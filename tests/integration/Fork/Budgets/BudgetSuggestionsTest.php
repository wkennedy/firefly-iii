<?php

/*
 * BudgetSuggestionsTest.php
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

namespace Tests\integration\Fork\Budgets;

use Carbon\Carbon;
use FireflyIII\Fork\Budgets\BudgetSuggester;
use FireflyIII\Models\AutoBudget;
use FireflyIII\Models\Budget;
use FireflyIII\User;
use Override;
use Tests\integration\TestCase;
use Tests\integration\Traits\CreatesTransactionGroups;

/**
 * FORK: budget suggestions from history.
 *
 * @internal
 *
 * @coversNothing
 */
final class BudgetSuggestionsTest extends TestCase
{
    use CreatesTransactionGroups;

    public function testApplyCreatesAdjustsAndIsIdempotent(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-08-20', 'UTC'));
        $user = $this->createAuthenticatedUser();
        $this->spend($user, 'Groceries', '100.00', '2026-06-05');
        $this->spend($user, 'Groceries', '200.00', '2026-07-05');
        $suggester = app(BudgetSuggester::class);

        $rows = $suggester->apply($user, $suggester->suggest($user, 2), 'mean', dryRun: true);
        self::assertSame([['category' => 'Groceries', 'budget' => 'Groceries', 'amount' => '150.00', 'action' => 'create budget + auto-budget']], $rows);
        self::assertSame(0, Budget::query()->count());

        $suggester->apply($user, $suggester->suggest($user, 2), 'mean');
        $budget = Budget::query()->where('name', 'Groceries')->firstOrFail();
        $auto   = AutoBudget::query()->where('budget_id', $budget->id)->firstOrFail();
        self::assertSame(0, bccomp('150.00', (string) $auto->amount, 2));
        self::assertSame('monthly', $auto->period);
        self::assertSame(1, (int) $auto->auto_budget_type);

        $rows = $suggester->apply($user, $suggester->suggest($user, 2), 'mean');
        self::assertSame('unchanged', $rows[0]['action']);
        $rows = $suggester->apply($user, $suggester->suggest($user, 2), 'max');
        self::assertSame('adjust 150.00 → 200.00', $rows[0]['action']);
        self::assertSame(0, bccomp('200.00', (string) $auto->fresh()->amount, 2));
        self::assertSame(1, AutoBudget::query()->count(), 'never a second auto-budget');
        self::assertSame('200.00', $suggester->suggest($user, 2)['suggestions'][0]['budget']['auto_budget_amount']);
    }

    public function testCommandAndApi(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-08-20', 'UTC'));
        $user = $this->createAuthenticatedUser();
        $this->actingAs($user);
        $this->spend($user, 'Groceries', '100.00', '2026-06-05');
        $this->spend($user, 'Groceries', '200.00', '2026-07-05');
        $this->spend($user, 'Taxes', '900.00', '2026-07-05');

        $this->artisan('firefly-iii:fork:budgets:suggest', ['--months' => 2, '--exclude' => 'Taxes'])->expectsOutputToContain('1 category.')->assertExitCode(0);
        $this
            ->artisan('firefly-iii:fork:budgets:suggest', [
                '--months'    => 2,
                '--exclude'   => 'Taxes',
                '--apply'     => true,
                '--statistic' => 'p75',
                '--dry-run'   => true
            ])
            ->expectsOutputToContain('Would apply 1 auto-budget(s).')
            ->assertExitCode(0);
        self::assertSame(0, Budget::query()->count());
        $this->artisan('firefly-iii:fork:budgets:suggest', ['--apply' => true, '--statistic' => 'bogus'])->assertExitCode(1);

        $this
            ->getJson(route('api.v1.fork.insight.budget-suggestions') . '?months=2&exclude=Taxes')
            ->assertOk()
            ->assertJsonCount(1, 'data.suggestions')
            ->assertJsonPath('data.suggestions.0.median', '100.00');
        $this->postJson(route('api.v1.fork.insight.budget-suggestions.apply'), [
            'months'    => 2,
            'statistic' => 'p75',
            'dry_run'   => true
        ])->assertOk()->assertJsonPath('data.applied.0.action', 'create budget + auto-budget');
        self::assertSame(0, Budget::query()->count());
        $this->postJson(route('api.v1.fork.insight.budget-suggestions.apply'), [
            'months'    => 2,
            'exclude'   => 'Taxes',
            'statistic' => 'p75'
        ])->assertOk()->assertJsonCount(1, 'data.applied');
        self::assertSame(['Groceries'], Budget::query()->pluck('name')->all());
        self::assertSame(0, bccomp('200.00', (string) AutoBudget::query()->firstOrFail()->amount, 2));
    }

    public function testStatisticsOverCompleteMonthsWithZeroFilledGaps(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-08-20', 'UTC'));
        $user = $this->createAuthenticatedUser();
        // Groceries: May 100, June 300, July 0 (no spend), plus August 999 (current month: ignored)
        $this->spend($user, 'Groceries', '60.00', '2026-05-03');
        $this->spend($user, 'Groceries', '40.00', '2026-05-28');
        $this->spend($user, 'Groceries', '300.00', '2026-06-15');
        $this->spend($user, 'Groceries', '999.00', '2026-08-02');
        // Taxes: one month only; Uncategorised spend is ignored; a deposit is ignored
        $this->spend($user, 'Taxes', '14000.00', '2026-06-01');
        $this->createWithdrawal($user, ['amount' => '77.00', 'date' => Carbon::parse('2026-06-10', 'UTC')]);
        $this->createDeposit($user, ['amount' => '500.00', 'date' => Carbon::parse('2026-06-10', 'UTC'), 'category_name' => 'Groceries']);

        $result = app(BudgetSuggester::class)->suggest($user, 3);

        self::assertSame('2026-05-01', $result['start']);
        self::assertSame('2026-07-31', $result['end']);
        self::assertCount(2, $result['suggestions']);
        $groceries = $result['suggestions'][0];
        self::assertSame('Groceries', $groceries['category']);
        self::assertSame(['2026-05' => '100.00', '2026-06' => '300.00', '2026-07' => '0.00'], $groceries['monthly']);
        self::assertSame(2, $groceries['months_with_spend']);
        self::assertSame('133.33', $groceries['mean']);
        self::assertSame('100.00', $groceries['median']); // nearest rank of [0, 100, 300] at 50% → 2nd
        self::assertSame('300.00', $groceries['p75']); // 75% of 3 → rank 3
        self::assertSame('300.00', $groceries['max']);
        self::assertNull($groceries['budget']);
        self::assertSame('Taxes', $result['suggestions'][1]['category']);
        self::assertSame('4666.67', $result['suggestions'][1]['mean']);

        $excluded = app(BudgetSuggester::class)->suggest($user, 3, ['taxes']);
        self::assertSame(['Groceries'], array_column($excluded['suggestions'], 'category'));
    }

    #[Override]
    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function spend(User $user, string $category, string $amount, string $date): void
    {
        $this->createWithdrawal($user, [
            'amount'        => $amount,
            'date'          => Carbon::parse($date . ' 12:00:00', 'UTC'),
            'category_name' => $category,
            'description'   => $category . ' ' . $date
        ]);
    }
}
