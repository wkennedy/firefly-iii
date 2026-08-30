<?php

/*
 * SumByCategoryTool.php
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

namespace FireflyIII\Fork\Chat\Tools;

use FireflyIII\Repositories\Category\NoCategoryRepositoryInterface;
use FireflyIII\Repositories\Category\OperationsRepositoryInterface;
use FireflyIII\Support\Facades\Steam;
use FireflyIII\User;
use Illuminate\Support\Collection;
use Override;

/**
 * FORK: chat tool — totals per category over a date range, from the same repositories the reports
 * use, so an answer here matches the page. Amounts are returned as positive decimal strings and are
 * never rounded or combined across currencies; the model reports them, it does not compute them.
 */
final class SumByCategoryTool implements ChatTool
{
    use FormatsMoney;
    use ResolvesArguments;

    #[Override]
    public function definition(): array
    {
        return [
            'name'        => $this->name(),
            'description' => 'Total spending (or income) per category between two dates. Use this for "how much did I spend on X", "what did I spend most on", or any per-category total. Returns exact amounts per currency, already summed - never add these up yourself, call calculate instead.',
            'parameters'  => [
                'type'       => 'object',
                'properties' => [
                    'start'      => ['type' => 'string', 'description' => 'First day of the range, YYYY-MM-DD.'],
                    'end'        => ['type' => 'string', 'description' => 'Last day of the range, YYYY-MM-DD (inclusive).'],
                    'direction'  => ['type' => 'string', 'enum' => ['expenses', 'income'], 'description' => 'Defaults to expenses.'],
                    'categories' => [
                        'type'        => 'array',
                        'items'       => ['type' => 'string'],
                        'description' => 'Optional: only these categories, by exact name. Omit for every category.'
                    ]
                ],
                'required'   => ['start', 'end']
            ]
        ];
    }

    #[Override]
    public function name(): string
    {
        return 'sum_by_category';
    }

    #[Override]
    public function run(User $user, array $arguments): array
    {
        [$start, $end] = $this->range($arguments);
        $direction  = 'income' === ($arguments['direction'] ?? 'expenses') ? 'income' : 'expenses';
        $categories = $this->categories($user, $arguments);

        /** @var OperationsRepositoryInterface $operations */
        $operations = app(OperationsRepositoryInterface::class);
        $operations->setUser($user);
        $journals = 'income' === $direction
            ? $operations->collectIncome($start, $end, null, $categories)
            : $operations->collectExpenses($start, $end, null, $categories);

        $totals = [];
        foreach ($journals as $journal) {
            $name         = (string) ($journal['category_name'] ?? '');
            $currency     = (string) $journal['currency_code'];
            $key          = $name . '|' . $currency;
            $totals[$key] ??= [
                'category'      => $name,
                'currency_code' => $currency,
                'amount'        => '0',
                'decimals'      => (int) $journal['currency_decimal_places'],
                'transactions'  => 0
            ];
            $totals[$key]['amount'] = bcadd($totals[$key]['amount'], Steam::positive((string) $journal['amount']), 12);
            ++$totals[$key]['transactions'];
        }

        // Transactions with no category at all are invisible to the collector above, and leaving
        // them out turns "what did I spend in May" into a number that is quietly too low.
        $uncategorised = [];
        if (!$categories instanceof Collection) {
            /** @var NoCategoryRepositoryInterface $noCategory */
            $noCategory = app(NoCategoryRepositoryInterface::class);
            $noCategory->setUser($user);
            $sums = 'income' === $direction ? $noCategory->sumIncome($start, $end) : $noCategory->sumExpenses($start, $end);
            foreach ($sums as $sum) {
                $uncategorised[] = [
                    'currency_code' => (string) $sum['currency_code'],
                    'amount'        => $this->money(Steam::positive((string) $sum['sum']), (int) $sum['currency_decimal_places'])
                ];
            }
        }

        $rows = array_values($totals);
        usort($rows, static fn(array $a, array $b): int => bccomp($b['amount'], $a['amount'], 12));

        return [
            'start'              => $start->format('Y-m-d'),
            'end'                => $end->format('Y-m-d'),
            'direction'          => $direction,
            'totals'             => array_map(fn(array $row): array => [
                'category'      => '' === $row['category'] ? '(no category)' : $row['category'],
                'currency_code' => $row['currency_code'],
                'amount'        => $this->money($row['amount'], $row['decimals']),
                'transactions'  => $row['transactions']
            ], $rows),
            'uncategorised'      => $uncategorised,
            'uncategorised_note' => [] === $uncategorised
                ? 'Nothing in this range is missing a category.'
                : 'Amounts under "uncategorised" have no category at all and are NOT part of any total above.'
        ];
    }
}
