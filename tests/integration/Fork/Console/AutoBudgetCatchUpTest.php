<?php

/*
 * AutoBudgetCatchUpTest.php
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

namespace Tests\integration\Fork\Console;

use Carbon\Carbon;
use FireflyIII\Enums\AutoBudgetType;
use FireflyIII\Models\AutoBudget;
use FireflyIII\Models\Budget;
use FireflyIII\Models\BudgetLimit;
use FireflyIII\Models\TransactionCurrency;
use FireflyIII\Support\Facades\AppConfiguration;
use FireflyIII\User;
use Override;
use Tests\integration\TestCase;
use Tests\integration\Traits\CreatesTransactionGroups;

/**
 * FORK: firefly-iii:fork:auto-budget-catchup replays CreateAutoBudgetLimits for the
 * days a cron missed, sharing upstream's `last_ab_job` marker.
 *
 * @internal
 *
 * @coversNothing
 */
final class AutoBudgetCatchUpTest extends TestCase
{
    use CreatesTransactionGroups;

    private const string COMMAND = 'firefly-iii:fork:auto-budget-catchup';

    public function testIsIdempotentAndReportsUpToDate(): void
    {
        $this->today('2026-08-05 09:00:00');
        $user   = $this->createAuthenticatedUser();
        $budget = $this->monthlyResetBudget($user, '500');
        $this->lastRun('2026-07-30');

        $this->artisan(self::COMMAND)->assertExitCode(0);
        $this->artisan(self::COMMAND)->expectsOutputToContain('up to date')->assertExitCode(0);

        self::assertSame(1, $budget->budgetlimits()->count());
    }

    public function testNeverRanBeforeStartsToday(): void
    {
        $this->today('2026-08-05 09:00:00');
        $user   = $this->createAuthenticatedUser();
        $budget = $this->monthlyResetBudget($user, '500');

        $this->artisan(self::COMMAND)->expectsOutputToContain('never run')->assertExitCode(0);

        self::assertSame(0, $budget->budgetlimits()->count(), 'the 5th is not a magic day; no backfill without a marker');
        self::assertSame($this->timestamp('2026-08-05'), (int) AppConfiguration::get('last_ab_job')->data);
    }

    public function testRejectsBadDateAndOversizedRange(): void
    {
        $this->today('2026-08-05 09:00:00');
        $this->createAuthenticatedUser();

        $this->artisan(self::COMMAND, ['--since' => '2026-13-01'])->assertExitCode(1);
        $this->artisan(self::COMMAND, ['--since' => '2020-01-01'])->expectsOutputToContain('more than --max-days=400')->assertExitCode(1);
        self::assertSame(0, BudgetLimit::query()->count());
    }

    public function testReplaysAMissedFirstOfTheMonth(): void
    {
        $this->today('2026-08-05 09:00:00');
        $user   = $this->createAuthenticatedUser();
        $budget = $this->monthlyResetBudget($user, '500');
        $this->lastRun('2026-07-30');

        $this
            ->artisan(self::COMMAND)
            ->expectsOutputToContain('Running auto-budget job for 6 day(s): 2026-07-31 → 2026-08-05.')
            ->expectsOutputToContain('created 1 budget limit(s)')
            ->assertExitCode(0);

        $limit = $budget->budgetlimits()->first();
        self::assertNotNull($limit);
        self::assertSame('2026-08-01', $limit->start_date->format('Y-m-d'));
        self::assertSame('2026-08-31', $limit->end_date->format('Y-m-d'));
        self::assertSame($this->timestamp('2026-08-05'), (int) AppConfiguration::get('last_ab_job')->data, 'marker must advance to today');
    }

    public function testSinceOptionAndDryRun(): void
    {
        $this->today('2026-08-05 09:00:00');
        $user   = $this->createAuthenticatedUser();
        $budget = $this->monthlyResetBudget($user, '500');

        $this->artisan(self::COMMAND, ['--since' => '2026-08-01', '--dry-run' => true])->expectsOutputToContain('would run 2026-08-01')->assertExitCode(0);
        self::assertSame(0, $budget->budgetlimits()->count());
        self::assertSame(0, (int) AppConfiguration::get('last_ab_job', 0)->data, 'dry run must not record a run');

        $this->artisan(self::COMMAND, ['--since' => '2026-08-01'])->assertExitCode(0);
        self::assertSame(1, $budget->budgetlimits()->count());
    }

    #[Override]
    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function lastRun(string $date): void
    {
        AppConfiguration::set('last_ab_job', $this->timestamp($date));
    }

    private function monthlyResetBudget(User $user, string $amount): Budget
    {
        $budget = Budget::create([
            'user_id'       => $user->id,
            'user_group_id' => $user->user_group_id,
            'name'          => 'Groceries',
            'active'        => true,
            'order'         => 1
        ]);
        $autoBudget                          = new AutoBudget(['budget_id' => $budget->id, 'amount' => $amount, 'period' => 'monthly']);
        $autoBudget->transaction_currency_id = TransactionCurrency::query()->where('code', 'EUR')->firstOrFail()->id;
        $autoBudget->auto_budget_type        = AutoBudgetType::AUTO_BUDGET_RESET->value;
        $autoBudget->save();

        return $budget;
    }

    private function timestamp(string $date): int
    {
        return (int) Carbon::parse($date, config('app.timezone'))->startOfDay()->format('U');
    }

    private function today(string $dateTime): void
    {
        Carbon::setTestNow(Carbon::parse($dateTime, config('app.timezone')));
    }
}
