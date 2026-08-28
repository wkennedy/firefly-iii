<?php

/*
 * CategoryBudgetController.php
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

use Carbon\Carbon;
use FireflyIII\Api\V1\Controllers\Controller;
use FireflyIII\Fork\Budgets\DefaultBudgets;
use FireflyIII\Fork\Models\ForkCategoryBudget;
use FireflyIII\Models\Budget;
use FireflyIII\Models\Category;
use FireflyIII\User;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * FORK: /api/v1/fork/category-budgets — category → default budget mappings. Plain JSON.
 */
final class CategoryBudgetController extends Controller
{
    public function apply(Request $request, DefaultBudgets $defaults): JsonResponse
    {
        $data = $request->validate([
            'start'   => ['nullable', 'date_format:Y-m-d'],
            'end'     => ['nullable', 'date_format:Y-m-d'],
            'dry_run' => ['nullable', 'boolean']
        ]);
        $tz    = config('app.timezone');
        $start = null !== ($data['start'] ?? null) ? Carbon::createFromFormat('Y-m-d', (string) $data['start'], $tz) : Carbon::now($tz)->subDays(90);
        $end   = null !== ($data['end'] ?? null) ? Carbon::createFromFormat('Y-m-d', (string) $data['end'], $tz) : Carbon::now($tz);
        if (!$start instanceof Carbon || !$end instanceof Carbon) {
            return response()->json(['message' => 'Invalid date.'], 422);
        }

        return response()->json(['data' => $defaults->backfill($this->user(), $start, $end, (bool) ($data['dry_run'] ?? false))]);
    }

    public function destroy(int $id): JsonResponse
    {
        $mapping = ForkCategoryBudget::query()->where('user_group_id', $this->user()->user_group_id)->where('id', $id)->first();
        if (null === $mapping) {
            return response()->json(['message' => 'Mapping not found.'], 404);
        }
        $mapping->delete();

        return response()->json(null, 204);
    }

    public function index(): JsonResponse
    {
        $rows = [];
        foreach (ForkCategoryBudget::query()
            ->where('user_group_id', $this->user()->user_group_id)
            ->with(['category', 'budget'])
            ->orderBy('id')
            ->get() as $mapping) {
            $rows[] = $this->present($mapping);
        }

        return response()->json(['data' => $rows]);
    }

    /**
     * Create or replace the mapping for a category (one budget per category).
     */
    public function store(Request $request): JsonResponse
    {
        $data     = $request->validate(['category_id' => ['required', 'integer'], 'budget_id' => ['required', 'integer']]);
        $user     = $this->user();
        $category = Category::query()
            ->where('user_group_id', $user->user_group_id)
            ->find((int) $data['category_id']);
        $budget = Budget::query()
            ->where('user_group_id', $user->user_group_id)
            ->find((int) $data['budget_id']);
        if (null === $category || null === $budget) {
            return response()->json(['message' => 'Category or budget not found.'], 422);
        }
        $mapping = ForkCategoryBudget::query()->updateOrCreate(['user_group_id' => $user->user_group_id, 'category_id' => $category->id], [
            'budget_id' => $budget->id
        ]);

        return response()->json(['data' => $this->present($mapping->fresh(['category', 'budget']))], $mapping->wasRecentlyCreated ? 201 : 200);
    }

    /**
     * @return array<string, mixed>
     */
    private function present(ForkCategoryBudget $mapping): array
    {
        return [
            'id'            => (int) $mapping->id,
            'category_id'   => (int) $mapping->category_id,
            'category_name' => (string) $mapping->category?->name,
            'budget_id'     => (int) $mapping->budget_id,
            'budget_name'   => (string) $mapping->budget?->name
        ];
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
