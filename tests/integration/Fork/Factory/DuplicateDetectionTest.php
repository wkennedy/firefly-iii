<?php

/*
 * DuplicateDetectionTest.php
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

namespace Tests\integration\Fork\Factory;

use FireflyIII\Exceptions\DuplicateTransactionException;
use FireflyIII\Models\TransactionGroup;
use FireflyIII\User;
use Tests\integration\TestCase;
use Tests\integration\Traits\CreatesTransactionGroups;

/**
 * FORK: pins how duplicate detection actually works in TransactionJournalFactory —
 * a sha256 of the WHOLE row (import_hash_v2), not the external_id. The importer's
 * error_if_duplicate_hash flag depends on this. A fork change (e.g. a unique index
 * on external_id) must update these expectations deliberately.
 *
 * @internal
 *
 * @coversNothing
 */
final class DuplicateDetectionTest extends TestCase
{
    use CreatesTransactionGroups;

    private const array ROW = ['external_id' => 'simplefin-1', 'description' => 'WHOLEFDS MKT', 'amount' => '42.00'];

    public function testApiReportsDuplicateAsValidationError(): void
    {
        $user = $this->createAuthenticatedUser();
        $this->actingAs($user);
        $payload = $this->apiPayload($user);

        $first = $this->postJson(route('api.v1.transactions.store'), $payload);
        $first->assertSuccessful();

        $second = $this->postJson(route('api.v1.transactions.store'), $payload);
        $second->assertUnprocessable();
        // Laravel flattens nested validation keys: errors["transactions.0.description"]
        $errors = $second->json('errors');
        self::assertArrayHasKey('transactions.0.description', $errors, json_encode($errors));
        self::assertStringContainsString(sprintf('Duplicate of transaction #%d.', (int) $first->json('data.id')), $errors['transactions.0.description'][0]);
    }

    public function testDuplicateOfSoftDeletedTransactionIsStillRejected(): void
    {
        // errorIfDuplicate() searches withTrashed(): deleting a transaction in the UI does
        // not free its hash (matches the "already a (deleted) transaction" importer message).
        $user = $this->createAuthenticatedUser();
        $this->actingAs($user);
        $first = $this->createWithdrawal($user, self::ROW);
        $this->deleteJson(route('api.v1.transactions.delete', [$first->id]))->assertNoContent();

        $this->expectException(DuplicateTransactionException::class);
        $this->createTransactionGroup($user, [self::ROW + ['type' => 'withdrawal']], errorIfDuplicate: true);
    }

    public function testHashIsScopedToTheUser(): void
    {
        $user  = $this->createAuthenticatedUser();
        $other = $this->createUser('other@email.com');
        $this->createWithdrawal($other, self::ROW);

        $group = $this->createTransactionGroup($user, [self::ROW + ['type' => 'withdrawal']], errorIfDuplicate: true);

        self::assertSame($user->id, (int) $group->user_id);
    }

    public function testIdenticalRowIsAcceptedWhenFlagIsOff(): void
    {
        $user = $this->createAuthenticatedUser();
        $this->createWithdrawal($user, self::ROW);
        $this->createWithdrawal($user, self::ROW);

        self::assertSame(2, TransactionGroup::query()->where('user_id', $user->id)->count());
    }

    public function testIdenticalRowIsRejectedWhenErrorOnHashIsSet(): void
    {
        $user  = $this->createAuthenticatedUser();
        $first = $this->createWithdrawal($user, self::ROW);

        $this->expectException(DuplicateTransactionException::class);
        $this->expectExceptionMessage(sprintf('Duplicate of transaction #%d.', $first->id));
        $this->createTransactionGroup($user, [self::ROW + ['type' => 'withdrawal']], errorIfDuplicate: true);
    }

    public function testSameExternalIdWithDifferentDateIsNotADuplicate(): void
    {
        // The contract the importer relies on: dedup is by row hash, so a re-delivered
        // transaction whose date (or any field) changed slips through even with the same external_id.
        $user = $this->createAuthenticatedUser();
        $this->createWithdrawal($user, self::ROW);
        $this->createTransactionGroup(
            $user,
            [self::ROW + ['type' => 'withdrawal', 'date' => \Carbon\Carbon::parse('2026-01-16 12:00:00', 'UTC')]],
            errorIfDuplicate: true
        );

        self::assertSame(2, TransactionGroup::query()->where('user_id', $user->id)->count());
    }

    /**
     * @return array<string, mixed>
     */
    private function apiPayload(User $user): array
    {
        return [
            'error_if_duplicate_hash' => true,
            'transactions'            => [[
                'type'             => 'withdrawal',
                'date'             => '2026-01-15T12:00:00+00:00',
                'amount'           => '42.00',
                'description'      => 'WHOLEFDS MKT',
                'external_id'      => 'simplefin-1',
                'currency_code'    => 'EUR',
                'source_id'        => $this->assetAccount($user, 'Checking')->id,
                'destination_name' => 'Some shop'
            ]]
        ];
    }
}
