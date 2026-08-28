<?php

/*
 * TransferPairer.php
 * Copyright (c) 2026 the fork authors.
 *
 * This file is part of a fork of Firefly III (https://github.com/firefly-iii).
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Affero General Public License as
 * published by the Free Software Foundation, either version 3 of the
 * License, or (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU Affero General Public License for more details.
 *
 * You should have received a copy of the GNU Affero General Public License
 * along with this program.  If not, see https://www.gnu.org/licenses/.
 */

declare(strict_types=1);

namespace FireflyIII\Fork\Transfers;

use Carbon\Carbon;
use FireflyIII\Enums\AccountTypeEnum;
use FireflyIII\Enums\TransactionTypeEnum;
use FireflyIII\Factory\TagFactory;
use FireflyIII\Fork\Config\LiabilityTransfers;
use FireflyIII\Fork\Models\ForkTransferPair;
use FireflyIII\Models\Tag;
use FireflyIII\Models\Transaction;
use FireflyIII\Models\TransactionJournal;
use FireflyIII\Models\TransactionType;
use FireflyIII\Services\Internal\Destroy\TransactionGroupDestroyService;
use FireflyIII\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

use function Safe\preg_match;

/**
 * FORK: merges the two legs a bank feed reports for one card or loan payment.
 *
 *   funding leg  withdrawal  asset (checking) → expense ("CARD AUTOPAY")
 *   mirror leg   deposit     revenue ("PAYMENT THANK YOU") → asset card or liability
 *
 * Same amount, dates within the window, both descriptions matching the configured patterns, and
 * exactly ONE candidate → the funding leg becomes a transfer to the mirror's account and the
 * mirror group is deleted. Balances are unchanged by construction (checking −X, card +X before
 * and after). Zero or several candidates → nothing happens; the daily sweep tags such journals
 * `fork:unpaired` once their window has passed, for manual review.
 */
final class TransferPairer
{
    public const string TAG_UNPAIRED = 'fork:unpaired';
    public const string STRATEGY     = 'amount_date';

    public function __construct(
        private readonly PairingSettings $settings
    ) {}

    public static function enabled(): bool
    {
        return (bool) config('fork.transfer_pairing');
    }

    /**
     * Try to pair one journal (either leg). Idempotent.
     */
    public function pairJournal(TransactionJournal $journal, null|bool $dryRun = null): PairingResult
    {
        if (!self::enabled()) {
            return PairingResult::skipped('transfer pairing is disabled (FORK_TRANSFER_PAIRING)');
        }
        $settings = $this->settings->forUser($journal->user);
        if (!$settings['enabled']) {
            return PairingResult::skipped('transfer pairing is disabled for this user');
        }
        $dryRun ??= $settings['dry_run'];
        $type   = $journal->transactionType->type;

        if (TransactionTypeEnum::WITHDRAWAL->value === $type) {
            if (!$this->isFunding($journal, $settings)) {
                return PairingResult::skipped('not a pairable funding leg');
            }

            return $this->resolve($journal, $this->mirrorsFor($journal, $settings), $settings, $dryRun, fundingFirst: true);
        }
        if (TransactionTypeEnum::DEPOSIT->value === $type) {
            if (!$this->isMirror($journal, $settings)) {
                return PairingResult::skipped('not a pairable mirror leg');
            }

            return $this->resolve($journal, $this->fundingsFor($journal, $settings), $settings, $dryRun, fundingFirst: false);
        }

        return PairingResult::skipped(sprintf('%s journals are never paired', $type));
    }

    /**
     * Sweep a user's funding legs since a date: pair what can be paired, tag what is past its window.
     *
     * @return array{examined: int, paired: int, dry_run: int, no_candidate: int, ambiguous: int, tagged: int, skipped: int, results: list<array{journal_id: int, status: string, message: string}>}
     */
    public function sweep(User $user, Carbon $since, null|bool $dryRun = null): array
    {
        $summary  = ['examined' => 0, 'paired' => 0, 'dry_run' => 0, 'no_candidate' => 0, 'ambiguous' => 0, 'tagged' => 0, 'skipped' => 0, 'results' => []];
        $settings = $this->settings->forUser($user);
        if (!self::enabled() || !$settings['enabled']) {
            return $summary;
        }
        $dryRun ??= $settings['dry_run'];
        $cutoff = Carbon::now(config('app.timezone'))->subDays($settings['window_days'])->startOfDay();

        $journals = TransactionJournal::query()
            ->where('user_group_id', $user->user_group_id)
            ->where('date', '>=', $since->startOfDay())
            ->whereHas('transactionType', static fn($q) => $q->where('type', TransactionTypeEnum::WITHDRAWAL->value))
            ->orderBy('date')
            ->orderBy('id')
            ->get();
        foreach ($journals as $journal) {
            if (!$this->isFunding($journal, $settings)) {
                continue;
            }
            ++$summary['examined'];
            $result = $this->pairJournal($journal, $dryRun);
            ++$summary[$result->status];
            $summary['results'][] = ['journal_id' => (int) $journal->id, 'status' => $result->status, 'message' => $result->message];
            if (in_array($result->status, [PairingResult::NO_CANDIDATE, PairingResult::AMBIGUOUS], true) && $journal->date->lessThan($cutoff) && !$dryRun) {
                $this->tag($journal);
                ++$summary['tagged'];
            }
        }

        return $summary;
    }

