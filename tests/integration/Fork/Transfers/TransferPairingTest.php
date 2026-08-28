<?php

/*
 * TransferPairingTest.php
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

namespace Tests\integration\Fork\Transfers;

use Carbon\Carbon;
use FireflyIII\Enums\AccountTypeEnum;
use FireflyIII\Enums\TransactionTypeEnum;
use FireflyIII\Fork\Config\LiabilityTransfers;
use FireflyIII\Fork\Models\ForkTransferPair;
use FireflyIII\Fork\Transfers\PairingResult;
use FireflyIII\Fork\Transfers\PairingSettings;
use FireflyIII\Fork\Transfers\TransferPairer;
use FireflyIII\Models\Account;
use FireflyIII\Models\TransactionGroup;
use FireflyIII\Models\TransactionJournal;
use FireflyIII\User;
use Override;
use Tests\integration\TestCase;
use Tests\integration\Traits\CreatesTransactionGroups;

/**
 * FORK: transfer pairing (config fork.transfer_pairing + per-user settings).
 *
 * @internal
 *
 * @coversNothing
 */
final class TransferPairingTest extends TestCase
{
    use CreatesTransactionGroups;

    private const array PATTERNS = ['AUTOPAY', 'PAYMENT', 'LOAN PMTS'];

    public function testAccountsWhitelistRestrictsTargets(): void
    {
        [$user, $card] = $this->prepare();
        $other = $this->createAccount($user, AccountTypeEnum::ASSET, 'Other Card');
        app(PairingSettings::class)->save($user, ['accounts' => [$other->id]]);
        $funding = $this->funding($user, '250.00', '2026-07-05', 'CARD AUTOPAY');
        $this->mirror($user, $card, '250.00', '2026-07-06', 'PAYMENT THANK YOU');

        self::assertSame(PairingResult::NO_CANDIDATE, $this->pairer()->pairJournal($this->journal($funding))->status);
    }

    public function testApiSettingsRunAndIndex(): void
    {
        [$user, $card] = $this->prepare();
        $this->actingAs($user);

        $this
            ->getJson(route('api.v1.fork.transfer-pairs.settings'))
            ->assertOk()
            ->assertJsonPath('data.window_days', 3)
            ->assertJsonPath('data.patterns', self::PATTERNS);
        $this
            ->putJson(route('api.v1.fork.transfer-pairs.settings.update'), ['window_days' => 5, 'patterns' => ['AUTOPAY', 'PAYMENT']])
            ->assertOk()
            ->assertJsonPath('data.window_days', 5);
        $this->putJson(route('api.v1.fork.transfer-pairs.settings.update'), ['patterns' => ['(']])->assertUnprocessable();
        $this->putJson(route('api.v1.fork.transfer-pairs.settings.update'), ['accounts' => [999_999]])->assertUnprocessable();

        $funding = $this->funding($user, '250.00', '2026-07-05', 'CARD AUTOPAY');
        $this->mirror($user, $card, '250.00', '2026-07-06', 'PAYMENT THANK YOU');
        $this->postJson(route('api.v1.fork.transfer-pairs.run'), ['since' => '2026-07-01', 'dry_run' => true])->assertOk()->assertJsonPath('data.dry_run', 1);
        $this->postJson(route('api.v1.fork.transfer-pairs.run'), ['since' => '2026-07-01'])->assertOk()->assertJsonPath('data.paired', 1);

        $this
            ->getJson(route('api.v1.fork.transfer-pairs.index'))
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.funding_journal_id', (int) $this->journal($funding)->id);
        $this->getJson(route('api.v1.fork.transfer-pairs.index'))->assertJsonPath('data.0.mirror_account', 'Credit Card');
    }

    public function testCommandSweepsAndReports(): void
    {
        [$user, $card] = $this->prepare();
        $this->funding($user, '250.00', '2026-07-05', 'CARD AUTOPAY');
        $this->mirror($user, $card, '250.00', '2026-07-06', 'PAYMENT THANK YOU');

        $this
            ->artisan('firefly-iii:fork:pair-transfers', ['--since' => '2026-07-01', '--dry-run' => true])
            ->expectsOutputToContain('would pair 1')
            ->assertExitCode(0);
        self::assertSame(0, ForkTransferPair::query()->count());
        $this->artisan('firefly-iii:fork:pair-transfers', ['--since' => '2026-07-01'])->expectsOutputToContain('paired 1')->assertExitCode(0);
        self::assertSame(1, ForkTransferPair::query()->count());
    }

