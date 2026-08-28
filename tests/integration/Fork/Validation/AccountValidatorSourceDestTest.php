<?php

/*
 * AccountValidatorSourceDestTest.php
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

namespace Tests\integration\Fork\Validation;

use FireflyIII\Enums\AccountTypeEnum;
use FireflyIII\Enums\TransactionTypeEnum;
use FireflyIII\Models\Account;
use FireflyIII\User;
use FireflyIII\Validation\AccountValidator;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\integration\TestCase;
use Tests\integration\Traits\CreatesTransactionGroups;

/**
 * FORK: AccountValidator is what rejects the importer's payloads and what the rule
 * engine's account changes are checked against. Two layers:
 *  - the full matrix must agree with config('firefly.source_dests');
 *  - a handful of pairs the pipeline depends on are pinned explicitly, so an upstream
 *    config change (or our own relaxation for asset→liability transfers) is noticed.
 *
 * @internal
 *
 * @coversNothing
 */
final class AccountValidatorSourceDestTest extends TestCase
{
    use CreatesTransactionGroups;

    private const array TYPES = [
        AccountTypeEnum::ASSET,
        AccountTypeEnum::LOAN,
        AccountTypeEnum::DEBT,
        AccountTypeEnum::MORTGAGE,
        AccountTypeEnum::EXPENSE,
        AccountTypeEnum::REVENUE,
        AccountTypeEnum::CASH
    ];

    /**
     * @return iterable<string, array{TransactionTypeEnum, AccountTypeEnum, AccountTypeEnum}>
     */
    public static function matrix(): iterable
    {
        foreach ([TransactionTypeEnum::WITHDRAWAL, TransactionTypeEnum::DEPOSIT, TransactionTypeEnum::TRANSFER] as $type) {
            foreach (self::TYPES as $source) {
                foreach (self::TYPES as $destination) {
                    yield sprintf('%s: %s → %s', $type->value, $source->value, $destination->value) => [$type, $source, $destination];
                }
            }
        }
    }

    /**
     * @return iterable<string, array{TransactionTypeEnum, AccountTypeEnum, AccountTypeEnum, bool}>
     */
    public static function pinned(): iterable
    {
        yield 'withdrawal asset → expense (normal spend)' => [TransactionTypeEnum::WITHDRAWAL, AccountTypeEnum::ASSET, AccountTypeEnum::EXPENSE, true];
        yield 'withdrawal asset → loan (loan payment stays a withdrawal)' => [
            TransactionTypeEnum::WITHDRAWAL,
            AccountTypeEnum::ASSET,
            AccountTypeEnum::LOAN,
            true
        ];
        yield 'withdrawal asset → mortgage' => [TransactionTypeEnum::WITHDRAWAL, AccountTypeEnum::ASSET, AccountTypeEnum::MORTGAGE, true];
        yield 'withdrawal asset → asset is not a withdrawal' => [TransactionTypeEnum::WITHDRAWAL, AccountTypeEnum::ASSET, AccountTypeEnum::ASSET, false];
        yield 'deposit revenue → asset (income)' => [TransactionTypeEnum::DEPOSIT, AccountTypeEnum::REVENUE, AccountTypeEnum::ASSET, true];
        yield 'deposit loan → asset (loan disbursement)' => [TransactionTypeEnum::DEPOSIT, AccountTypeEnum::LOAN, AccountTypeEnum::ASSET, true];
        yield 'transfer asset → asset (card payment after convert_transfer)' => [
            TransactionTypeEnum::TRANSFER,
            AccountTypeEnum::ASSET,
            AccountTypeEnum::ASSET,
            true
        ];
        yield 'transfer asset → loan is REJECTED upstream' => [TransactionTypeEnum::TRANSFER, AccountTypeEnum::ASSET, AccountTypeEnum::LOAN, false];
        yield 'transfer asset → mortgage is REJECTED upstream' => [TransactionTypeEnum::TRANSFER, AccountTypeEnum::ASSET, AccountTypeEnum::MORTGAGE, false];
        yield 'transfer loan → asset is REJECTED upstream' => [TransactionTypeEnum::TRANSFER, AccountTypeEnum::LOAN, AccountTypeEnum::ASSET, false];
        yield 'transfer loan → debt (liability to liability)' => [TransactionTypeEnum::TRANSFER, AccountTypeEnum::LOAN, AccountTypeEnum::DEBT, true];
    }

