<?php

/*
 * AutoBudgetCatchUp.php
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
use FireflyIII\Jobs\CreateAutoBudgetLimits;
use FireflyIII\Models\BudgetLimit;
use FireflyIII\Support\Facades\AppConfiguration;
use FireflyIII\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * FORK: CreateAutoBudgetLimits only creates a period's limit when it runs ON the
 * period's first day ("magic day"). A cron that misses the 1st therefore skips the
 * whole month, silently. This command replays the job for every day since the last
 * auto-budget run, sharing upstream's per-user `last_ab_job_<id>` bookkeeping so `firefly-iii:cron`
 * and this command never disagree about what has been done.
 *
 * Run it before `firefly-iii:cron` in the daily job. It is idempotent: the job itself
 * refuses to create a second limit for a period that already has one.
 */
final class AutoBudgetCatchUp extends Command
{
    use ShowsFriendlyMessages;

    protected $description = 'FORK: run the auto-budget job for every day since it last ran, so a missed cron does not skip a period.';

    protected $signature = 'firefly-iii:fork:auto-budget-catchup
        {--since= : First day to (re)process, YYYY-MM-DD. Defaults to the day after the last auto-budget run (or today if it never ran).}
        {--dry-run : List the days that would be processed without creating anything.}
        {--max-days=400 : Refuse to process a range longer than this many days.}';

    /**
     * Upstream's AutoBudgetCronjob keeps one marker per user.
     */
    public static function marker(User $user): string
    {
        return sprintf('last_ab_job_%d', $user->id);
    }

    public function handle(): int
    {
        $timezone = config('app.timezone');
        $today    = today($timezone);
        $maxDays  = (int) $this->option('max-days');
        $dryRun   = (bool) $this->option('dry-run');

        // Plan every user's range first, so an oversized range fails before anything is created.
        $plans = [];

        /** @var User $user */
        foreach (User::query()->orderBy('id')->get() as $user) {
            $since = $this->firstDay($user, $today, $timezone);
            if (null === $since) {
                return self::FAILURE;
            }
            if ($since->greaterThan($today)) {
                $this->friendlyPositive(sprintf('User #%d: auto-budgets are up to date (last run covered %s).', $user->id, $since->subDay()->format('Y-m-d')));

                continue;
            }
            $days = (int) $since->diffInDays($today) + 1;
            if ($days > $maxDays) {
                $this->friendlyError(sprintf(
                    'User #%d: range %s → %s is %d days, more than --max-days=%d. Pass --since to narrow it.',
                    $user->id,
                    $since->format('Y-m-d'),
                    $today->format('Y-m-d'),
                    $days,
                    $maxDays
                ));

                return self::FAILURE;
            }
            $plans[] = [$user, $since, $days];
        }

        $before = BudgetLimit::query()->count();
        foreach ($plans as [$user, $since, $days]) {
            $this->friendlyInfo(sprintf(
                'User #%d: %s auto-budget job for %d day(s): %s → %s.',
                $user->id,
                $dryRun ? 'Would run' : 'Running',
                $days,
                $since->format('Y-m-d'),
                $today->format('Y-m-d')
            ));

            for ($date = $since->clone(); $date->lessThanOrEqualTo($today); $date->addDay()) {
                if ($dryRun) {
                    $this->friendlyLine(sprintf('  would run %s', $date->format('Y-m-d')));

                    continue;
                }
                Log::info(sprintf('FORK auto-budget catch-up: running CreateAutoBudgetLimits for %s, user #%d', $date->format('Y-m-d'), $user->id));
                $job = new CreateAutoBudgetLimits($date->clone());
                $job->setUser($user);
                $job->handle();
                AppConfiguration::set(self::marker($user), (int) $date->clone()->startOfDay()->format('U'));
            }
        }

        if ($dryRun) {
            $this->friendlyNeutral('Dry run: nothing was created or recorded.');

            return self::SUCCESS;
        }
        $created = BudgetLimit::query()->count() - $before;
        $this->friendlyPositive(sprintf('Processed %d user(s), created %d budget limit(s).', count($plans), $created));

        return self::SUCCESS;
    }

    private function firstDay(User $user, Carbon $today, string $timezone): null|Carbon
    {
        $option = $this->option('since');
        if (null !== $option && '' !== $option) {
            $since = Carbon::createFromFormat('Y-m-d', (string) $option, $timezone);
            if (!$since instanceof Carbon || $since->format('Y-m-d') !== $option) {
                $this->friendlyError(sprintf('"%s" is not a valid YYYY-MM-DD date.', $option));

                return null;
            }

            return $since->startOfDay();
        }

        $lastRun = (int) AppConfiguration::get(self::marker($user), 0)->data;
        if (0 === $lastRun) {
            $this->friendlyInfo(sprintf('User #%d: the auto-budget job has never run; starting from today.', $user->id));

            return $today->clone();
        }

        return Carbon::createFromTimestamp($lastRun, $timezone)->startOfDay()->addDay();
    }
}
