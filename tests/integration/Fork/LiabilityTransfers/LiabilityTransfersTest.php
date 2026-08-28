<?php

/*
 * LiabilityTransfersTest.php
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

namespace Tests\integration\Fork\LiabilityTransfers;

use FireflyIII\Enums\AccountTypeEnum;
use FireflyIII\Enums\TransactionTypeEnum;
use FireflyIII\Fork\Config\LiabilityTransfers;
use FireflyIII\Models\Account;
use FireflyIII\Models\Rule;
use FireflyIII\Models\TransactionType;
use FireflyIII\TransactionRules\Engine\RuleEngineInterface;
use FireflyIII\User;
use FireflyIII\Validation\AccountValidator;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Tests\integration\TestCase;
use Tests\integration\Traits\CreatesTransactionGroups;

/**
 * FORK: asset → liability transfers (config fork.liability_transfers) and the
 * convert_liability_transfer rule action. Each test starts with the flag OFF (a fresh app per
 * test); enable() applies the runtime override the provider would apply at boot.
 *
 * @internal
 *
 * @coversNothing
 */
final class LiabilityTransfersTest extends TestCase
{
    use CreatesTransactionGroups;

    public function testApiStoresAssetToMortgageTransferOnlyWhenEnabled(): void
    {
        $user = $this->createAuthenticatedUser();
        $this->actingAs($user);
        $checking = $this->createAccount($user, AccountTypeEnum::ASSET, 'Checking');
        $mortgage = $this->createAccount($user, AccountTypeEnum::MORTGAGE, 'Mortgage');
        $payload  = [
            'transactions' => [[
                'type'           => 'transfer',
                'date'           => '2026-07-01T12:00:00+00:00',
                'amount'         => '5616.10',
                'description'    => 'MORTGAGE PAYMENT',
                'currency_code'  => 'EUR',
                'source_id'      => $checking->id,
                'destination_id' => $mortgage->id
            ]]
        ];

        $this->postJson(route('api.v1.transactions.store'), $payload)->assertUnprocessable();

        $this->enable();
        $response = $this->postJson(route('api.v1.transactions.store'), $payload);
        $response->assertSuccessful();
        $tx = $response->json('data.attributes.transactions.0');
        self::assertSame('transfer', $tx['type']);
        self::assertSame('Mortgage', $tx['destination_type']);

        // the liability's balance moves by the payment, exactly as a withdrawal would move it
        $balance = $this->getJson(route('api.v1.accounts.show', [$mortgage->id]))->json('data.attributes.current_balance');
        self::assertSame(0, bccomp('5616.10', (string) $balance, 2));
    }

    public function testApplyIsIdempotent(): void
    {
        $this->enable();
        LiabilityTransfers::apply();
        $list = config('firefly.source_dests.Transfer.Asset account');
        self::assertSame(count($list), count(array_unique($list)));
        self::assertSame('Transfer', config('firefly.account_to_transaction.Asset account.Mortgage'));
        self::assertSame('Deposit', config('firefly.account_to_transaction.Loan.Asset account'));
        self::assertSame('Withdrawal', config('firefly.account_to_transaction.Asset account.Expense account'));
    }

    public function testCorrectionCommandRevertsWhenDisabledAndConvertsWhenEnabled(): void
    {
        $user     = $this->createAuthenticatedUser();
        $loan     = $this->createAccount($user, AccountTypeEnum::LOAN, 'Car Loan');
        $group    = $this->createWithdrawal($user, ['description' => 'LOAN PMTS', 'destination_id' => $loan->id, 'amount' => '400.00']);
        $journal  = $group->transactionJournals()->first();
        $transfer = TransactionType::query()->where('type', TransactionTypeEnum::TRANSFER->value)->firstOrFail();

        // flag off: a hand-made asset → loan transfer is "wrong" and gets corrected back
        DB::table('transaction_journals')->where('id', $journal->id)->update(['transaction_type_id' => $transfer->id]);
        $this->artisan('correction:transaction-types')->assertExitCode(0);
        self::assertSame(TransactionTypeEnum::WITHDRAWAL->value, $journal->fresh()->transactionType->type);

        // flag on: the same command is the migration — the withdrawal becomes a transfer and stays one
        $this->enable();
        $this->artisan('correction:transaction-types')->expectsOutputToContain('Corrected transaction type of 1')->assertExitCode(0);
        self::assertSame(TransactionTypeEnum::TRANSFER->value, $journal->fresh()->transactionType->type);
        $this
            ->artisan('correction:transaction-types')
            ->expectsOutputToContain('All transaction journals are of the correct transaction type')
            ->assertExitCode(0);
        self::assertSame(TransactionTypeEnum::TRANSFER->value, $journal->fresh()->transactionType->type);
    }

    public function testRuleActionConvertsWithdrawalToLiabilityTransfer(): void
    {
        $this->enable();
        $user  = $this->createAuthenticatedUser();
        $loan  = $this->createAccount($user, AccountTypeEnum::LOAN, 'Car Loan');
        $group = $this->createWithdrawal($user, ['description' => 'LOAN PMTS', 'destination_name' => 'Loan Servicer Inc', 'amount' => '400.00']);
        $rule  = $this->createRule($user, ['description_is' => 'LOAN PMTS'], [['convert_liability_transfer', 'Car Loan']]);

        $this->fire($user, $rule);

        $journal = $group->transactionJournals()->first()->fresh(['transactionType']);
        self::assertSame(TransactionTypeEnum::TRANSFER->value, $journal->transactionType->type);
        self::assertSame($loan->id, (int) $journal->transactions()->where('amount', '>', 0)->first()->account_id);
        self::assertSame('Checking', $journal->transactions()->where('amount', '<', 0)->first()->account->name);
        self::assertSame(0, bccomp('400.00', (string) $journal->transactions()->where('amount', '>', 0)->first()->amount, 2));
        // the correction command agrees with the result
        $this
            ->artisan('correction:transaction-types')
            ->expectsOutputToContain('All transaction journals are of the correct transaction type')
            ->assertExitCode(0);
    }