    public function testDryRunAndDisabledChangeNothing(): void
    {
        [$user, $card] = $this->prepare(['dry_run' => true]);
        $funding = $this->funding($user, '250.00', '2026-07-05', 'CARD AUTOPAY');
        $this->mirror($user, $card, '250.00', '2026-07-06', 'PAYMENT THANK YOU');

        $result = $this->pairer()->pairJournal($this->journal($funding));
        self::assertSame(PairingResult::DRY_RUN, $result->status);
        self::assertStringContainsString('would convert', $result->message);
        self::assertSame(0, ForkTransferPair::query()->count());
        self::assertSame(TransactionTypeEnum::WITHDRAWAL->value, $this->journal($funding)->transactionType->type);

        config(['fork.transfer_pairing' => false]);
        self::assertSame(PairingResult::SKIPPED, $this->pairer()->pairJournal($this->journal($funding), false)->status);
        config(['fork.transfer_pairing' => true]);
        app(PairingSettings::class)->save($user, ['enabled' => false]);
        self::assertSame(PairingResult::SKIPPED, $this->pairer()->pairJournal($this->journal($funding), false)->status);
    }

    public function testExactPairBecomesOneTransferAndMirrorIsDeleted(): void
    {
        [$user, $card] = $this->prepare();
        $funding = $this->funding($user, '250.00', '2026-07-05', 'CARD AUTOPAY');
        $mirror  = $this->mirror($user, $card, '250.00', '2026-07-06', 'PAYMENT THANK YOU');

        $result = $this->pairer()->pairJournal($this->journal($funding));

        self::assertSame(PairingResult::PAIRED, $result->status, $result->message);
        $journal = $this->journal($funding);
        self::assertSame(TransactionTypeEnum::TRANSFER->value, $journal->transactionType->type);
        self::assertSame($card->id, (int) $journal->transactions()->where('amount', '>', 0)->first()->account_id);
        self::assertSame('Checking', $journal->transactions()->where('amount', '<', 0)->first()->account->name);
        self::assertNotNull(TransactionGroup::query()->withTrashed()->find($mirror->id)->deleted_at, 'mirror group must be soft-deleted');

        $pair = ForkTransferPair::query()->firstOrFail();
        self::assertSame((int) $journal->id, (int) $pair->funding_journal_id);
        self::assertSame('PAYMENT THANK YOU', $pair->mirror_description);
        self::assertSame('Credit Card', $pair->mirror_account);
        self::assertSame(0, bccomp('250.00', $pair->amount, 2));
        self::assertSame('2026-07-05', $pair->matched_on->format('Y-m-d'));
    }

    public function testLiabilityTargetNeedsPhaseTwoFlag(): void
    {
        [$user] = $this->prepare();
        $loan    = $this->createAccount($user, AccountTypeEnum::LOAN, 'Car Loan');
        $funding = $this->funding($user, '400.00', '2026-07-05', 'LOAN PMTS');
        $this->mirror($user, $loan, '400.00', '2026-07-06', 'PAYMENT RECEIVED');

        $result = $this->pairer()->pairJournal($this->journal($funding));
        self::assertSame(PairingResult::SKIPPED, $result->status);
        self::assertStringContainsString('FORK_LIABILITY_TRANSFERS', $result->message);

        config(['fork.liability_transfers' => true]);
        LiabilityTransfers::apply();
        self::assertSame(PairingResult::PAIRED, $this->pairer()->pairJournal($this->journal($funding))->status);
        $journal = $this->journal($funding);
        self::assertSame(TransactionTypeEnum::TRANSFER->value, $journal->transactionType->type);
        self::assertSame($loan->id, (int) $journal->transactions()->where('amount', '>', 0)->first()->account_id);
        $this
            ->artisan('correction:transaction-types')
            ->expectsOutputToContain('All transaction journals are of the correct transaction type')
            ->assertExitCode(0);
    }

