<?php

/*
 * AccountFactory.php
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

namespace FireflyIII\Fork\Factory;

use FireflyIII\Factory\AccountFactory as UpstreamFactory;
use FireflyIII\Fork\Payees\PayeeAliaser;
use FireflyIII\Models\Account;
use FireflyIII\Models\AccountType;
use FireflyIII\User;
use Override;

/**
 * FORK: bound in place of the upstream factory. Every path that turns a payee string into an
 * expense/revenue account ends here (the journal factory via AccountRepository::store(), rule
 * actions via findOrCreate()), so applying the alias here means the fragment account is never
 * created — the canonical one is found or created instead.
 */
final class AccountFactory extends UpstreamFactory
{
    private null|User $forkUser = null;

    #[Override]
    public function create(array $data): Account
    {
        if (null !== $this->forkUser && PayeeAliaser::enabled()) {
            $type = $this->getAccountType($data);
            if ($type instanceof AccountType && array_key_exists('name', $data)) {
                $data['name'] = app(PayeeAliaser::class)->resolve((int) $this->forkUser->user_group_id, $type->type, (string) $data['name']);
            }
        }

        return parent::create($data);
    }

    #[Override]
    public function findOrCreate(string $accountName, string $accountType): Account
    {
        if (null !== $this->forkUser && PayeeAliaser::enabled()) {
            $accountName = app(PayeeAliaser::class)->resolve((int) $this->forkUser->user_group_id, $accountType, $accountName);
        }

        return parent::findOrCreate($accountName, $accountType);
    }

    #[Override]
    public function setUser(User $user): void
    {
        parent::setUser($user);
        $this->forkUser = $user;
    }
}
