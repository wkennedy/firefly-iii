<?php

/*
 * IncomeVsExpenseTool.php
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
use Override;

/**
 * FORK: chat tool — money in against money out for a period, per currency, with the difference
 * already worked out.
 *
 * Both halves include transactions with no category (the category repositories only see categorised
 * ones), because "did I save anything last month" answered from categorised rows alone is wrong in
 * a way nobody would notice.
 */
final class IncomeVsExpenseTool implements ChatTool
{
    use FormatsMoney;
    use ResolvesArguments;

    #[Override]
    public function definition(): array
    {
        return [
            'name'        => $this->name(),
            'description' => 'Total income, total spending and the difference between two dates, per currency. Use for "did I save money", "how much came in", "what is my net for the month". Transfers between your own accounts are not counted either way.',
            'parameters'  => [
                'type'       => 'object',
                'properties' => [
                    'start' => ['type' => 'string', 'description' => 'First day of the period, YYYY-MM-DD.'],
                    'end'   => ['type' => 'string', 'description' => 'Last day of the period, YYYY-MM-DD (inclusive).'],
                ],
                'required'   => ['start', 'end'],
            ],
        ];
    }

    #[Override]
    public function name(): string
    {
        return 'income_vs_expense';
    }

    #[Override]
    public function run(User $user, array $arguments): array
    {
        [$start, $end] = $this->range($arguments);

        /** @var OperationsRepositoryInterface $operations */
        $operations    = app(OperationsRepositoryInterface::class);
        /** @var NoCategoryRepositoryInterface $noCategory */
        $noCategory    = app(NoCategoryRepositoryInterface::class);
        $operations->setUser($user);
        $noCategory->setUser($user);

        $totals        = [];
        $this->add($totals, $operations->sumIncome($start, $end), 'income');
        $this->add($totals, $noCategory->sumIncome($start, $end), 'income');
        $this->add($totals, $operations->sumExpenses($start, $end), 'spent');
        $this->add($totals, $noCategory->sumExpenses($start, $end), 'spent');

        foreach ($totals as $code => $row) {
            $totals[$code]['difference'] = $this->money(bcsub($row['income'], $row['spent'], 12));
            $totals[$code]['income']     = $this->money($row['income']);
            $totals[$code]['spent']      = $this->money($row['spent']);
        }

        return [
            'start'   => $start->format('Y-m-d'),
            'end'     => $end->format('Y-m-d'),
            'totals'  => array_values($totals),
            'note'    => 'difference is income minus spending; a negative difference means more went out than came in.',
        ];
    }

    /**
     * @param array<string, array<string, string>> $totals
     * @param array<int, array<string, mixed>>     $sums
     */
    private function add(array &$totals, array $sums, string $key): void
    {
        foreach ($sums as $sum) {
            $code                 = (string) $sum['currency_code'];
            $totals[$code] ??= ['currency' => $code, 'income' => '0', 'spent' => '0'];
            $totals[$code][$key]  = bcadd($totals[$code][$key], Steam::positive((string) $sum['sum']), 12);
        }
    }
}
