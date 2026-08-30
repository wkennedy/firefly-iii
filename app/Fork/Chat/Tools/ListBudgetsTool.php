<?php

/*
 * ListBudgetsTool.php
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

use FireflyIII\Models\AutoBudget;
use FireflyIII\Models\Budget;
use FireflyIII\Repositories\Budget\BudgetRepositoryInterface;
use FireflyIII\User;
use Override;

/**
 * FORK: chat tool — the budget names, with the recurring amount when one is set. Grounding for
 * budget questions; budget_status is the tool that says how they are doing.
 */
final class ListBudgetsTool implements ChatTool
{
    use FormatsMoney;

    #[Override]
    public function definition(): array
    {
        return [
            'name'        => $this->name(),
            'description' => 'List the active budgets and their recurring (auto-budget) amount, if any. Call this before answering anything that names a budget. For spent-vs-limit figures use budget_status.',
            'parameters'  => ['type' => 'object', 'properties' => new \stdClass(), 'required' => []]
        ];
    }

    #[Override]
    public function name(): string
    {
        return 'list_budgets';
    }

    #[Override]
    public function run(User $user, array $arguments): array
    {
        /** @var BudgetRepositoryInterface $repository */
        $repository = app(BudgetRepositoryInterface::class);
        $repository->setUser($user);

        $budgets = $repository
            ->getActiveBudgets()
            ->map(function (Budget $budget): array {
                /** @var null|AutoBudget $auto */
                $auto = $budget->autoBudgets()->first();

                return [
                    'name'               => $budget->name,
                    'recurring_amount'   => null === $auto ? null : $this->money((string) $auto->amount, $auto->transactionCurrency->decimal_places ?? 2),
                    'recurring_period'   => $auto?->period,
                    'recurring_currency' => $auto?->transactionCurrency?->code
                ];
            })
            ->values()
            ->all();

        return ['budgets' => $budgets, 'count' => count($budgets)];
    }
}