    public function testListenerPairsOnApiStoreInEitherOrder(): void
    {
        [$user, $card] = $this->prepare();
        $this->actingAs($user);
        $checking = $this->assetAccount($user, 'Checking');

        $mirrorId = $this->store([
            'type'           => 'deposit',
            'amount'         => '250.00',
            'date'           => '2026-07-06T12:00:00+00:00',
            'description'    => 'PAYMENT THANK YOU',
            'source_name'    => 'Card Issuer',
            'destination_id' => $card->id
        ]);
        self::assertSame(0, ForkTransferPair::query()->count());
        $fundingId = $this->store([
            'type'             => 'withdrawal',
            'amount'           => '250.00',
            'date'             => '2026-07-05T12:00:00+00:00',
            'description'      => 'CARD AUTOPAY',
            'source_id'        => $checking->id,
            'destination_name' => 'Card Issuer Payment'
        ]);

        self::assertSame(1, ForkTransferPair::query()->count());
        self::assertSame(
            TransactionTypeEnum::TRANSFER->value,
            TransactionJournal::query()->where('transaction_group_id', $fundingId)->firstOrFail()->transactionType->type
        );
        self::assertNotNull(TransactionGroup::query()->withTrashed()->find($mirrorId)->deleted_at);
    }

    public function testNoPairWhenAmountDateAccountOrPatternDoNotMatch(): void
    {
        [$user, $card] = $this->prepare();
        $checking = $this->assetAccount($user, 'Checking');
        $funding  = $this->funding($user, '250.00', '2026-07-05', 'CARD AUTOPAY');
        $this->mirror($user, $card, '250.01', '2026-07-06', 'PAYMENT THANK YOU'); // amount
        $this->mirror($user, $card, '250.00', '2026-07-20', 'PAYMENT THANK YOU'); // outside ±3 days
        $this->mirror($user, $checking, '250.00', '2026-07-06', 'PAYMENT THANK YOU'); // same account as the source
        $this->mirror($user, $card, '250.00', '2026-07-06', 'INTEREST REFUND'); // pattern

        $result = $this->pairer()->pairJournal($this->journal($funding));

        self::assertSame(PairingResult::NO_CANDIDATE, $result->status, $result->message);
        self::assertSame(TransactionTypeEnum::WITHDRAWAL->value, $this->journal($funding)->transactionType->type);
        self::assertSame(0, ForkTransferPair::query()->count());
        self::assertSame(0, TransactionGroup::query()->onlyTrashed()->count());
    }

    public function testOtherUsersJournalsAreNeverCandidates(): void
    {
        [$user, $card] = $this->prepare();
        $other = $this->createUser('other@email.com');
        app(PairingSettings::class)->save($other, ['enabled' => true, 'patterns' => self::PATTERNS]);
        $otherCard = $this->createAccount($other, AccountTypeEnum::ASSET, 'Credit Card');
        $funding   = $this->funding($user, '250.00', '2026-07-05', 'CARD AUTOPAY');
        $this->mirror($other, $otherCard, '250.00', '2026-07-06', 'PAYMENT THANK YOU');

        self::assertSame(PairingResult::NO_CANDIDATE, $this->pairer()->pairJournal($this->journal($funding))->status);
        self::assertSame($card->user_group_id, $user->user_group_id);
    }

    public function testPairingIsIdempotentAndWorksFromTheDepositSide(): void
    {
        [$user, $card] = $this->prepare();
        $funding = $this->funding($user, '250.00', '2026-07-05', 'CARD AUTOPAY');
        $mirror  = $this->mirror($user, $card, '250.00', '2026-07-06', 'PAYMENT THANK YOU');

        self::assertSame(PairingResult::PAIRED, $this->pairer()->pairJournal($this->journal($mirror))->status);
        self::assertSame(PairingResult::SKIPPED, $this->pairer()->pairJournal($this->journal($funding))->status);
        self::assertSame(1, ForkTransferPair::query()->count());
        self::assertSame(TransactionTypeEnum::TRANSFER->value, $this->journal($funding)->transactionType->type);
    }

