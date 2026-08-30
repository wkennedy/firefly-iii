<?php

/*
 * ToolsTest.php
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

namespace Tests\integration\Fork\Chat;

use Carbon\Carbon;
use FireflyIII\Enums\AccountTypeEnum;
use FireflyIII\Enums\AutoBudgetType;
use FireflyIII\Fork\Chat\ToolRegistry;
use FireflyIII\Models\AutoBudget;
use FireflyIII\Models\Budget;
use FireflyIII\Models\BudgetLimit;
use FireflyIII\Models\TransactionCurrency;
use FireflyIII\User;
use Tests\integration\TestCase;
use Tests\integration\Traits\CreatesTransactionGroups;

/**
 * FORK: the chat's read-only tools (config fork.chat). Driven through the registry, which is how
 * the agent reaches them, so argument decoding, error handling and the size cap are covered too.
 *
 * @internal
 *
 * @coversNothing
 */
final class ToolsTest extends TestCase
{
    use CreatesTransactionGroups;

    private User $user;

    public function testAccountBalancesMatchTheAccountsPage(): void
    {
        $this->spend('Groceries', '112.44', '2026-05-06', 'WHOLEFDS');
        $this->createDeposit($this->user, [
            'amount'        => '2500.00',
            'date'          => Carbon::parse('2026-05-01 12:00:00', 'UTC'),
            'currency_code' => 'EUR',
        ]);

        $result   = $this->tool('account_balances');
        $balances = [];
        foreach ($result['accounts'] as $row) {
            $balances[$row['name']] = $row['balance'];
        }

        // The claim this tool makes is that it cannot disagree with the rest of Firefly, so the
        // assertion is against Firefly's own API rather than a number typed into this test.
        $api      = $this->getJson('/api/v1/accounts?type=asset')->assertOk()->json('data');
        self::assertNotEmpty($api);
        foreach ($api as $account) {
            $name = $account['attributes']['name'];
            if (!array_key_exists($name, $balances)) {
                continue;
            }
            self::assertSame(
                $account['attributes']['current_balance'],
                $balances[$name],
                sprintf('chat and /api/v1/accounts disagree about "%s"', $name)
            );
        }
        self::assertSame('2387.56', $balances['Checking'], '2500.00 in, 112.44 out');
    }

    public function testBudgetStatusReportsSpentLimitAndWhatIsLeft(): void
    {
        $groceries = $this->budget('Groceries budget', limit: '500.00');
        $this->budget('Fuel budget', auto: '150.00');            // recurring amount, no limit this period
        $this->spend('Groceries', '200.00', '2026-05-06', 'WHOLEFDS', $groceries);
        $this->spend('Dining Out', '75.00', '2026-05-07', 'TAQUERIA');  // no budget at all

        $result = $this->tool('budget_status', ['start' => '2026-05-01', 'end' => '2026-05-31']);
        $rows   = [];
        foreach ($result['budgets'] as $row) {
            $rows[$row['budget']] = $row;
        }

        self::assertSame('200.00', $rows['Groceries budget']['spent']);
        self::assertSame('500.00', $rows['Groceries budget']['limit']);
        self::assertSame('300.00', $rows['Groceries budget']['left']);
        self::assertSame(40.0, $rows['Groceries budget']['used_percent']);
        self::assertFalse($rows['Groceries budget']['limit_from_auto_budget']);

        // A budget whose limit the cron has not created yet must not look unlimited.
        self::assertSame('150.00', $rows['Fuel budget']['limit']);
        self::assertTrue($rows['Fuel budget']['limit_from_auto_budget']);
        self::assertSame('0.00', $rows['Fuel budget']['spent']);

        self::assertSame('75.00', $result['outside_budgets'][0]['amount'], 'the un-budgeted withdrawal');
    }

    public function testCalculateIsReachableThroughTheRegistry(): void
    {
        self::assertSame('1483.44', $this->tool('calculate', ['expression' => '1071.56 + 411.88'])['result']);
        // A bad expression is advice for the model, not an exception that ends the turn.
        self::assertStringContainsString('not something I can calculate', $this->tool('calculate', ['expression' => 'sum(x)'])['error']);
    }

    public function testGroundingToolsListWhatExists(): void
    {
        $this->spend('Groceries', '10.00', '2026-05-02', 'WHOLEFDS');
        $this->budget('Groceries budget', auto: '400.00');
        $this->createAccount($this->user, AccountTypeEnum::DEBT, 'Old loan');

        self::assertSame(['Groceries'], $this->tool('list_categories')['categories']);

        $accounts = $this->tool('list_accounts');
        self::assertContains('Checking', array_column($accounts['assets'], 'name'));
        self::assertSame(['Old loan'], array_column($accounts['liabilities'], 'name'));

        $budgets  = $this->tool('list_budgets')['budgets'];
        self::assertSame('Groceries budget', $budgets[0]['name']);
        self::assertSame('400.00', $budgets[0]['recurring_amount']);
    }

    public function testIncomeVsExpenseCountsUncategorisedOnBothSides(): void
    {
        $this->createDeposit($this->user, ['amount' => '2500.00', 'date' => Carbon::parse('2026-05-01 12:00:00', 'UTC'), 'currency_code' => 'EUR']);
        $this->spend('Groceries', '200.00', '2026-05-06', 'WHOLEFDS');
        $this->createWithdrawal($this->user, ['amount' => '50.00', 'date' => Carbon::parse('2026-05-08 12:00:00', 'UTC'), 'currency_code' => 'EUR']);
        $this->createTransfer($this->user, ['amount' => '900.00', 'date' => Carbon::parse('2026-05-09 12:00:00', 'UTC'), 'currency_code' => 'EUR']);

        $totals = $this->tool('income_vs_expense', ['start' => '2026-05-01', 'end' => '2026-05-31'])['totals'][0];
        self::assertSame('2500.00', $totals['income'], 'the deposit has no category and still counts');
        self::assertSame('250.00', $totals['spent'], 'categorised 200 + uncategorised 50, transfer excluded');
        self::assertSame('2250.00', $totals['difference']);
    }

