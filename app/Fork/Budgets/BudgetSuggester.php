<?php

/*
 * BudgetSuggester.php
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

namespace FireflyIII\Fork\Budgets;

use Carbon\Carbon;
use FireflyIII\Enums\AutoBudgetType;
use FireflyIII\Enums\TransactionTypeEnum;
use FireflyIII\Helpers\Collector\GroupCollectorInterface;
use FireflyIII\Models\AutoBudget;
use FireflyIII\Models\Budget;
use FireflyIII\Models\TransactionCurrency;
use FireflyIII\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * FORK: suggests a monthly budget per category from the last N complete months of withdrawals.
 * A month without spending in a category counts as 0 — it is a real month for budgeting. Amounts
 * are bcmath strings; statistics are rounded to 2 decimals.
 */
final class BudgetSuggester
{
    /** @var list<string> */
    public const array STATISTICS = ['mean', 'median', 'p75', 'max'];

    private static function actionFor(null|Budget $budget, null|AutoBudget $auto, string $amount): string
    {
        if (null === $budget) {
            return 'create budget + auto-budget';
        }
        if (null === $auto) {
            return 'add auto-budget';
        }
        if (0 === bccomp((string) $auto->amount, $amount, 2)) {
            return 'unchanged';
        }

        return sprintf('adjust %s → %s', self::money((string) $auto->amount), $amount);
    }

    /**
     * @param  list<string>  $values
     */
    private static function max(array $values): string
    {
        $max = '0';
        foreach ($values as $value) {
            if (1 === bccomp($value, $max, 12)) {
                $max = $value;
            }
        }

        return self::money($max);
    }

    /**
     * @param  list<string>  $values
     */
    private static function mean(array $values): string
    {
        if ([] === $values) {
            return '0.00';
        }
        $sum = '0';
        foreach ($values as $value) {
            $sum = bcadd($sum, $value, 12);
        }

        return self::money(bcdiv($sum, (string) count($values), 12));
    }

    /**
     * Round half-up to 2 decimals and always show them (bcmath truncates, "0" has no decimals).
     */
    private static function money(string $value): string
    {
        return bcadd(app('steam')->bcround($value, 2), '0', 2);
    }

    /**
     * Nearest-rank percentile on the sorted monthly totals.
     *
     * @param  list<string>  $values
     */
    private static function percentile(array $values, int $percent): string
    {
        if ([] === $values) {
            return '0.00';
        }
        usort($values, static fn(string $a, string $b): int => bccomp($a, $b, 12));
        $rank = (int) max(1, (int) ceil(($percent / 100) * count($values)));

        return self::money($values[$rank - 1]);
    }

    /**
     * Create or adjust a monthly "reset" auto-budget per suggestion, named after the category.
     *
     * @param  array{suggestions: list<array<string, mixed>>}  $result
     *
     * @return list<array{category: string, budget: string, amount: string, action: string}>
     */
    public function apply(User $user, array $result, string $statistic, bool $dryRun = false): array
    {
        if (!in_array($statistic, self::STATISTICS, true)) {
            throw new \InvalidArgumentException(sprintf('Unknown statistic "%s".', $statistic));
        }
        $rows = [];
        foreach ($result['suggestions'] as $suggestion) {
            $amount = (string) $suggestion[$statistic];
            if (1 !== bccomp($amount, '0', 2)) {
                continue;
            }
            $category = (string) $suggestion['category'];
            $currency = TransactionCurrency::query()
                ->where('code', (string) $suggestion['currency_code'])
                ->first();
            if (null === $currency) {
                continue;
            }
            $budget = Budget::query()->where('user_group_id', $user->user_group_id)->where('name', $category)->first();
            $auto   = null !== $budget ? AutoBudget::query()->where('budget_id', $budget->id)->first() : null;
            $action = self::actionFor($budget, $auto, $amount);
            $rows[] = ['category' => $category, 'budget' => $category, 'amount' => $amount, 'action' => $action];
            if ($dryRun || 'unchanged' === $action) {
                continue;
            }
            DB::transaction(static function () use ($user, $category, $amount, $currency, &$budget, &$auto): void {
                $budget ??= Budget::query()->create([
                    'user_id'       => $user->id,
                    'user_group_id' => $user->user_group_id,
                    'name'          => $category,
                    'active'        => true,
                    'order'         => (int) Budget::query()->where('user_group_id', $user->user_group_id)->max('order') + 1
                ]);
                if (null === $auto) {
                    $auto                          = new AutoBudget(['budget_id' => $budget->id, 'amount' => $amount, 'period' => 'monthly']);
                    $auto->transaction_currency_id = $currency->id;
                    $auto->auto_budget_type        = AutoBudgetType::AUTO_BUDGET_RESET->value;
                    $auto->save();

                    return;
                }
                $auto->amount = $amount;
                $auto->save();
            });
            Log::info(sprintf('FORK budget suggestion applied: "%s" = %s %s (%s).', $category, $amount, $currency->code, $action));
        }

        return $rows;
    }

