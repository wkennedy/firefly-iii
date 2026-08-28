<?php

/*
 * LiabilityTransfers.php
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

namespace FireflyIII\Fork\Config;

use FireflyIII\Enums\AccountTypeEnum;
use FireflyIII\Enums\TransactionTypeEnum;

/**
 * FORK: lets an asset account transfer to a liability (loan / debt / mortgage). Upstream encodes
 * "asset → liability is a withdrawal" in four config maps read in different places; all four are
 * adjusted at runtime here so nothing in config/firefly.php is edited. Applied by ForkServiceProvider
 * when config('fork.liability_transfers') is on; tests call apply() directly.
 *
 * Verified 2026-08-28 against a production copy: liability and asset balances are identical for
 * both forms, spending/insight totals drop by the converted amount, transfer totals rise by it.
 */
final class LiabilityTransfers
{
    /** @var list<string> */
    public const array LIABILITY_TYPES = [AccountTypeEnum::LOAN->value, AccountTypeEnum::DEBT->value, AccountTypeEnum::MORTGAGE->value];

    /**
     * Idempotent: safe to call more than once.
     */
    public static function apply(): void
    {
        $transfer = TransactionTypeEnum::TRANSFER->value;
        $asset    = AccountTypeEnum::ASSET->value;

        // 1. AccountValidator (API + factory), MassController, CorrectsAccountTypes.
        self::merge(sprintf('firefly.source_dests.%s.%s', $transfer, $asset), self::LIABILITY_TYPES);

        // 2. CorrectsTransactionTypes, web create/edit, recurring: the expected type for the pair.
        //    (liability → asset stays a deposit: that is a loan being paid out.)
        foreach (self::LIABILITY_TYPES as $liability) {
            config()->set(sprintf('firefly.account_to_transaction.%s.%s', $asset, $liability), $transfer);
        }

        // 3. Web forms: which opposing account types may be offered.
        self::merge(sprintf('firefly.allowed_opposing_types.source.%s', $asset), self::LIABILITY_TYPES);
        foreach (self::LIABILITY_TYPES as $liability) {
            self::merge(sprintf('firefly.allowed_opposing_types.destination.%s', $liability), [$asset]);
        }

        // 4. Rule actions (set_destination_account & co.) and journal services.
        self::merge(sprintf('firefly.expected_source_types.destination.%s', $transfer), self::LIABILITY_TYPES);
        self::merge(sprintf('firefly.expected_source_types.source.%s', $transfer), [$asset]);
    }

    public static function enabled(): bool
    {
        return (bool) config('fork.liability_transfers');
    }

    /**
     * @param list<string> $values
     */
    private static function merge(string $key, array $values): void
    {
        $current = config($key);
        $current = is_array($current) ? $current : [];
        config()->set($key, array_values(array_unique(array_merge($current, $values))));
    }
}
