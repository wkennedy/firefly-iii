<?php

/*
 * DevDataSeeder.php
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

namespace FireflyIII\Fork\Dev;

use Carbon\Carbon;
use FireflyIII\Enums\AutoBudgetType;
use FireflyIII\Factory\AccountFactory;
use FireflyIII\Factory\TransactionGroupFactory;
use FireflyIII\Models\Account;
use FireflyIII\Models\AutoBudget;
use FireflyIII\Models\Budget;
use FireflyIII\Models\Category;
use FireflyIII\Models\GroupMembership;
use FireflyIII\Models\TransactionCurrency;
use FireflyIII\Models\TransactionJournal;
use FireflyIII\Models\UserGroup;
use FireflyIII\Models\UserRole;
use FireflyIII\User;
use Illuminate\Support\Facades\Hash;

/**
 * FORK: deterministic synthetic data shaped like a real bank feed — both legs of every card and
 * loan payment, Amazon payee fragments, uncategorised rows, external_ids — so every fork feature
 * has something to act on. Never meant for production databases.
 */
final class DevDataSeeder
{
    public const string EMAIL    = 'dev@example.invalid';
    public const string PASSWORD = 'devpassword';

    private int $sequence = 0;

    public function hasData(User $user): bool
    {
        return TransactionJournal::query()->where('user_id', $user->id)->exists();
    }

    /**
     * @return array{user: string, accounts: int, journals: int, months: int, start: string, end: string}
     */
    public function seed(null|User $user, int $months, Carbon $today): array
    {
        mt_srand(20_260_828);
        $user              ??= $this->devUser();
        $currency          = TransactionCurrency::query()->where('code', 'USD')->firstOrFail();
        $currency->enabled = true;
        $currency->save();
        $accounts = $this->accounts($user, $currency);
        $this->budgetsAndCategories($user, $currency);

        $end      = $today->clone()->endOfDay();
        $start    = $today->clone()->startOfMonth()->subMonths($months)->startOfDay();
        $journals = 0;
        for ($cursor = $start->clone(); $cursor->lessThanOrEqualTo($end); $cursor->addMonth()) {
            $journals += $this->month($user, $accounts, $cursor, $end);
        }

        return [
            'user'     => $user->email,
            'accounts' => count($accounts),
            'journals' => $journals,
            'months'   => $months,
            'start'    => $start->format('Y-m-d'),
            'end'      => $end->format('Y-m-d')
        ];
    }

    /**
     * @return array<string, Account>
     */
    private function accounts(User $user, TransactionCurrency $currency): array
    {
        /** @var AccountFactory $factory */
        $factory = app(AccountFactory::class);
        $factory->setUser($user);
        $defs = [
            'checking'   => ['Dev Checking', 'asset', 'defaultAsset'],
            'savings'    => ['Dev Savings', 'asset', 'savingAsset'],
            'visa'       => ['Dev Visa', 'asset', 'ccAsset'],
            'mastercard' => ['Dev Mastercard', 'asset', 'ccAsset'],
            'carloan'    => ['Dev Car Loan', 'loan', null],
            'mortgage'   => ['Dev Mortgage', 'mortgage', null]
        ];
        $out = [];
        foreach ($defs as $key => [$name, $type, $role]) {
            $existing = Account::query()->where('user_id', $user->id)->where('name', $name)->first();
            if (null !== $existing) {
                $out[$key] = $existing;

                continue;
            }
            $data = [
                'name'              => $name,
                'account_type_name' => $type,
                'account_type_id'   => null,
                'virtual_balance'   => '0',
                'active'            => true,
                'iban'              => null,
                'currency_id'       => $currency->id
            ];
            if (null !== $role) {
                $data['account_role'] = $role;
            }
            if ('ccAsset' === $role) {
                $data['cc_type']                 = 'monthlyFull';
                $data['cc_monthly_payment_date'] = '2026-01-15';
            }
            if (in_array($type, ['loan', 'mortgage'], true)) {
                $data['liability_direction'] = 'debit';
                $data['interest']            = '0';
                $data['interest_period']     = 'monthly';
            }
            $out[$key] = $factory->create($data);
        }

        return $out;
    }

