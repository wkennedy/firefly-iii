<?php

/*
 * BudgetSuggestionController.php
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

namespace FireflyIII\Fork\Http\Controllers;

use FireflyIII\Api\V1\Controllers\Controller;
use FireflyIII\Fork\Budgets\BudgetSuggester;
use FireflyIII\User;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * FORK: /api/v1/fork/insight/budget-suggestions — read suggestions, optionally apply them.
 */
final class BudgetSuggestionController extends Controller
{
    public function apply(Request $request, BudgetSuggester $suggester): JsonResponse
    {
        $data = $request->validate([
            'months'    => ['nullable', 'integer', 'min:1', 'max:60'],
            'exclude'   => ['nullable', 'string', 'max:2000'],
            'statistic' => ['nullable', Rule::in(BudgetSuggester::STATISTICS)],
            'dry_run'   => ['nullable', 'boolean']
        ]);
        $user   = $this->user();
        $result = $suggester->suggest($user, (int) ($data['months'] ?? 6), $this->exclude($data));
        $rows   = $suggester->apply($user, $result, (string) ($data['statistic'] ?? 'median'), (bool) ($data['dry_run'] ?? false));

        return response()->json(['data' => [
            'statistic' => (string) ($data['statistic'] ?? 'median'),
            'dry_run'   => (bool) ($data['dry_run'] ?? false),
            'applied'   => $rows
        ]]);
    }

    public function index(Request $request, BudgetSuggester $suggester): JsonResponse
    {
        $data = $request->validate(['months' => ['nullable', 'integer', 'min:1', 'max:60'], 'exclude' => ['nullable', 'string', 'max:2000']]);

        return response()->json(['data' => $suggester->suggest($this->user(), (int) ($data['months'] ?? 6), $this->exclude($data))]);
    }

    /**
     * @param  array<string, mixed>  $data
     *
     * @return list<string>
     */
    private function exclude(array $data): array
    {
        return array_values(array_filter(array_map('trim', explode(',', (string) ($data['exclude'] ?? ''))), static fn(string $n): bool => '' !== $n));
    }

    private function user(): User
    {
        $user = auth()->user();
        if (!$user instanceof User) {
            throw new AuthenticationException();
        }

        return $user;
    }
}