    private function amount(TransactionJournal $journal): string
    {
        return (string) $this->destinationTransaction($journal)->amount;
    }

    private function destinationTransaction(TransactionJournal $journal): Transaction
    {
        /** @var null|Transaction $loaded */
        $loaded = $journal->transactions->first(static fn(Transaction $t): bool => 1 === bccomp((string) $t->amount, '0', 12));

        return $loaded ?? Transaction::query()->where('transaction_journal_id', $journal->id)->where('amount', '>', 0)->firstOrFail();
    }

    /**
     * @param  array{enabled: bool, window_days: int, patterns: list<string>, accounts: list<int>, dry_run: bool}  $settings
     */
    private function fundingsFor(TransactionJournal $mirror, array $settings): Collection
    {
        $amount = $this->amount($mirror);
        $target = $this->destinationTransaction($mirror)->account_id;

        return $this
            ->window($mirror, $settings, TransactionTypeEnum::WITHDRAWAL->value)
            ->filter(
                fn(TransactionJournal $j): bool => (
                    $this->isFunding($j, $settings)
                    && 0 === bccomp($amount, $this->amount($j), 4)
                    && (int) $this->sourceTransaction($j)->account_id !== (int) $target
                )
            )
            ->values();
    }

    /**
     * @param  array{enabled: bool, window_days: int, patterns: list<string>, accounts: list<int>, dry_run: bool}  $settings
     */
    private function isFunding(TransactionJournal $journal, array $settings): bool
    {
        if (!$this->matches($journal, $settings) || !$this->isSingle($journal) || $this->isPaired($journal)) {
            return false;
        }
        $source      = $this->sourceTransaction($journal)->account->accountType->type;
        $destination = $this->destinationTransaction($journal)->account->accountType->type;

        return AccountTypeEnum::ASSET->value === $source && AccountTypeEnum::EXPENSE->value === $destination;
    }

    /**
     * @param  array{enabled: bool, window_days: int, patterns: list<string>, accounts: list<int>, dry_run: bool}  $settings
     */
    private function isMirror(TransactionJournal $journal, array $settings): bool
    {
        if (!$this->matches($journal, $settings) || !$this->isSingle($journal) || $this->isPaired($journal)) {
            return false;
        }
        $source      = $this->sourceTransaction($journal)->account->accountType->type;
        $destination = $this->destinationTransaction($journal)->account;
        $allowed     = array_merge([AccountTypeEnum::ASSET->value], LiabilityTransfers::LIABILITY_TYPES);
        if (AccountTypeEnum::REVENUE->value !== $source || !in_array($destination->accountType->type, $allowed, true)) {
            return false;
        }

        return [] === $settings['accounts'] || in_array((int) $destination->id, $settings['accounts'], true);
    }

    private function isPaired(TransactionJournal $journal): bool
    {
        return ForkTransferPair::query()->where('funding_journal_id', $journal->id)->orWhere('mirror_journal_id', $journal->id)->exists();
    }

    private function isSingle(TransactionJournal $journal): bool
    {
        return 1 === TransactionJournal::query()->where('transaction_group_id', $journal->transaction_group_id)->count();
    }

