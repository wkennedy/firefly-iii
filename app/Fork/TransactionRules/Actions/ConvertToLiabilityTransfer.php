<?php

/*
 * ConvertToLiabilityTransfer.php
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

namespace FireflyIII\Fork\TransactionRules\Actions;

use FireflyIII\Enums\AccountTypeEnum;
use FireflyIII\Enums\TransactionTypeEnum;
use FireflyIII\Events\Model\Rule\RuleActionFailedOnArray;
use FireflyIII\Events\Model\TransactionGroup\TransactionGroupRequestsAuditLogEntry;
use FireflyIII\Fork\Config\LiabilityTransfers;
use FireflyIII\Models\Account;
use FireflyIII\Models\RuleAction;
use FireflyIII\Models\Transaction;
use FireflyIII\Models\TransactionJournal;
use FireflyIII\Models\TransactionType;
use FireflyIII\Repositories\Account\AccountRepositoryInterface;
use FireflyIII\TransactionRules\Actions\ActionInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * FORK rule action `convert_liability_transfer` (registered by ForkServiceProvider): turns a
 * withdrawal from an asset account into a transfer to the liability named in the action value
 * — a loan or mortgage payment. Upstream's convert_transfer only looks for an opposing account of
 * the source's own type, so it can never reach a liability. Needs config fork.liability_transfers.
 */
final class ConvertToLiabilityTransfer implements ActionInterface
{
    public function __construct(
        private readonly RuleAction $action
    ) {}

    public function actOnArray(array $journal): bool
    {
        if (!LiabilityTransfers::enabled()) {
            $this->fail($journal, 'FORK: liability transfers are disabled (FORK_LIABILITY_TRANSFERS).');

            return false;
        }
        $name = $this->action->getValue($journal);

        /** @var null|TransactionJournal $object */
        $object = TransactionJournal::query()->where('user_id', $journal['user_id'])->find($journal['transaction_journal_id']);
        if (null === $object) {
            $this->fail($journal, 'Journal not found.');

            return false;
        }
        if (TransactionJournal::query()->where('transaction_group_id', $journal['transaction_group_id'])->count() > 1) {
            $this->fail($journal, 'Split transactions are not converted.');

            return false;
        }
        if (TransactionTypeEnum::WITHDRAWAL->value !== $object->transactionType->type) {
            $this->fail($journal, sprintf('Only withdrawals are converted, this is a %s.', $object->transactionType->type));

            return false;
        }

        /** @var null|Transaction $source */
        $source = $object->transactions()->where('amount', '<', 0)->first();

        /** @var null|Transaction $destination */
        $destination = $object->transactions()->where('amount', '>', 0)->first();
        if (null === $source || null === $destination || AccountTypeEnum::ASSET->value !== $source->account->accountType->type) {
            $this->fail($journal, 'Source must be an asset account.');

            return false;
        }

        /** @var AccountRepositoryInterface $repository */
        $repository = app(AccountRepositoryInterface::class);
        $repository->setUser($object->user);
        $liability = $repository->findByName($name, LiabilityTransfers::LIABILITY_TYPES);
        if (!$liability instanceof Account) {
            $this->fail($journal, sprintf('No liability account named "%s".', $name));

            return false;
        }
        $sourceCurrency    = $repository->getAccountCurrency($source->account);
        $liabilityCurrency = $repository->getAccountCurrency($liability);
        if (null !== $sourceCurrency && null !== $liabilityCurrency && $sourceCurrency->id !== $liabilityCurrency->id) {
            $this->fail($journal, sprintf('Currency mismatch: source is %s, "%s" is %s.', $sourceCurrency->code, $liability->name, $liabilityCurrency->code));

            return false;
        }

        $transferType = TransactionType::query()->where('type', TransactionTypeEnum::TRANSFER->value)->firstOrFail();
        DB::transaction(static function () use ($object, $destination, $liability, $transferType): void {
            DB::table('transactions')->where('id', $destination->id)->update(['account_id' => $liability->id]);
            DB::table('transaction_journals')->where('id', $object->id)->update(['transaction_type_id' => $transferType->id, 'bill_id' => null]);
        });
        event(
            new TransactionGroupRequestsAuditLogEntry(
                $this->action->rule,
                $object,
                'update_transaction_type',
                TransactionTypeEnum::WITHDRAWAL->value,
                TransactionTypeEnum::TRANSFER->value
            )
        );
        Log::debug(sprintf('FORK: journal #%d converted to a transfer to liability "%s" (#%d).', $object->id, $liability->name, $liability->id));

        return true;
    }

    /**
     * @param array<string, mixed> $journal
     */
    private function fail(array $journal, string $message): void
    {
        Log::warning(sprintf('FORK convert_liability_transfer on journal #%d: %s', (int) ($journal['transaction_journal_id'] ?? 0), $message));
        event(new RuleActionFailedOnArray($this->action, $journal, $message));
    }
}
