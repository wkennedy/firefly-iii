<?php

/*
 * PayeesPruneEmpty.php
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

namespace FireflyIII\Fork\Console\Commands;

use FireflyIII\Console\Commands\ShowsFriendlyMessages;
use FireflyIII\Fork\Payees\PayeeAliaser;
use FireflyIII\Models\Account;
use FireflyIII\Services\Internal\Destroy\AccountDestroyService;
use FireflyIII\User;
use Illuminate\Console\Command;

/**
 * FORK: deletes expense/revenue accounts that have no transactions left (what rule-based
 * retargeting leaves behind).
 */
final class PayeesPruneEmpty extends Command
{
    use ShowsFriendlyMessages;

    protected $description = 'FORK: delete expense/revenue accounts without any transactions.';

    protected $signature = 'firefly-iii:fork:payees:prune-empty {--dry-run : Report only.} {--user= : Only this user (email).}';

    public function handle(): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $users  = null !== $this->option('user') ? User::query()->where('email', (string) $this->option('user'))->get() : User::query()->orderBy('id')->get();
        $names  = [];

        /** @var AccountDestroyService $destroyer */
        $destroyer = app(AccountDestroyService::class);
        foreach ($users as $user) {
            $empty = Account::query()
                ->where('user_group_id', $user->user_group_id)
                ->whereHas('accountType', static fn($q) => $q->whereIn('type', PayeeAliaser::ALIASABLE_TYPES))
                ->whereDoesntHave('transactions')
                ->orderBy('name')
                ->get();
            foreach ($empty as $account) {
                $names[] = sprintf('%s (%s)', $account->name, $user->email);
                if (!$dryRun) {
                    $destroyer->destroy($account, null);
                }
            }
        }
        foreach ($names as $name) {
            $this->friendlyLine(sprintf('  %s %s', $dryRun ? 'would delete' : 'deleted', $name));
        }
        $this->friendlyPositive(sprintf('%s %d empty account(s).', $dryRun ? 'Would delete' : 'Deleted', count($names)));

        return self::SUCCESS;
    }
}
