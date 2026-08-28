<?php

/*
 * CreateAutoBudgetLimitsTest.php
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

namespace Tests\integration\Fork\Jobs;

use Carbon\Carbon;
use FireflyIII\Enums\AutoBudgetType;
use FireflyIII\Exceptions\FireflyException;
use FireflyIII\Jobs\CreateAutoBudgetLimits;
use FireflyIII\Models\AutoBudget;
use FireflyIII\Models\Budget;
use FireflyIII\Models\BudgetLimit;
use FireflyIII\Models\TransactionCurrency;
use FireflyIII\User;
use PHPUnit\Framework\Attributes\DataProvider;
use ReflectionMethod;
use Tests\integration\TestCase;
use Tests\integration\Traits\CreatesTransactionGroups;

/**
 * FORK: every budget uses an auto-budget (type=reset, monthly). The cron job only
 * creates the month's limit on its "magic day"; this pins that calendar, the
 * idempotency of re-runs, and the API's limit_exists guard.
 *
 * @internal
 *
 * @coversNothing
 */
final class CreateAutoBudgetLimitsTest extends TestCase
{
    use CreatesTransactionGroups;

    /**
     * @return iterable<string, array{string, string, bool}>
     */
    public static function magicDays(): iterable
    {
        yield 'daily, any day' => ['daily', '2026-08-28', true];
        yield 'weekly, Monday' => ['weekly', '2026-08-31', true];
        yield 'weekly, Friday' => ['weekly', '2026-08-28', false];
        yield 'monthly, 1st' => ['monthly', '2026-08-01', true];
        yield 'monthly, 2nd (cron missed)' => ['monthly', '2026-08-02', false];
        yield 'monthly, last day' => ['monthly', '2026-08-31', false];
        yield 'quarterly, Jul 1' => ['quarterly', '2026-07-01', true];
        yield 'quarterly, Oct 1' => ['quarterly', '2026-10-01', true];
        yield 'quarterly, Aug 1' => ['quarterly', '2026-08-01', false];
        yield 'half_year, Jul 1' => ['half_year', '2026-07-01', true];
        yield 'half_year, Apr 1' => ['half_year', '2026-04-01', false];
        yield 'yearly, Jan 1' => ['yearly', '2026-01-01', true];
        yield 'yearly, Jul 1' => ['yearly', '2026-07-01', false];
    }

    public function testApiRejectsASecondLimitForTheSamePeriodAndCurrency(): void
    {
        $user = $this->createAuthenticatedUser();
        $this->actingAs($user);
        $budget = $this->budgetWithAutoBudget($user, 'Groceries', '500');
        new CreateAutoBudgetLimits(Carbon::parse('2026-08-01', 'UTC'))->handle();

        $payload = ['start' => '2026-08-01', 'end' => '2026-08-31', 'amount' => '500', 'currency_code' => 'EUR'];
        $this
            ->postJson(route('api.v1.budgets.limits.store', [$budget->id]), $payload)
            ->assertUnprocessable()
            ->assertJsonPath('errors.start.0', trans('validation.limit_exists'));

        // a different period is fine
        $this->postJson(route('api.v1.budgets.limits.store', [$budget->id]), ['start' => '2026-09-01', 'end' => '2026-09-30'] + $payload)->assertSuccessful();
        self::assertSame(2, $budget->budgetlimits()->count());
    }

    public function testInactiveBudgetGetsNoLimit(): void
    {
        $user           = $this->createAuthenticatedUser();
        $budget         = $this->budgetWithAutoBudget($user, 'Old budget', '100');
        $budget->active = false;
        $budget->save();

        new CreateAutoBudgetLimits(Carbon::parse('2026-08-01', 'UTC'))->handle();

        self::assertSame(0, BudgetLimit::query()->count());
    }

    #[DataProvider('magicDays')]
    public function testIsMagicDay(string $period, string $date, bool $expected): void
    {
        self::assertSame($expected, $this->isMagicDay($period, $date));
    }

    public function testMissingTheFirstMeansNoLimitThatMonth(): void
    {
        $user   = $this->createAuthenticatedUser();
        $budget = $this->budgetWithAutoBudget($user, 'Groceries', '500');

        new CreateAutoBudgetLimits(Carbon::parse('2026-08-02', 'UTC'))->handle();

        self::assertSame(0, $budget->budgetlimits()->count());
    }

    public function testMonthlyResetCreatesTheLimitOnTheFirstAndIsIdempotent(): void
    {
        $user   = $this->createAuthenticatedUser();
        $budget = $this->budgetWithAutoBudget($user, 'Groceries', '500');

        new CreateAutoBudgetLimits(Carbon::parse('2026-08-01', 'UTC'))->handle();

        self::assertSame(1, $budget->budgetlimits()->count());
        $limit = $budget->budgetlimits()->first();
        self::assertSame('2026-08-01', $limit->start_date->format('Y-m-d'));
        self::assertSame('2026-08-31', $limit->end_date->format('Y-m-d'));
        self::assertSame(0, bccomp('500', (string) $limit->amount, 2));

        new CreateAutoBudgetLimits(Carbon::parse('2026-08-01', 'UTC'))->handle();
        new CreateAutoBudgetLimits(Carbon::parse('2026-08-01 23:59:59', 'UTC'))->handle();
        self::assertSame(1, $budget->budgetlimits()->count(), 'running the job twice on the magic day must not duplicate the limit');
    }

    public function testUnknownPeriodThrows(): void
    {
        $this->expectException(FireflyException::class);
        $this->isMagicDay('fortnightly', '2026-08-01');
    }

    private function budgetWithAutoBudget(User $user, string $name, string $amount, string $period = 'monthly'): Budget
    {
        $budget = Budget::create([
            'user_id'       => $user->id,
            'user_group_id' => $user->user_group_id,
            'name'          => $name,
            'active'        => true,
            'order'         => 1
        ]);
        $autoBudget                          = new AutoBudget(['budget_id' => $budget->id, 'amount' => $amount, 'period' => $period]);
        $autoBudget->transaction_currency_id = TransactionCurrency::query()->where('code', 'EUR')->firstOrFail()->id;
        $autoBudget->auto_budget_type        = AutoBudgetType::AUTO_BUDGET_RESET->value;
        $autoBudget->save();

        return $budget;
    }

    private function isMagicDay(string $period, string $date): bool
    {
        $job    = new CreateAutoBudgetLimits(Carbon::parse($date, 'UTC'));
        $method = new ReflectionMethod($job, 'isMagicDay');

        return $method->invoke($job, new AutoBudget(['period' => $period]));
    }
}
