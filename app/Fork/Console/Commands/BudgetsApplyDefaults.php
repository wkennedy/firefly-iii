<?php

/*
 * BudgetsApplyDefaults.php
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

namespace FireflyIII\Fork\Console\Commands;

use Carbon\Carbon;
use FireflyIII\Console\Commands\ShowsFriendlyMessages;
use FireflyIII\Fork\Budgets\DefaultBudgets;
use FireflyIII\User;
use Illuminate\Console\Command;

/**
 * FORK: backfill default budgets for a date range.
 */
final class BudgetsApplyDefaults extends Command
{
    use ShowsFriendlyMessages;

    protected $description = 'FORK: attach each category\'s default budget to withdrawals that have a category but no budget.';

    protected $signature = 'firefly-iii:fork:budgets:apply-defaults
        {--start= : First date, YYYY-MM-DD (default: 90 days ago).}
        {--end= : Last date, YYYY-MM-DD (default: today).}
        {--dry-run : Report only.}
        {--user= : Only this user (email).}';

    public function handle(DefaultBudgets $defaults): int
    {
        if (!DefaultBudgets::enabled()) {
            $this->friendlyWarning('Category → budget defaults are disabled (FORK_CATEGORY_BUDGETS).');

            return self::SUCCESS;
        }
        $timezone = config('app.timezone');
        $start    = $this->date('start', Carbon::now($timezone)->subDays(90));
        $end      = $this->date('end', Carbon::now($timezone));
        if (null === $start || null === $end) {
            return self::FAILURE;
        }
        $dryRun = (bool) $this->option('dry-run');
        $users  = null !== $this->option('user') ? User::query()->where('email', (string) $this->option('user'))->get() : User::query()->orderBy('id')->get();

        foreach ($users as $user) {
            $summary = $defaults->backfill($user, $start, $end, $dryRun);
            $this->friendlyInfo(sprintf(
                '%s: %d withdrawal(s) with a category and no budget between %s and %s; %s %d.',
                $user->email,
                $summary['examined'],
                $start->format('Y-m-d'),
                $end->format('Y-m-d'),
                $dryRun ? 'would set' : 'set',
                $summary['applied']
            ));
            foreach ($summary['budgets'] as $name => $count) {
                $this->friendlyLine(sprintf('  %s: %d', $name, $count));
            }
        }

        return self::SUCCESS;
    }

    private function date(string $option, Carbon $default): null|Carbon
    {
        $value = $this->option($option);
        if (null === $value || '' === $value) {
            return $default;
        }
        $date = Carbon::createFromFormat('Y-m-d', (string) $value, config('app.timezone'));
        if (!$date instanceof Carbon || $date->format('Y-m-d') !== $value) {
            $this->friendlyError(sprintf('"%s" is not a valid YYYY-MM-DD date for --%s.', (string) $value, $option));

            return null;
        }

        return $date;
    }
}
