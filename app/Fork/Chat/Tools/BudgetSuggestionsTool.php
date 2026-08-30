<?php

/*
 * BudgetSuggestionsTool.php
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

use FireflyIII\Fork\Budgets\BudgetSuggester;
use FireflyIII\User;
use Override;

/**
 * FORK: chat tool — what recent months say each budget should be. Straight reuse of the fork's
 * BudgetSuggester (the same numbers as GET /api/v1/fork/insight/budget-suggestions and the daily
 * report), so the chat cannot drift from the endpoint that applies them.
 *
 * Read-only: this suggests, it does not touch a budget. Applying is a deliberate act elsewhere.
 */
final class BudgetSuggestionsTool implements ChatTool
{
    public function __construct(private readonly BudgetSuggester $suggester) {}

    #[Override]
    public function definition(): array
    {
        return [
            'name'        => $this->name(),
            'description' => 'What each category has actually cost per month over recent complete months (mean, median, 75th percentile, max), next to the budget that exists today. Use for "what should my budget be", "is my budget realistic". This only suggests; it changes nothing.',
            'parameters'  => [
                'type'       => 'object',
                'properties' => [
                    'months' => ['type' => 'integer', 'description' => 'How many complete months to look back over. Defaults to 6.'],
                ],
                'required'   => [],
            ],
        ];
    }

    #[Override]
    public function name(): string
    {
        return 'budget_suggestions';
    }

    #[Override]
    public function run(User $user, array $arguments): array
    {
        $months = (int) ($arguments['months'] ?? 6);
        $result = $this->suggester->suggest($user, max(1, min(60, $months)));

        // The per-month breakdown is the bulk of the payload and the model never quotes it; the
        // statistics are what an answer is built from.
        $result['suggestions'] = array_map(static function (array $row): array {
            unset($row['monthly']);

            return $row;
        }, $result['suggestions']);

        return $result;
    }
}