    private function amount(int $min, int $max): string
    {
        return sprintf('%d.%02d', mt_rand($min, $max), mt_rand(0, 99));
    }

    private function budgetsAndCategories(User $user, TransactionCurrency $currency): void
    {
        foreach (['Groceries', 'Dining', 'Fuel', 'Utilities & Bills', 'Subscriptions', 'Salary', 'Debt Repayment', 'Shopping'] as $name) {
            Category::query()->firstOrCreate(['user_group_id' => $user->user_group_id, 'name' => $name], ['user_id' => $user->id]);
        }
        foreach (['Groceries' => '600', 'Dining' => '250', 'Fuel' => '150'] as $name => $amount) {
            $budget = Budget::query()->firstOrCreate(['user_group_id' => $user->user_group_id, 'name' => $name], [
                'user_id' => $user->id,
                'active'  => true,
                'order'   => 1
            ]);
            if (null === AutoBudget::query()->where('budget_id', $budget->id)->first()) {
                $auto                          = new AutoBudget(['budget_id' => $budget->id, 'amount' => $amount, 'period' => 'monthly']);
                $auto->transaction_currency_id = $currency->id;
                $auto->auto_budget_type        = AutoBudgetType::AUTO_BUDGET_RESET->value;
                $auto->save();
            }
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function deposit(Account $destination, string $description, string $payer, string $amount, Carbon $date, null|string $category): array
    {
        return $this->row(['type' => 'deposit', 'source_name' => $payer, 'destination_id' => $destination->id], $description, $amount, $date, $category);
    }

    private function devUser(): User
    {
        $existing = User::query()->where('email', self::EMAIL)->first();
        if (null !== $existing) {
            return $existing;
        }
        $group = UserGroup::query()->create(['title' => self::EMAIL]);
        $user  = User::query()->create(['email' => self::EMAIL, 'password' => Hash::make(self::PASSWORD), 'user_group_id' => $group->id]);
        $role  = UserRole::query()->where('title', 'owner')->firstOrFail();
        GroupMembership::query()->create(['user_id' => $user->id, 'user_group_id' => $group->id, 'user_role_id' => $role->id]);

        return $user;
    }

    /**
     * @param array<string, Account> $accounts
     */
    private function month(User $user, array $accounts, Carbon $monthStart, Carbon $end): int
    {
        $count = 0;
        $day   = fn(int $d): Carbon => $monthStart->clone()->day(min($d, $monthStart->daysInMonth))->setTime(12, 0);
        $rows  = [];

        // income on the 1st and 15th
        $rows[] = $this->deposit($accounts['checking'], 'ACME CORP PAYROLL', 'Acme Corp Payroll', '2600.00', $day(1), 'Salary');
        $rows[] = $this->deposit($accounts['checking'], 'ACME CORP PAYROLL', 'Acme Corp Payroll', '2600.00', $day(15), 'Salary');

        // groceries (some uncategorised), dining, fuel, utilities, subscriptions — spread over the month
        foreach ([3, 7, 12, 18, 24, 27] as $i => $d) {
            $rows[] = $this->withdrawal(
                $accounts['visa'],
                sprintf('WHOLEFDS MKT #%d', 10_000 + $d),
                'WHOLEFDS MKT',
                $this->amount(45, 140),
                $day($d),
                0 === ($i % 3) ? null : 'Groceries'
            );
        }
        foreach ([2, 9, 16, 23] as $d) {
            $rows[] = $this->withdrawal(
                $accounts['visa'],
                sprintf('AMAZON MKTPL*%s', strtoupper(substr(md5((string) mt_rand()), 0, 8))),
                sprintf('AMAZON MKTPL*%s', strtoupper(substr(md5((string) mt_rand()), 0, 8))),
                $this->amount(12, 90),
                $day($d),
                null
            );
        }
        foreach ([5, 13, 20, 26] as $i => $d) {
            $rows[] = $this->withdrawal(
                $accounts['mastercard'],
                0 === ($i % 2) ? 'SQ *COFFEE PLACE' : 'DOORDASH*ORDER',
                0 === ($i % 2) ? 'SQ *COFFEE PLACE' : 'DOORDASH',
                $this->amount(8, 60),
                $day($d),
                'Dining'
            );
        }
        foreach ([6, 21] as $d) {
            $rows[] = $this->withdrawal($accounts['checking'], 'SHELL OIL 1234', 'Shell', $this->amount(40, 75), $day($d), 'Fuel');
        }
        $rows[] = $this->withdrawal(
            $accounts['checking'],
            'CITY POWER & LIGHT AUTOPAY',
            'City Power & Light',
            $this->amount(90, 180),
            $day(10),
            'Utilities & Bills'
        );
        $rows[] = $this->withdrawal($accounts['checking'], 'NETFLIX.COM', 'Netflix', '15.99', $day(11), 'Subscriptions');

        // card payments: the feed reports BOTH legs — a withdrawal from checking to an expense
        // account, and a deposit from a revenue account into the card. Same amount, a day apart.
        foreach (['visa' => ['VISA AUTOPAY PAYMENT', '450.00'], 'mastercard' => ['MASTERCARD ONLINE PMT', '300.00']] as $card => [$description, $amount]) {
            $rows[] = $this->withdrawal($accounts['checking'], $description, 'Card Payment', $amount, $day(15), null);
            $rows[] = $this->deposit($accounts[$card], 'PAYMENT THANK YOU', 'Card Issuer', $amount, $day(16), null);
        }
        // loan payments: withdrawal retargeted at the liability (what the old rules did) + mirror leg
        foreach (['carloan' => ['LIGHTNING LOAN PMTS', '400.00'], 'mortgage' => ['BIG BANK MORTGAGE LOAN', '1800.00']] as $loan => [$description, $amount]) {
            $rows[] = $this->withdrawalTo($accounts['checking'], $accounts[$loan], $description, $amount, $day(4), 'Debt Repayment');
            $rows[] = $this->deposit($accounts[$loan], 'PAYMENT RECEIVED', 'Loan Servicer', $amount, $day(5), null);
        }
        // a transfer to savings
        $rows[] = $this->transfer($accounts['checking'], $accounts['savings'], 'MONTHLY SAVINGS', '500.00', $day(2));

        /** @var TransactionGroupFactory $factory */
        $factory = app(TransactionGroupFactory::class);
        $factory->setUser($user);
        $factory->setUserGroup($user->userGroup);
        foreach ($rows as $row) {
            if ($row['date']->greaterThan($end)) {
                continue;
            }
            $factory->create([
                'user'                    => $user,
                'user_group'              => $user->userGroup,
                'group_title'             => null,
                'error_if_duplicate_hash' => false,
                'transactions'            => [$row]
            ]);
            ++$count;
        }

        return $count;
    }

    /**
     * @param array<string, mixed> $legs
     *
     * @return array<string, mixed>
     */
    private function row(array $legs, string $description, string $amount, Carbon $date, null|string $category): array
    {
        ++$this->sequence;
        $row = $legs
        + [
            'date'          => $date,
            'amount'        => $amount,
            'description'   => $description,
            'currency_code' => 'USD',
            'external_id'   => sprintf('dev-%s-%04d', $date->format('Ym'), $this->sequence)
        ];
        if (null !== $category) {
            $row['category_name'] = $category;
        }

        return $row;
    }

    /**
     * @return array<string, mixed>
     */
    private function transfer(Account $source, Account $destination, string $description, string $amount, Carbon $date): array
    {
        return $this->row(['type' => 'transfer', 'source_id' => $source->id, 'destination_id' => $destination->id], $description, $amount, $date, null);
    }

    /**
     * @return array<string, mixed>
     */
    private function withdrawal(Account $source, string $description, string $payee, string $amount, Carbon $date, null|string $category): array
    {
        return $this->row(['type' => 'withdrawal', 'source_id' => $source->id, 'destination_name' => $payee], $description, $amount, $date, $category);
    }

    /**
     * @return array<string, mixed>
     */
    private function withdrawalTo(Account $source, Account $destination, string $description, string $amount, Carbon $date, null|string $category): array
    {
        return $this->row(['type' => 'withdrawal', 'source_id' => $source->id, 'destination_id' => $destination->id], $description, $amount, $date, $category);
    }
}
