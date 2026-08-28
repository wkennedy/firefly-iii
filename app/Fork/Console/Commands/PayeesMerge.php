<?php

/*
 * PayeesMerge.php
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
use FireflyIII\Factory\AccountFactory;
use FireflyIII\Fork\Models\ForkPayeeAlias;
use FireflyIII\Fork\Payees\PayeeAliaser;
use FireflyIII\Models\Account;
use FireflyIII\Services\Internal\Destroy\AccountDestroyService;
use FireflyIII\User;
use Illuminate\Console\Command;

/**
 * FORK: folds existing fragment accounts ("AMAZON MKTPL*AB12", "AMAZON MKTPL*CD34", …) into the
 * canonical account of the alias that matches them: transactions are moved, the fragment deleted.
 */
final class PayeesMerge extends Command
{
    use ShowsFriendlyMessages;

    protected $description = 'FORK: merge expense/revenue accounts that match a payee alias into the canonical account.';

    protected $signature = 'firefly-iii:fork:payees:merge {--dry-run : Report only.} {--user= : Only this user (email).}';

    public function handle(PayeeAliaser $aliaser): int
    {
        if (!PayeeAliaser::enabled()) {
            $this->friendlyWarning('Payee aliases are disabled (FORK_PAYEE_ALIASES).');

            return self::SUCCESS;
        }
        $dryRun = (bool) $this->option('dry-run');
        $users  = null !== $this->option('user') ? User::query()->where('email', (string) $this->option('user'))->get() : User::query()->orderBy('id')->get();
        $rows   = [];
        $moved  = 0;

        foreach ($users as $user) {
            /** @var AccountFactory $factory */
            $factory = app(AccountFactory::class);
            $factory->setUser($user);

            /** @var AccountDestroyService $destroyer */
            $destroyer = app(AccountDestroyService::class);

            $aliases = ForkPayeeAlias::query()
                ->where('user_group_id', $user->user_group_id)
                ->where('active', true)
                ->orderBy('order')
                ->orderBy('id')
                ->get();
            $accounts = Account::query()
                ->where('user_group_id', $user->user_group_id)
                ->whereHas('accountType', static fn($q) => $q->whereIn('type', PayeeAliaser::ALIASABLE_TYPES))
                ->with('accountType')
                ->orderBy('id')
                ->get();
            foreach ($accounts as $fragment) {
                $alias = $aliases->first(
                    static fn(ForkPayeeAlias $a): bool => (
                        $a->account_type === $fragment->accountType->type
                        && $a->matches((string) $fragment->name)
                        && 0 !== strcasecmp((string) $fragment->name, (string) $a->canonical_name)
                    )
                );
                if (null === $alias) {
                    continue;
                }
                $count  = $fragment->transactions()->count();
                $rows[] = [$user->email, $fragment->name, $alias->canonical_name, $count];
                if ($dryRun) {
                    continue;
                }
                $canonical = $factory->findOrCreate((string) $alias->canonical_name, $fragment->accountType->type);
                $destroyer->destroy($fragment, $canonical);
                $moved += $count;
            }
        }

        if ([] !== $rows) {
            $this->table(['user', 'fragment account', 'canonical', 'transactions'], $rows);
        }
        $this->friendlyPositive(sprintf('%s %d account(s), %d transaction(s) moved.', $dryRun ? 'Would merge' : 'Merged', count($rows), $moved));

        return self::SUCCESS;
    }
}