    /**
     * @param  list<string>  $exclude  category names to leave out (case-insensitive)
     *
     * @return array{start: string, end: string, months: int, suggestions: list<array{category: string, currency_code: string, months_with_spend: int, monthly: array<string, string>, mean: string, median: string, p75: string, max: string, budget: null|array{id: int, name: string, auto_budget_amount: null|string, auto_budget_period: null|string}}>}
     */
    public function suggest(User $user, int $months, array $exclude = [], null|Carbon $asOf = null): array
    {
        $months = max(1, min(60, $months));
        $asOf   ??= Carbon::now(config('app.timezone'));
        $end    = $asOf->clone()->startOfMonth()->subDay()->endOfDay(); // last day of the previous month
        $start = $end
            ->clone()
            ->startOfMonth()
            ->subMonths($months - 1)
            ->startOfDay();
        $excluded  = array_map(static fn(string $n): string => mb_strtolower(trim($n)), $exclude);
        $monthKeys = [];
        for ($cursor = $start->clone(); $cursor->lessThanOrEqualTo($end); $cursor->addMonth()) {
            $monthKeys[] = $cursor->format('Y-m');
        }

        /** @var GroupCollectorInterface $collector */
        $collector = app(GroupCollectorInterface::class);
        $journals  = $collector
            ->setUser($user)
            ->setRange($start, $end)
            ->setTypes([TransactionTypeEnum::WITHDRAWAL->value])
            ->withCategoryInformation()
            ->getExtractedJournals();

        /** @var array<string, array{category: string, currency_code: string, monthly: array<string, string>}> $buckets */
        $buckets = [];
        foreach ($journals as $journal) {
            $category = (string) ($journal['category_name'] ?? '');
            if ('' === $category || in_array(mb_strtolower($category), $excluded, true)) {
                continue;
            }
            $currency      = (string) $journal['currency_code'];
            $key           = $category . '|' . $currency;
            $buckets[$key] ??= ['category' => $category, 'currency_code' => $currency, 'monthly' => array_fill_keys($monthKeys, '0')];
            $month         = $journal['date']->format('Y-m');
            if (!array_key_exists($month, $buckets[$key]['monthly'])) {
                continue;
            }
            // withdrawals carry the (negative) source amount; budgets are positive numbers
            $buckets[$key]['monthly'][$month] = bcadd($buckets[$key]['monthly'][$month], bcmul((string) $journal['amount'], '-1', 12), 12);
        }
        ksort($buckets);

        $suggestions = [];
        foreach ($buckets as $bucket) {
            $values        = array_values($bucket['monthly']);
            $suggestions[] = [
                'category'          => $bucket['category'],
                'currency_code'     => $bucket['currency_code'],
                'months_with_spend' => count(array_filter($values, static fn(string $v): bool => 1 === bccomp($v, '0', 2))),
                'monthly'           => array_map(static fn(string $v): string => self::money($v), $bucket['monthly']),
                'mean'              => self::mean($values),
                'median'            => self::percentile($values, 50),
                'p75'               => self::percentile($values, 75),
                'max'               => self::max($values),
                'budget'            => $this->existingBudget($user, $bucket['category'])
            ];
        }

        return ['start' => $start->format('Y-m-d'), 'end' => $end->format('Y-m-d'), 'months' => $months, 'suggestions' => $suggestions];
    }

    /**
     * @return null|array{id: int, name: string, auto_budget_amount: null|string, auto_budget_period: null|string}
     */
    private function existingBudget(User $user, string $category): null|array
    {
        $budget = Budget::query()->where('user_group_id', $user->user_group_id)->where('name', $category)->first();
        if (null === $budget) {
            return null;
        }
        $auto = AutoBudget::query()->where('budget_id', $budget->id)->first();

        return [
            'id'                 => (int) $budget->id,
            'name'               => (string) $budget->name,
            'auto_budget_amount' => null === $auto ? null : self::money((string) $auto->amount),
            'auto_budget_period' => $auto?->period
        ];
    }
}