    public function testSweepDoesNotTagInsideTheWindowAndUntagsWhenPairedLater(): void
    {
        [$user, $card] = $this->prepare();
        $funding = $this->funding($user, '250.00', '2026-07-05', 'CARD AUTOPAY');

        Carbon::setTestNow(Carbon::parse('2026-07-06', 'UTC'));
        $summary = $this->pairer()->sweep($user, Carbon::parse('2026-07-01', 'UTC'));
        self::assertSame(1, $summary['no_candidate']);
        self::assertSame(0, $summary['tagged'], 'mirror may still arrive');

        Carbon::setTestNow(Carbon::parse('2026-07-20', 'UTC'));
        $this->pairer()->sweep($user, Carbon::parse('2026-07-01', 'UTC'));
        self::assertSame([TransferPairer::TAG_UNPAIRED], $this->journal($funding)->tags()->pluck('tag')->all());

        $this->mirror($user, $card, '250.00', '2026-07-07', 'PAYMENT THANK YOU');
        self::assertSame(PairingResult::PAIRED, $this->pairer()->pairJournal($this->journal($funding))->status);
        self::assertSame([], $this->journal($funding)->tags()->pluck('tag')->all());
    }

    public function testTwoCandidatesAreAmbiguousAndSweepTagsAfterTheWindow(): void
    {
        [$user, $card] = $this->prepare();
        $other   = $this->createAccount($user, AccountTypeEnum::ASSET, 'Other Card');
        $funding = $this->funding($user, '139.58', '2026-07-05', 'AMEX EPAYMENT ACH PMT');
        $this->mirror($user, $card, '139.58', '2026-07-05', 'PAYMENT RECEIVED');
        $this->mirror($user, $other, '139.58', '2026-07-06', 'PAYMENT RECEIVED');

        self::assertSame(PairingResult::AMBIGUOUS, $this->pairer()->pairJournal($this->journal($funding))->status);

        Carbon::setTestNow(Carbon::parse('2026-07-20', 'UTC'));
        $summary = $this->pairer()->sweep($user, Carbon::parse('2026-07-01', 'UTC'));
        self::assertSame(1, $summary['examined']);
        self::assertSame(1, $summary['ambiguous']);
        self::assertSame(1, $summary['tagged']);
        self::assertSame([TransferPairer::TAG_UNPAIRED], $this->journal($funding)->tags()->pluck('tag')->all());
        self::assertSame(TransactionTypeEnum::WITHDRAWAL->value, $this->journal($funding)->transactionType->type);
    }

    #[Override]
    protected function setUp(): void
    {
        parent::setUp();
        config(['fork.transfer_pairing' => true]);
    }

    #[Override]
    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function funding(User $user, string $amount, string $date, string $description): TransactionGroup
    {
        return $this->createWithdrawal($user, [
            'amount'           => $amount,
            'date'             => Carbon::parse($date . ' 12:00:00', 'UTC'),
            'description'      => $description,
            'destination_name' => 'Card Issuer Payment'
        ]);
    }

    private function journal(TransactionGroup $group): TransactionJournal
    {
        return TransactionJournal::query()
            ->where('transaction_group_id', $group->id)
            ->with(['transactions.account.accountType', 'transactionType', 'transactionGroup'])
            ->firstOrFail();
    }

    private function mirror(User $user, Account $target, string $amount, string $date, string $description): TransactionGroup
    {
        return $this->createDeposit($user, [
            'amount'         => $amount,
            'date'           => Carbon::parse($date . ' 12:00:00', 'UTC'),
            'description'    => $description,
            'source_name'    => 'Card Issuer',
            'destination_id' => $target->id
        ]);
    }

    private function pairer(): TransferPairer
    {
        return app(TransferPairer::class);
    }

    /**
     * @param array<string, mixed> $overrides
     *
     * @return array{0: User, 1: Account}
     */
    private function prepare(array $overrides = []): array
    {
        $user = $this->createAuthenticatedUser();
        app(PairingSettings::class)->save($user, ['enabled' => true, 'patterns' => self::PATTERNS, 'window_days' => 3] + $overrides);

        return [$user, $this->createAccount($user, AccountTypeEnum::ASSET, 'Credit Card')];
    }

    /**
     * @param array<string, mixed> $transaction
     */
    private function store(array $transaction): int
    {
        $response = $this->postJson(route('api.v1.transactions.store'), ['transactions' => [$transaction + ['currency_code' => 'EUR']]]);
        $response->assertSuccessful();

        return (int) $response->json('data.id');
    }
}