    public function testRuleActionDoesNothingWhenDisabledOrTargetIsNotALiability(): void
    {
        $user = $this->createAuthenticatedUser();
        $this->createAccount($user, AccountTypeEnum::LOAN, 'Car Loan');
        $this->createAccount($user, AccountTypeEnum::ASSET, 'Savings');

        // disabled
        $a = $this->createWithdrawal($user, ['description' => 'LOAN PMTS']);
        $this->fire($user, $this->createRule($user, ['description_is' => 'LOAN PMTS'], [['convert_liability_transfer', 'Car Loan']], title: 'r1'));
        self::assertSame(TransactionTypeEnum::WITHDRAWAL->value, $a->transactionJournals()->first()->fresh()->transactionType->type);

        $this->enable();
        // an asset account of that name is not a liability
        $b = $this->createWithdrawal($user, ['description' => 'TO SAVINGS']);
        $this->fire($user, $this->createRule($user, ['description_is' => 'TO SAVINGS'], [['convert_liability_transfer', 'Savings']], title: 'r2'));
        self::assertSame(TransactionTypeEnum::WITHDRAWAL->value, $b->transactionJournals()->first()->fresh()->transactionType->type);
        // split groups are left alone
        $c = $this->createTransactionGroup(
            $user,
            [['description' => 'SPLIT PMT', 'amount' => '100.00'], ['description' => 'SPLIT PMT', 'amount' => '50.00']],
            'split'
        );
        $this->fire($user, $this->createRule($user, ['description_is' => 'SPLIT PMT'], [['convert_liability_transfer', 'Car Loan']], title: 'r3'));
        foreach ($c->transactionJournals()->get() as $j) {
            self::assertSame(TransactionTypeEnum::WITHDRAWAL->value, $j->fresh()->transactionType->type);
        }
    }

    public function testRuleWithForkActionIsAcceptedByTheApi(): void
    {
        $user = $this->createAuthenticatedUser();
        $this->actingAs($user);
        $this->createAccount($user, AccountTypeEnum::LOAN, 'Car Loan');
        $group = \FireflyIII\Models\RuleGroup::create([
            'user_id'         => $user->id,
            'user_group_id'   => $user->user_group_id,
            'title'           => 'Loans',
            'order'           => 1,
            'active'          => true,
            'stop_processing' => false
        ]);

        $this->postJson(route('api.v1.rules.store'), [
            'title'         => 'Car loan payments',
            'rule_group_id' => $group->id,
            'trigger'       => 'store-journal',
            'triggers'      => [['type' => 'description_contains', 'value' => 'LOAN PMTS']],
            'actions'       => [['type' => 'convert_liability_transfer', 'value' => 'Car Loan']]
        ])->assertSuccessful();

        self::assertSame(1, Rule::query()->where('title', 'Car loan payments')->count());
    }

    public function testValidatorAcceptsAssetToLiabilityTransfersWhenEnabled(): void
    {
        $this->enable();
        $user     = $this->createAuthenticatedUser();
        $checking = $this->createAccount($user, AccountTypeEnum::ASSET, 'Checking');
        foreach ([AccountTypeEnum::MORTGAGE, AccountTypeEnum::LOAN, AccountTypeEnum::DEBT] as $type) {
            self::assertTrue($this->validateTransfer($user, $checking, $this->createAccount($user, $type, 'Liability ' . $type->value)), $type->value);
        }
        // the reverse direction stays a deposit (loan pay-out), not a transfer
        self::assertFalse($this->validateTransfer($user, $this->createAccount($user, AccountTypeEnum::LOAN, 'Car Loan'), $checking));
        // and asset → expense is still not a transfer
        self::assertFalse($this->validateTransfer($user, $checking, $this->createAccount($user, AccountTypeEnum::EXPENSE, 'Shop')));
    }

    public function testValidatorRejectsAssetToMortgageTransferByDefault(): void
    {
        $user = $this->createAuthenticatedUser();
        self::assertFalse($this->validateTransfer(
            $user,
            $this->createAccount($user, AccountTypeEnum::ASSET, 'Checking'),
            $this->createAccount($user, AccountTypeEnum::MORTGAGE, 'Mortgage')
        ));
    }

    private function enable(): void
    {
        config(['fork.liability_transfers' => true]);
        LiabilityTransfers::apply();
    }

    private function fire(User $user, Rule $rule): void
    {
        /** @var RuleEngineInterface $engine */
        $engine = app(RuleEngineInterface::class);
        $engine->setUser($user);
        $engine->setRules(new Collection([$rule]));
        $engine->fire();
    }

    private function validateTransfer(User $user, Account $source, Account $destination): bool
    {
        $validator = new AccountValidator();
        $validator->setUser($user);
        $validator->setUserGroup($user->userGroup);
        $validator->setTransactionType(TransactionTypeEnum::TRANSFER->value);

        return $validator->validateSource(['id' => $source->id]) && $validator->validateDestination(['id' => $destination->id]);
    }
}
