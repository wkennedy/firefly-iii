<?php

/*
 * DefaultBudgets.php
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
use FireflyIII\Enums\TransactionTypeEnum;
use FireflyIII\Fork\Models\ForkCategoryBudget;
use FireflyIII\Models\Budget;
use FireflyIII\Models\Category;
use FireflyIII\Models\TransactionJournal;
use FireflyIII\User;
use Illuminate\Support\Facades\Log;

/**
 * FORK: attaches the default budget of a withdrawal's category when the journal has none.
 * Deposits/transfers never get budgets (matching upstream's set_budget), an existing budget is
 * never replaced, an inactive budget is never used.
 */
final class DefaultBudgets
{
    public static function enabled(): bool
    {
        return (bool) config('fork.category_budgets');
    }

    /**
     * @return null|Budget the budget that was attached, or null when nothing changed
     */
    public function apply(TransactionJournal $journal, bool $dryRun = false): null|Budget
    {
        if (!self::enabled()) {
            return null;
        }
        if (TransactionTypeEnum::WITHDRAWAL->value !== $journal->transactionType->type) {
            return null;
        }
        if ($journal->budgets()->exists()) {
            return null;
        }

        /** @var null|Category $category */
        $category = $journal->categories()->first();
        if (null === $category) {
            return null;
        }
        $mapping = ForkCategoryBudget::query()->where('user_group_id', $journal->user_group_id)->where('category_id', $category->id)->first();
        if (null === $mapping) {
            return null;
        }

        /** @var null|Budget $budget */
        $budget = Budget::query()->where('user_group_id', $journal->user_group_id)->where('active', true)->find($mapping->budget_id);
        if (null === $budget) {
            return null;
        }
        if (!$dryRun) {
            $journal->budgets()->sync([$budget->id]);
            Log::debug(sprintf('FORK default budget: journal #%d (category "%s") → budget "%s".', $journal->id, $category->name, $budget->name));
        }

        return $budget;
    }

    /**
     * @return array{examined: int, applied: int, budgets: array<string, int>}
     */
    public function backfill(User $user, Carbon $start, Carbon $end, bool $dryRun = false): array
    {
        $summary = ['examined' => 0, 'applied' => 0, 'budgets' => []];
        if (!self::enabled()) {
            return $summary;
        }
        $journals = TransactionJournal::query()
            ->where('user_group_id', $user->user_group_id)
            ->whereBetween('date', [$start->clone()->startOfDay(), $end->clone()->endOfDay()])
            ->whereHas('transactionType', static fn($q) => $q->where('type', TransactionTypeEnum::WITHDRAWAL->value))
            ->whereHas('categories')
            ->whereDoesntHave('budgets')
            ->orderBy('date')
            ->orderBy('id')
            ->with('transactionType')
            ->get();
        foreach ($journals as $journal) {
            ++$summary['examined'];
            $budget = $this->apply($journal, $dryRun);
            if (null !== $budget) {
                ++$summary['applied'];
                $summary['budgets'][$budget->name] = ($summary['budgets'][$budget->name] ?? 0) + 1;
            }
        }

        return $summary;
    }
}
