<?php

/*
 * BudgetStatusTool.php
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

use Carbon\Carbon;
use FireflyIII\Models\AutoBudget;
use FireflyIII\Models\Budget;
use FireflyIII\Models\BudgetLimit;
use FireflyIII\Repositories\Budget\BudgetLimitRepositoryInterface;
use FireflyIII\Repositories\Budget\BudgetRepositoryInterface;
use FireflyIII\Repositories\Budget\NoBudgetRepositoryInterface;
use FireflyIII\Repositories\Budget\OperationsRepositoryInterface;
use FireflyIII\Support\Facades\Steam;
use FireflyIII\User;
use Illuminate\Support\Collection;
use Override;

/**
 * FORK: chat tool — budget versus reality for a period: spent, limit, what is left and the share
 * used, plus the spending no budget covers at all.
 *
 * Same shape as the daily Mattermost report's budget table (bryte-fi report/script.configmap.yaml),
 * including its fallback: a budget with a recurring amount but no limit for this period (the cron
 * has not created one yet) reports that amount, flagged, instead of looking unlimited.
 *
 * Percentages are computed here. The model is told repeatedly not to do arithmetic, so anything it
 * would otherwise have to divide has to arrive already divided.
 */
final class BudgetStatusTool implements ChatTool
{
    use FormatsMoney;
    use ResolvesArguments;

    #[Override]
    public function definition(): array
    {
        return [
            'name'        => $this->name(),
            'description' => 'How each budget is doing between two dates: amount spent, the limit, what is left and the percentage used, plus any spending that no budget covers. Use for "am I over budget", "how is my X budget", "how much is left".',
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
        return 'budget_status';
    }

    #[Override]
    public function run(User $user, array $arguments): array
    {
        [$start, $end] = $this->range($arguments);

        /** @var BudgetRepositoryInterface $budgets */
        $budgets = app(BudgetRepositoryInterface::class);
        /** @var OperationsRepositoryInterface $operations */
        $operations = app(OperationsRepositoryInterface::class);
        /** @var BudgetLimitRepositoryInterface $limits */
        $limits = app(BudgetLimitRepositoryInterface::class);
        /** @var NoBudgetRepositoryInterface $noBudget */
        $noBudget = app(NoBudgetRepositoryInterface::class);
        foreach ([$budgets, $operations, $limits, $noBudget] as $repository) {
            $repository->setUser($user);
        }

        $rows = [];
        foreach ($budgets->getActiveBudgets() as $budget) {
            /** @var Budget $budget */
            $spentPerCurrency = $operations->sumExpenses($start, $end, null, new Collection([$budget]));
            $limit            = $this->limit($limits, $budget, $start, $end);
            $spent            = '0';
            $currency         = $limit['currency'];
            foreach ($spentPerCurrency as $sum) {
                $spent    = bcadd($spent, Steam::positive((string) $sum['sum']), 12);
                $currency ??= (string) $sum['currency_code'];
            }
            $rows[] = [
                'budget'                 => $budget->name,
                'spent'                  => $this->money($spent),
                'limit'                  => $limit['amount'],
                'left'                   => null === $limit['amount'] ? null : $this->money(bcsub($limit['amount'], $spent, 12)),
                'used_percent'           => $this->percentage($spent, $limit['amount']),
                'currency'               => $currency,
                'limit_from_auto_budget' => $limit['from_auto']
            ];
        }
        usort($rows, static fn(array $a, array $b): int => ($b['used_percent'] ?? -1) <=> ($a['used_percent'] ?? -1));

        $outside = [];
        foreach ($noBudget->sumExpenses($start, $end) as $sum) {
            $outside[] = ['amount' => $this->money(Steam::positive((string) $sum['sum'])), 'currency' => (string) $sum['currency_code']];
        }

        return [
            'start'           => $start->format('Y-m-d'),
            'end'             => $end->format('Y-m-d'),
            'budgets'         => $rows,
            'outside_budgets' => $outside,
            'notes'           => [
                'A null limit means the budget has no amount set for this period; "left" and "used_percent" are then null too.',
                'limit_from_auto_budget means no limit exists for this period yet, so the budget\'s recurring amount is shown instead.',
                'outside_budgets is spending on withdrawals with no budget at all; it is not part of any row above.'
            ]
        ];
    }

    /**
     * @return array{amount: null|string, currency: null|string, from_auto: bool}
     */
    private function limit(BudgetLimitRepositoryInterface $limits, Budget $budget, Carbon $start, Carbon $end): array
    {
        $total    = null;
        $currency = null;
        foreach ($limits->getBudgetLimits($budget, $start, $end) as $limit) {
            /** @var BudgetLimit $limit */
            $total    = bcadd($total ?? '0', (string) $limit->amount, 12);
            $currency ??= $limit->transactionCurrency?->code;
        }
        if (null !== $total) {
            return ['amount' => $this->money($total), 'currency' => $currency, 'from_auto' => false];
        }

        /** @var null|AutoBudget $auto */
        $auto = $budget->autoBudgets()->first();
        if (null === $auto) {
            return ['amount' => null, 'currency' => null, 'from_auto' => false];
        }

        return ['amount' => $this->money((string) $auto->amount), 'currency' => $auto->transactionCurrency?->code, 'from_auto' => true];
    }

    private function percentage(string $spent, null|string $limit): null|float
    {
        if (null === $limit || 0 === bccomp($limit, '0', 12)) {
            return null;
        }

        return round((float) bcdiv($spent, $limit, 6) * 100, 1);
    }
}
