<?php

/*
 * UnbudgetedSpendingTool.php
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

use FireflyIII\Enums\TransactionTypeEnum;
use FireflyIII\Helpers\Collector\GroupCollectorInterface;
use FireflyIII\Support\Facades\Steam;
use FireflyIII\User;
use Override;

/**
 * FORK: chat tool — withdrawals that no budget covers, grouped by category, so "what am I spending
 * outside my budgets" has an answer with names on it rather than one lump sum.
 *
 * Mirrors the daily report's "outside budgets" line (bryte-fi report/script.configmap.yaml), which
 * is the same question asked once a day in Mattermost.
 */
final class UnbudgetedSpendingTool implements ChatTool
{
    use FormatsMoney;
    use ResolvesArguments;

    #[Override]
    public function definition(): array
    {
        return [
            'name'        => $this->name(),
            'description' => 'Spending between two dates that has no budget attached, grouped by category. Use for "what is not covered by a budget" or when budget_status shows money outside the budgets and the person asks what it was.',
            'parameters'  => [
                'type'       => 'object',
                'properties' => [
                    'start' => ['type' => 'string', 'description' => 'First day of the period, YYYY-MM-DD.'],
                    'end'   => ['type' => 'string', 'description' => 'Last day of the period, YYYY-MM-DD (inclusive).']
                ],
                'required'   => ['start', 'end']
            ]
        ];
    }

    #[Override]
    public function name(): string
    {
        return 'unbudgeted_spending';
    }

    #[Override]
    public function run(User $user, array $arguments): array
    {
        [$start, $end] = $this->range($arguments);

        /** @var GroupCollectorInterface $collector */
        $collector = app(GroupCollectorInterface::class);
        $journals  = $collector
            ->setUser($user)
            ->setRange($start, $end)
            ->setTypes([TransactionTypeEnum::WITHDRAWAL->value])
            ->withoutBudget()
            ->withCategoryInformation()
            ->getExtractedJournals();

        $rows = [];
        foreach ($journals as $journal) {
            $category             = '' === (string) ($journal['category_name'] ?? '') ? '(no category)' : (string) $journal['category_name'];
            $currency             = (string) $journal['currency_code'];
            $key                  = $category . '|' . $currency;
            $rows[$key]           ??= ['category' => $category, 'currency' => $currency, 'amount' => '0', 'transactions' => 0];
            $rows[$key]['amount'] = bcadd($rows[$key]['amount'], Steam::positive((string) $journal['amount']), 12);
            ++$rows[$key]['transactions'];
        }
        $rows = array_values($rows);
        usort($rows, static fn(array $a, array $b): int => bccomp($b['amount'], $a['amount'], 12));

        return [
            'start'      => $start->format('Y-m-d'),
            'end'        => $end->format('Y-m-d'),
            'categories' => array_map(fn(array $row): array => [
                'category'     => $row['category'],
                'currency'     => $row['currency'],
                'amount'       => $this->money($row['amount']),
                'transactions' => $row['transactions']
            ], $rows)
        ];
    }
}
