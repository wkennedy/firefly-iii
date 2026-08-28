<?php

/*
 * ConvertToTransferActionTest.php
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

namespace Tests\integration\Fork\Rules;

use FireflyIII\Enums\AccountTypeEnum;
use FireflyIII\Enums\TransactionTypeEnum;
use FireflyIII\Models\Rule;
use FireflyIII\Models\TransactionJournal;
use FireflyIII\TransactionRules\Engine\RuleEngineInterface;
use FireflyIII\User;
use Illuminate\Support\Collection;
use Tests\integration\TestCase;
use Tests\integration\Traits\CreatesTransactionGroups;

/**
 * FORK: the "Transfer matching" rules (convert_transfer → card account) silently do nothing
 * in several situations. Pin each one so a future upstream change is noticed.
 *
 * @internal
 *
 * @coversNothing
 */
final class ConvertToTransferActionTest extends TestCase
{
    use CreatesTransactionGroups;

    public function testConvertsWithdrawalToTransferWhenNamedAssetAccountExists(): void
    {
        $user  = $this->createAuthenticatedUser();
        $card  = $this->createAccount($user, AccountTypeEnum::ASSET, 'Credit Card');
        $group = $this->createWithdrawal($user, [
            'description'      => 'CARD AUTOPAY',
            'destination_name' => 'Card Payment',
            'amount'           => '250.00'
        ]);
        $rule = $this->createRule($user, ['description_is' => 'CARD AUTOPAY'], [['convert_transfer', 'Credit Card']]);

        $this->fire($user, $rule);

        $journal = $this->journal($group->id);
        self::assertSame(TransactionTypeEnum::TRANSFER->value, $journal->transactionType->type);
        self::assertSame($card->id, $this->destination($journal)->id);
        self::assertSame('Checking', $this->source($journal)->name);
        self::assertSame('250.00', bcadd($this->destinationAmount($journal), '0', 2));
    }

    public function testDoesNotConvertSplitGroups(): void
    {
        $user = $this->createAuthenticatedUser();
        $this->createAccount($user, AccountTypeEnum::ASSET, 'Credit Card');
        $group = $this->createTransactionGroup(
            $user,
            [
                ['description' => 'CARD AUTOPAY', 'amount' => '100.00'],
                ['description' => 'CARD AUTOPAY', 'amount' => '50.00']
            ],
            'Split payment'
        );
        $rule = $this->createRule($user, ['description_is' => 'CARD AUTOPAY'], [['convert_transfer', 'Credit Card']]);

        $this->fire($user, $rule);

        foreach ($group->transactionJournals()->get() as $journal) {
            self::assertSame(TransactionTypeEnum::WITHDRAWAL->value, $journal->fresh()->transactionType->type);
        }
    }

    public function testDoesNotConvertWhenNamedAccountIsMissing(): void
    {
        $user  = $this->createAuthenticatedUser();
        $group = $this->createWithdrawal($user, ['description' => 'OTHER CARD AUTOPAY']);
        $rule  = $this->createRule($user, ['description_is' => 'OTHER CARD AUTOPAY'], [['convert_transfer', 'Other Card']]);

        $this->fire($user, $rule);

        self::assertSame(TransactionTypeEnum::WITHDRAWAL->value, $this->journal($group->id)->transactionType->type);
    }

    public function testDoesNotConvertWhenNamedAccountIsNotAnAssetAccount(): void
    {
        $user = $this->createAuthenticatedUser();
        // a liability with that name exists, but convert_transfer needs an account of the SOURCE's type
        $this->createAccount($user, AccountTypeEnum::LOAN, 'Car Loan');
        $group = $this->createWithdrawal($user, ['description' => 'LOAN PMTS']);
        $rule  = $this->createRule($user, ['description_is' => 'LOAN PMTS'], [['convert_transfer', 'Car Loan']]);

        $this->fire($user, $rule);

        self::assertSame(TransactionTypeEnum::WITHDRAWAL->value, $this->journal($group->id)->transactionType->type);
    }

    public function testLeavesExistingTransfersAlone(): void
    {
        $user  = $this->createAuthenticatedUser();
        $group = $this->createTransfer($user, ['description' => 'MOVE TO SAVINGS']);
        $rule  = $this->createRule($user, ['description_is' => 'MOVE TO SAVINGS'], [['convert_transfer', 'Savings']]);

        $this->fire($user, $rule);

        $journal = $this->journal($group->id);
        self::assertSame(TransactionTypeEnum::TRANSFER->value, $journal->transactionType->type);
        self::assertSame('Savings', $this->destination($journal)->name);
    }

    public function testSetCategoryCreatesButSetBudgetRequiresExistingBudget(): void
    {
        $user  = $this->createAuthenticatedUser();
        $group = $this->createWithdrawal($user, ['description' => 'WHOLEFDS MKT']);
        $rule  = $this->createRule(
            $user,
            ['description_is' => 'WHOLEFDS MKT'],
            [
                ['set_category', 'Groceries'],
                ['set_budget',   'Groceries']
            ]
        );

        $this->fire($user, $rule);

        $journal = $this->journal($group->id);
        self::assertSame('Groceries', $journal->categories()->first()?->name);
        self::assertSame(0, $journal->budgets()->count(), 'set_budget must not create budgets on the fly');
    }

    private function destination(TransactionJournal $journal): \FireflyIII\Models\Account
    {
        return $journal->transactions()->where('amount', '>', 0)->first()->account;
    }

    private function destinationAmount(TransactionJournal $journal): string
    {
        return (string) $journal->transactions()->where('amount', '>', 0)->first()->amount;
    }

    private function fire(User $user, Rule $rule): void
    {
        /** @var RuleEngineInterface $engine */
        $engine = app(RuleEngineInterface::class);
        $engine->setUser($user);
        $engine->setRules(new Collection([$rule]));
        $engine->fire();
    }

    private function journal(int $groupId): TransactionJournal
    {
        return TransactionJournal::query()->where('transaction_group_id', $groupId)->with(['transactionType', 'transactions.account'])->firstOrFail();
    }

    private function source(TransactionJournal $journal): \FireflyIII\Models\Account
    {
        return $journal->transactions()->where('amount', '<', 0)->first()->account;
    }
}