    public function testDestinationCannotBeValidatedBeforeSource(): void
    {
        $user      = $this->createAuthenticatedUser();
        $validator = $this->validator($user, TransactionTypeEnum::WITHDRAWAL);

        self::assertFalse($validator->validateDestination(['id' => null, 'name' => 'Shop']));
    }

    #[DataProvider('matrix')]
    public function testExistingAccountPairsFollowSourceDestsConfig(TransactionTypeEnum $type, AccountTypeEnum $source, AccountTypeEnum $destination): void
    {
        $user     = $this->createAuthenticatedUser();
        $config   = config('firefly.source_dests');
        $expected = in_array($destination->value, $config[$type->value][$source->value] ?? [], true);

        self::assertSame($expected, $this->validatePair(
            $user,
            $type,
            $this->createAccount($user, $source, 'Src'),
            $this->createAccount($user, $destination, 'Dst')
        ));
    }

    #[DataProvider('pinned')]
    public function testPinnedPairs(TransactionTypeEnum $type, AccountTypeEnum $source, AccountTypeEnum $destination, bool $expected): void
    {
        $user = $this->createAuthenticatedUser();

        self::assertSame($expected, $this->validatePair(
            $user,
            $type,
            $this->createAccount($user, $source, 'Src'),
            $this->createAccount($user, $destination, 'Dst')
        ));
    }

    public function testTransferToTheSameAccountIsRejected(): void
    {
        $user      = $this->createAuthenticatedUser();
        $checking  = $this->createAccount($user, AccountTypeEnum::ASSET, 'Checking');
        $validator = $this->validator($user, TransactionTypeEnum::TRANSFER);

        self::assertTrue($validator->validateSource(['id' => $checking->id]));
        self::assertFalse($validator->validateDestination(['id' => $checking->id]));
        self::assertSame('Source and destination are the same.', $validator->destError);
    }

    public function testUnknownNamesCreateOnlyExpenseAndRevenueAccounts(): void
    {
        $user     = $this->createAuthenticatedUser();
        $checking = $this->createAccount($user, AccountTypeEnum::ASSET, 'Checking');

        // withdrawal → unknown destination name: fine, an expense account will be created
        $validator = $this->validator($user, TransactionTypeEnum::WITHDRAWAL);
        self::assertTrue($validator->validateSource(['id' => $checking->id]));
        self::assertTrue($validator->validateDestination(['id' => null, 'name' => 'Brand new shop']));

        // deposit ← unknown source name: fine, a revenue account will be created
        $validator = $this->validator($user, TransactionTypeEnum::DEPOSIT);
        self::assertTrue($validator->validateSource(['id' => null, 'name' => 'Brand new employer']));
        self::assertTrue($validator->validateDestination(['id' => $checking->id]));

        // transfer → unknown destination name: NOT fine, asset accounts are never auto-created
        $validator = $this->validator($user, TransactionTypeEnum::TRANSFER);
        self::assertTrue($validator->validateSource(['id' => $checking->id]));
        self::assertFalse($validator->validateDestination(['id' => null, 'name' => 'Brand new savings']));
        self::assertNotSame('No error yet.', $validator->destError);
    }

    private function validatePair(User $user, TransactionTypeEnum $type, Account $source, Account $destination): bool
    {
        $validator = $this->validator($user, $type);

        return (
            $validator->validateSource(['id' => $source->id, 'name' => null, 'iban' => null, 'number' => null])
            && $validator->validateDestination(['id' => $destination->id, 'name' => null, 'iban' => null, 'number' => null])
        );
    }

    private function validator(User $user, TransactionTypeEnum $type): AccountValidator
    {
        $validator = new AccountValidator();
        $validator->setUser($user);
        $validator->setUserGroup($user->userGroup);
        $validator->setTransactionType($type->value);

        return $validator;
    }
}
