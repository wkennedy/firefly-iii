<?php

/*
 * ListCategoriesTool.php
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

use FireflyIII\Models\Category;
use FireflyIII\Repositories\Category\CategoryRepositoryInterface;
use FireflyIII\User;
use Override;

/**
 * FORK: chat tool — the user's category names. Grounding: without it the model invents plausible
 * category names ("Restaurants") that do not exist in this ledger ("Dining Out") and reports zero.
 */
final class ListCategoriesTool implements ChatTool
{
    #[Override]
    public function definition(): array
    {
        return [
            'name'        => $this->name(),
            'description' => 'List the names of the categories that exist in this ledger. Call this first whenever a question mentions a category, so you use names that really exist.',
            'parameters'  => ['type' => 'object', 'properties' => new \stdClass(), 'required' => []],
        ];
    }

    #[Override]
    public function name(): string
    {
        return 'list_categories';
    }

    #[Override]
    public function run(User $user, array $arguments): array
    {
        /** @var CategoryRepositoryInterface $repository */
        $repository = app(CategoryRepositoryInterface::class);
        $repository->setUser($user);
        $names      = $repository->getCategories()->map(static fn(Category $category): string => $category->name)->values()->all();
        sort($names);

        return ['categories' => $names, 'count' => count($names)];
    }
}