    public function testOversizedResultsAreTrimmedAndSaySo(): void
    {
        for ($day = 1; $day <= 20; ++$day) {
            $this->spend('Groceries', '10.00', sprintf('2026-05-%02d', $day), sprintf('SHOP NUMBER %d WITH A LONG NAME', $day));
        }
        config(['fork.chat_max_result_bytes' => 1200]);

        $result = $this->tool('search_transactions', ['start' => '2026-05-01', 'end' => '2026-05-31']);
        self::assertSame(20, $result['matched'], 'the count is honest even when the rows are not all there');
        self::assertLessThan(20, count($result['transactions']));
        self::assertTrue($result['truncated']);
        self::assertStringContainsString('rather than presenting this as the whole list', $result['truncated_note']);
    }

    public function testSearchCanFindTheBiggestTransaction(): void
    {
        $this->spend('Groceries', '10.00', '2026-05-02', 'SMALL');
        $this->spend('Groceries', '480.00', '2026-05-03', 'BIG');
        $this->spend('Groceries', '90.00', '2026-05-04', 'MEDIUM');

        // The 4b live run showed the model guessing an amount filter to find a maximum; this is the
        // one-call answer it should reach for instead.
        $result = $this->tool('search_transactions', ['start' => '2026-05-01', 'end' => '2026-05-31', 'order_by' => 'amount_desc', 'limit' => 1]);
        self::assertSame('BIG', $result['transactions'][0]['description']);
        self::assertSame('480.00', $result['transactions'][0]['amount']);
        self::assertSame(3, $result['matched']);
        self::assertTrue($result['truncated']);
    }

    public function testUnbudgetedSpendingGroupsByCategory(): void
    {
        $budget = $this->budget('Groceries budget', limit: '500.00');
        $this->spend('Groceries', '200.00', '2026-05-06', 'WHOLEFDS', $budget);
        $this->spend('Dining Out', '75.00', '2026-05-07', 'TAQUERIA');
        $this->spend('Dining Out', '25.00', '2026-05-08', 'COFFEE');
        $this->createWithdrawal($this->user, ['amount' => '13.00', 'date' => Carbon::parse('2026-05-09 12:00:00', 'UTC'), 'currency_code' => 'EUR']);

        $rows = $this->tool('unbudgeted_spending', ['start' => '2026-05-01', 'end' => '2026-05-31'])['categories'];
        self::assertSame([
            ['category' => 'Dining Out', 'currency' => 'EUR', 'amount' => '100.00', 'transactions' => 2],
            ['category' => '(no category)', 'currency' => 'EUR', 'amount' => '13.00', 'transactions' => 1],
        ], $rows, 'budgeted groceries are not here; biggest first');
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpForkTestSupport();
        config(['fork.chat' => true, 'fork.chat_max_rows' => 50, 'fork.chat_max_result_bytes' => 12000]);
        $this->user = $this->createAuthenticatedUser();
        $this->actingAs($this->user);
    }

    private function budget(string $name, ?string $limit = null, ?string $auto = null): Budget
    {
        $budget   = Budget::create([
            'user_id'       => $this->user->id,
            'user_group_id' => $this->user->user_group_id,
            'name'          => $name,
            'active'        => true,
            'order'         => (int) Budget::query()->where('user_id', $this->user->id)->max('order') + 1,
        ]);
        $currency = TransactionCurrency::query()->where('code', 'EUR')->firstOrFail();
        if (null !== $limit) {
            BudgetLimit::create([
                'budget_id'               => $budget->id,
                'transaction_currency_id' => $currency->id,
                'start_date'              => '2026-05-01',
                'end_date'                => '2026-05-31',
                'amount'                  => $limit,
            ]);
        }
        if (null !== $auto) {
            // transaction_currency_id and auto_budget_type are not fillable on AutoBudget.
            $autoBudget                          = new AutoBudget();
            $autoBudget->budget_id               = $budget->id;
            $autoBudget->transaction_currency_id = $currency->id;
            $autoBudget->auto_budget_type        = AutoBudgetType::AUTO_BUDGET_RESET->value;
            $autoBudget->amount                  = $auto;
            $autoBudget->period                  = 'monthly';
            $autoBudget->save();
        }

        return $budget;
    }

    /**
     * @param array<string, mixed> $arguments
     *
     * @return array<string, mixed>
     */
    private function tool(string $tool, array $arguments = []): array
    {
        return app(ToolRegistry::class)->execute($this->user, $tool, (string) json_encode($arguments));
    }

    private function spend(string $category, string $amount, string $date, string $description, ?Budget $budget = null): void
    {
        $row = [
            'category_name' => $category,
            'amount'        => $amount,
            'date'          => Carbon::parse(sprintf('%s 12:00:00', $date), 'UTC'),
            'description'   => $description,
            'currency_code' => 'EUR',
        ];
        if ($budget instanceof Budget) {
            $row['budget_id'] = $budget->id;
        }
        $this->createWithdrawal($this->user, $row);
    }
}
