<?php

/*
 * PayeeAliaser.php
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

namespace FireflyIII\Fork\Payees;

use FireflyIII\Enums\AccountTypeEnum;
use FireflyIII\Fork\Models\ForkPayeeAlias;
use Illuminate\Support\Facades\Log;

/**
 * FORK: resolves a raw payee name to its canonical account name for a user group.
 * Only expense and revenue accounts are ever aliased — asset and liability names are sacred.
 */
final class PayeeAliaser
{
    /** @var list<string> */
    public const array ALIASABLE_TYPES = [AccountTypeEnum::EXPENSE->value, AccountTypeEnum::REVENUE->value];

    public static function enabled(): bool
    {
        return (bool) config('fork.payee_aliases');
    }

    public function isAliasable(string $accountType): bool
    {
        return in_array($accountType, self::ALIASABLE_TYPES, true);
    }

    /**
     * The first active alias (by order, then id) whose pattern matches, or null.
     */
    public function match(int $userGroupId, string $accountType, string $name): null|ForkPayeeAlias
    {
        if (!self::enabled() || !$this->isAliasable($accountType) || '' === trim($name)) {
            return null;
        }
        $aliases = ForkPayeeAlias::query()
            ->where('user_group_id', $userGroupId)
            ->where('account_type', $accountType)
            ->where('active', true)
            ->orderBy('order')
            ->orderBy('id')
            ->get();
        foreach ($aliases as $alias) {
            if ($alias->matches($name) && 0 !== strcasecmp(trim($name), trim((string) $alias->canonical_name))) {
                return $alias;
            }
        }

        return null;
    }

    /**
     * Canonical name for this payee (the raw name when nothing matches). Counts the hit.
     */
    public function resolve(int $userGroupId, string $accountType, string $name): string
    {
        $alias = $this->match($userGroupId, $accountType, $name);
        if (null === $alias) {
            return $name;
        }
        $alias->increment('hit_count');
        Log::debug(sprintf('FORK payee alias #%d: "%s" → "%s"', $alias->id, $name, $alias->canonical_name));

        return (string) $alias->canonical_name;
    }
}
