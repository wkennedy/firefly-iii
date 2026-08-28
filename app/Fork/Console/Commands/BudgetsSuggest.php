<?php

/*
 * BudgetsSuggest.php
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

use FireflyIII\Console\Commands\ShowsFriendlyMessages;
use FireflyIII\Fork\Budgets\BudgetSuggester;
use FireflyIII\User;
use Illuminate\Console\Command;
use InvalidArgumentException;

/**
 * FORK: budget suggestions from history; --apply turns them into monthly reset auto-budgets.
 */
final class BudgetsSuggest extends Command
{
    use ShowsFriendlyMessages;

    protected $description = 'FORK: suggest a monthly budget per category from the last N complete months (mean / median / p75 / max).';

    protected $signature = 'firefly-iii:fork:budgets:suggest
        {--months=6 : Number of complete months to look back.}
        {--exclude= : Comma-separated category names to leave out.}
        {--statistic=median : Which statistic --apply uses: mean, median, p75 or max.}
        {--apply : Create or adjust a monthly reset auto-budget per category at that statistic.}
        {--dry-run : With --apply: show what would change without changing it.}
        {--user= : Only this user (email).}';

    public function handle(BudgetSuggester $suggester): int
    {
        $months    = (int) $this->option('months');
        $exclude   = array_values(array_filter(array_map('trim', explode(',', (string) $this->option('exclude'))), static fn(string $n): bool => '' !== $n));
        $statistic = (string) $this->option('statistic');
        $users     = null !== $this->option('user')
            ? User::query()->where('email', (string) $this->option('user'))->get()
            : User::query()->orderBy('id')->get();

        foreach ($users as $user) {
            $result = $suggester->suggest($user, $months, $exclude);
            $this->friendlyInfo(sprintf(
                '%s: %d complete month(s), %s → %s, %d categor%s.',
                $user->email,
                $result['months'],
                $result['start'],
                $result['end'],
                count($result['suggestions']),
                1 === count($result['suggestions']) ? 'y' : 'ies'
            ));
            $this->table(['category', 'currency', 'months w/ spend', 'mean', 'median', 'p75', 'max', 'current auto-budget'], array_map(
                static fn(array $s): array => [
                    $s['category'],
                    $s['currency_code'],
                    $s['months_with_spend'],
                    $s['mean'],
                    $s['median'],
                    $s['p75'],
                    $s['max'],
                    null === $s['budget'] ? '—' : $s['budget']['auto_budget_amount'] ?? 'no auto-budget'
                ],
                $result['suggestions']
            ));
            if (!(bool) $this->option('apply')) {
                continue;
            }

            try {
                $rows = $suggester->apply($user, $result, $statistic, (bool) $this->option('dry-run'));
            } catch (InvalidArgumentException $e) {
                $this->friendlyError($e->getMessage());

                return self::FAILURE;
            }
            $this->table(['category', 'amount (' . $statistic . ')', 'action'], array_map(static fn(array $r): array => [
                $r['category'],
                $r['amount'],
                $r['action']
            ], $rows));
            $this->friendlyPositive(sprintf(
                '%s %d auto-budget(s).',
                (bool) $this->option('dry-run') ? 'Would apply' : 'Applied',
                count(array_filter($rows, static fn(array $r): bool => 'unchanged' !== $r['action']))
            ));
        }

        return self::SUCCESS;
    }
}
