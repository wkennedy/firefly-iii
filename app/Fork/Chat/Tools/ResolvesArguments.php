<?php

/*
 * ResolvesArguments.php
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
use FireflyIII\Fork\Chat\ToolException;
use FireflyIII\Models\Category;
use FireflyIII\Repositories\Category\CategoryRepositoryInterface;
use FireflyIII\User;
use Illuminate\Support\Collection;
use Throwable;

/**
 * FORK: turns model-written tool arguments into real objects. Everything here treats its input as
 * untrusted: a model will happily send "last month", an empty string or a category that does not
 * exist, and every one of those has to come back as a message the model can act on rather than a
 * 500.
 */
trait ResolvesArguments
{
    /**
     * Resolve category names to the user's own categories. Unknown names are an error naming them,
     * so the model can retry with a name from list_categories instead of silently getting zero.
     *
     * @param array<string, mixed> $arguments
     */
    protected function categories(User $user, array $arguments, string $key = 'categories'): ?Collection
    {
        $names      = $arguments[$key] ?? null;
        if (null === $names || [] === $names || '' === $names) {
            return null;
        }
        if (is_string($names)) {
            $names = [$names];
        }
        if (!is_array($names)) {
            throw new ToolException(sprintf('"%s" must be a list of category names.', $key));
        }

        /** @var CategoryRepositoryInterface $repository */
        $repository = app(CategoryRepositoryInterface::class);
        $repository->setUser($user);
        $found      = new Collection();
        $unknown    = [];
        foreach ($names as $name) {
            $category = $repository->findByName((string) $name);
            if ($category instanceof Category) {
                $found->push($category);

                continue;
            }
            $unknown[] = (string) $name;
        }
        if ([] !== $unknown) {
            throw new ToolException(sprintf('no such category: %s. Call list_categories for the real names.', implode(', ', $unknown)));
        }

        return $found;
    }

    /**
     * A whole-day date range. Both ends are required — an unbounded range over a real ledger is a
     * table the model cannot fit in its context anyway.
     *
     * @param array<string, mixed> $arguments
     *
     * @return array{Carbon, Carbon}
     */
    protected function range(array $arguments): array
    {
        $start = $this->date($arguments, 'start');
        $end   = $this->date($arguments, 'end');
        if ($start->gt($end)) {
            throw new ToolException('"start" is after "end".');
        }

        return [$start->startOfDay(), $end->endOfDay()];
    }

    /**
     * @param array<string, mixed> $arguments
     */
    private function date(array $arguments, string $key): Carbon
    {
        $value = $arguments[$key] ?? null;
        if (!is_string($value) || '' === $value) {
            throw new ToolException(sprintf('"%s" is required and must be a date as YYYY-MM-DD.', $key));
        }
        // Carbon throws on anything it cannot read ("last month", "May", ""), which is exactly what
        // a model sends when it ignores the format, so the throw is the normal path here.
        try {
            $date = Carbon::createFromFormat('!Y-m-d', $value);
        } catch (Throwable) {
            $date = null;
        }
        if (!$date instanceof Carbon) {
            throw new ToolException(sprintf('"%s" ("%s") is not a date as YYYY-MM-DD.', $key, $value));
        }

        return $date;
    }
}
