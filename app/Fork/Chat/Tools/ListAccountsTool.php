<?php

/*
 * ListAccountsTool.php
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

use FireflyIII\Enums\AccountTypeEnum;
use FireflyIII\Models\Account;
use FireflyIII\Repositories\Account\AccountRepositoryInterface;
use FireflyIII\User;
use Override;

/**
 * FORK: chat tool — the accounts money actually sits in. Grounding, like list_categories: without
 * it the model guesses at account names and reports nothing found.
 */
final class ListAccountsTool implements ChatTool
{
    #[Override]
    public function definition(): array
    {
        return [
            'name'        => $this->name(),
            'description' => 'List the accounts in this ledger: asset accounts (current accounts, savings, cards) and liabilities (loans, debts, mortgages). Call this before answering anything that names an account.',
            'parameters'  => ['type' => 'object', 'properties' => new \stdClass(), 'required' => []],
        ];
    }

    #[Override]
    public function name(): string
    {
        return 'list_accounts';
    }

    #[Override]
    public function run(User $user, array $arguments): array
    {
        /** @var AccountRepositoryInterface $repository */
        $repository  = app(AccountRepositoryInterface::class);
        $repository->setUser($user);

        $describe    = static fn(Account $account): array => [
            'name'     => $account->name,
            'type'     => strtolower((string) $account->accountType?->type),
            'currency' => $repository->getAccountCurrency($account)?->code,
        ];
        $assets      = $repository->getActiveAccountsByType([AccountTypeEnum::ASSET->value]);
        $liabilities = $repository->getActiveAccountsByType([
            AccountTypeEnum::LOAN->value,
            AccountTypeEnum::DEBT->value,
            AccountTypeEnum::MORTGAGE->value,
        ]);

        return [
            'assets'      => $assets->map($describe)->values()->all(),
            'liabilities' => $liabilities->map($describe)->values()->all(),
        ];
    }
}