    /**
     * @param  array{enabled: bool, window_days: int, patterns: list<string>, accounts: list<int>, dry_run: bool}  $settings
     */
    private function matches(TransactionJournal $journal, array $settings): bool
    {
        if ([] === $settings['patterns']) {
            return true;
        }
        foreach ($settings['patterns'] as $pattern) {
            if (1 === preg_match(PairingSettings::regex($pattern), (string) $journal->description)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  array{enabled: bool, window_days: int, patterns: list<string>, accounts: list<int>, dry_run: bool}  $settings
     */
    private function mirrorsFor(TransactionJournal $funding, array $settings): Collection
    {
        $amount = $this->amount($funding);
        $source = $this->sourceTransaction($funding)->account_id;

        return $this
            ->window($funding, $settings, TransactionTypeEnum::DEPOSIT->value)
            ->filter(
                fn(TransactionJournal $j): bool => (
                    $this->isMirror($j, $settings)
                    && 0 === bccomp($amount, $this->amount($j), 4)
                    && (int) $this->destinationTransaction($j)->account_id !== (int) $source
                )
            )
            ->values();
    }

    private function pair(TransactionJournal $funding, TransactionJournal $mirror): PairingResult
    {
        $mirrorDestination = $this->destinationTransaction($mirror);
        $target            = $mirrorDestination->account;
        $transfer          = TransactionType::query()->where('type', TransactionTypeEnum::TRANSFER->value)->firstOrFail();

        $pair = DB::transaction(function () use ($funding, $mirror, $target, $transfer): ForkTransferPair {
            DB::table('transactions')->where('transaction_journal_id', $funding->id)->where('amount', '>', 0)->update(['account_id' => $target->id]);
            DB::table('transaction_journals')->where('id', $funding->id)->update(['transaction_type_id' => $transfer->id, 'bill_id' => null]);

            $pair = ForkTransferPair::query()->create([
                'user_group_id'      => $funding->user_group_id,
                'funding_journal_id' => $funding->id,
                'mirror_journal_id'  => $mirror->id,
                'mirror_description' => (string) $mirror->description,
                'mirror_account'     => (string) $target->name,
                'amount'             => $this->amount($funding),
                'matched_on'         => $funding->date->format('Y-m-d'),
                'strategy'           => self::STRATEGY
            ]);

            /** @var TransactionGroupDestroyService $destroyer */
            $destroyer = app(TransactionGroupDestroyService::class);
            $destroyer->destroy($mirror->transactionGroup);
            $this->untag($funding);

            return $pair;
        });
        Log::info(sprintf(
            'FORK transfer pairing: journal #%d is now a transfer to "%s"; mirror journal #%d deleted (pair #%d).',
            $funding->id,
            $target->name,
            $mirror->id,
            $pair->id
        ));

        return new PairingResult(
            PairingResult::PAIRED,
            sprintf('converted #%d to a transfer to "%s", deleted #%d', $funding->id, $target->name, $mirror->id),
            $pair,
            (int) $mirror->id
        );
    }

    /**
     * @param  array{enabled: bool, window_days: int, patterns: list<string>, accounts: list<int>, dry_run: bool}  $settings
     */
    private function resolve(TransactionJournal $journal, Collection $candidates, array $settings, bool $dryRun, bool $fundingFirst): PairingResult
    {
        if (0 === $candidates->count()) {
            return new PairingResult(PairingResult::NO_CANDIDATE, 'no matching opposite leg within the window');
        }
        if ($candidates->count() > 1) {
            return new PairingResult(PairingResult::AMBIGUOUS, sprintf('%d matching legs: %s', $candidates->count(), $candidates->pluck('id')->implode(', ')));
        }

        /** @var TransactionJournal $other */
        $other   = $candidates->first();
        $funding = $fundingFirst ? $journal : $other;
        $mirror  = $fundingFirst ? $other : $journal;
        $target  = $this->destinationTransaction($mirror)->account;
        if (in_array($target->accountType->type, LiabilityTransfers::LIABILITY_TYPES, true) && !LiabilityTransfers::enabled()) {
            return PairingResult::skipped(sprintf('mirror lands on liability "%s" but FORK_LIABILITY_TRANSFERS is off', $target->name));
        }
        if ($dryRun) {
            return new PairingResult(
                PairingResult::DRY_RUN,
                sprintf('would convert #%d to a transfer to "%s" and delete #%d', $funding->id, $target->name, $mirror->id),
                null,
                (int) $mirror->id
            );
        }

        return $this->pair($funding, $mirror);
    }

    private function sourceTransaction(TransactionJournal $journal): Transaction
    {
        /** @var null|Transaction $loaded */
        $loaded = $journal->transactions->first(static fn(Transaction $t): bool => 1 === bccomp('0', (string) $t->amount, 12));

        return $loaded ?? Transaction::query()->where('transaction_journal_id', $journal->id)->where('amount', '<', 0)->firstOrFail();
    }

    private function tag(TransactionJournal $journal): void
    {
        /** @var TagFactory $factory */
        $factory = app(TagFactory::class);
        $factory->setUser($journal->user);
        $factory->setUserGroup($journal->userGroup);
        $tag = $factory->findOrCreate(self::TAG_UNPAIRED);
        if ($tag instanceof Tag) {
            $journal->tags()->syncWithoutDetaching([$tag->id]);
        }
    }

    private function untag(TransactionJournal $journal): void
    {
        $ids = Tag::query()->where('user_group_id', $journal->user_group_id)->where('tag', self::TAG_UNPAIRED)->pluck('id')->all();
        if ([] !== $ids) {
            $journal->tags()->detach($ids);
        }
    }

    /**
     * @param  array{enabled: bool, window_days: int, patterns: list<string>, accounts: list<int>, dry_run: bool}  $settings
     */
    private function window(TransactionJournal $journal, array $settings, string $type): Collection
    {
        return TransactionJournal::query()
            ->where('user_group_id', $journal->user_group_id)
            ->where('id', '!=', $journal->id)
            ->whereBetween('date', [
                $journal->date->clone()->subDays($settings['window_days'])->startOfDay(),
                $journal->date->clone()->addDays($settings['window_days'])->endOfDay()
            ])
            ->whereHas('transactionType', static fn($q) => $q->where('type', $type))
            ->with(['transactions.account.accountType', 'transactionGroup'])
            ->get();
    }
}
