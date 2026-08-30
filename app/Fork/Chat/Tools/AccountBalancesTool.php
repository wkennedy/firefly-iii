<?php

/*
 * AccountBalancesTool.php
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
use FireflyIII\Enums\AccountTypeEnum;
use FireflyIII\Models\Account;
use FireflyIII\Repositories\Account\AccountRepositoryInterface;
use FireflyIII\Support\Facades\Amount;
use FireflyIII\Support\Facades\Steam;
use FireflyIII\User;
use Illuminate\Support\Collection;
use Override;

/**
 * FORK: chat tool — what is in each account right now (or on a given date).
 *
 * The balance is computed the same way the API's `current_balance` is (AccountEnrichment: the
 * account's own currency out of Steam::finalAccountBalance, rounded to that currency's decimals),
 * so an answer here, the accounts page and the daily report cannot disagree.
 */
final class AccountBalancesTool implements ChatTool
{
    use ResolvesArguments;

    #[Override]
    public function definition(): array
    {
        return [
            'name'        => $this->name(),
            'description' => 'Current balance of every asset account and liability, with totals per currency. Use for "how much do I have", "what is my balance", "how much do I owe". Optionally as of a past date.',
            'parameters'  => [
                'type'       => 'object',
                'properties' => [
                    'date' => ['type' => 'string', 'description' => 'Balance as it was at the end of this day, YYYY-MM-DD. Defaults to today.'],
                ],
                'required'   => [],
            ],
        ];
    }

    #[Override]
    public function name(): string
    {
        return 'account_balances';
    }

    #[Override]
    public function run(User $user, array $arguments): array
    {
        $date        = null === ($arguments['date'] ?? null) ? Carbon::now() : $this->range(['start' => $arguments['date'], 'end' => $arguments['date']])[1];

        /** @var AccountRepositoryInterface $repository */
        $repository  = app(AccountRepositoryInterface::class);
        $repository->setUser($user);

        $assets      = $repository->getActiveAccountsByType([AccountTypeEnum::ASSET->value]);
        $liabilities = $repository->getActiveAccountsByType([
            AccountTypeEnum::LOAN->value,
            AccountTypeEnum::DEBT->value,
            AccountTypeEnum::MORTGAGE->value,
        ]);
        $totals      = [];

        return [
            'date'        => $date->format('Y-m-d'),
            'accounts'    => $this->balances($repository, $assets, $date, $totals),
            'liabilities' => $this->balances($repository, $liabilities, $date, $totals),
            // Only asset accounts are summed: adding a mortgage to a current account produces a
            // number that means nothing, and the model would happily present it as "your money".
            'asset_totals' => array_values($totals),
            'note'        => 'Totals cover asset accounts only, per currency. Liabilities are listed separately and are negative when owed.',
        ];
    }

    /**
     * @param array<string, array<string, string>> $totals
     *
     * @return list<array<string, mixed>>
     */
    private function balances(AccountRepositoryInterface $repository, Collection $accounts, Carbon $date, array &$totals): array
    {
        $rows = [];
        foreach ($accounts as $account) {
            /** @var Account $account */
            $currency = $repository->getAccountCurrency($account) ?? Amount::getPrimaryCurrency();
            $final    = Steam::finalAccountBalance($account, $date);
            $balance  = Steam::bcround($final[$currency->code] ?? '0', $currency->decimal_places);
            $rows[]   = ['name' => $account->name, 'balance' => $balance, 'currency' => $currency->code];
            if ($account->accountType?->type === AccountTypeEnum::ASSET->value) {
                $totals[$currency->code] ??= ['currency' => $currency->code, 'total' => '0'];
                $totals[$currency->code]['total'] = bcadd($totals[$currency->code]['total'], $balance, $currency->decimal_places);
            }
        }

        return $rows;
    }
}
