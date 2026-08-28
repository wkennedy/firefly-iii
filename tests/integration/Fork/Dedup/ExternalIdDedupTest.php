<?php

/*
 * ExternalIdDedupTest.php
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

namespace Tests\integration\Fork\Dedup;

use Carbon\Carbon;
use FireflyIII\Exceptions\DuplicateTransactionException;
use FireflyIII\Factory\TransactionJournalFactory;
use FireflyIII\Fork\Factory\TransactionJournalFactory as ForkFactory;
use FireflyIII\Fork\Models\ForkExternalId;
use FireflyIII\Models\TransactionGroup;
use FireflyIII\Models\TransactionJournal;
use FireflyIII\User;
use Override;
use Tests\integration\TestCase;
use Tests\integration\Traits\CreatesTransactionGroups;

/**
 * FORK: atomic external_id dedup (config fork.external_id_dedup). Contract for the importer:
 * a second transaction with an already-reserved external_id is rejected with 422 — whatever
 * the rest of the row looks like — and the rejected group leaves nothing behind.
 *
 * @internal
 *
 * @coversNothing
 */
final class ExternalIdDedupTest extends TestCase
{
    use CreatesTransactionGroups;

    public function testApiRejectsWithValidationErrorAndOriginalGroupId(): void
    {
        $user = $this->createAuthenticatedUser();
        $this->actingAs($user);
        $first = $this->postJson(route('api.v1.transactions.store'), $this->payload($user, 'sf-api', 'First delivery'));
        $first->assertSuccessful();

        $second = $this->postJson(route('api.v1.transactions.store'), $this->payload($user, 'sf-api', 'Second delivery, amount changed', '99.00'));
        $second->assertUnprocessable();
        $errors = $second->json('errors');
        self::assertStringContainsString(sprintf('Duplicate of transaction #%d', (int) $first->json('data.id')), $errors['transactions.0.description'][0]);
        self::assertSame(1, TransactionGroup::query()->where('user_id', $user->id)->count());
    }

    public function testChangingTheExternalIdMovesTheReservation(): void
    {
        $user = $this->createAuthenticatedUser();
        $this->actingAs($user);
        $group     = $this->createWithdrawal($user, ['external_id' => 'sf-old']);
        $journalId = (int) $group->transactionJournals()->first()->id;

        $this->putJson(route('api.v1.transactions.update', [$group->id]), [
            'transactions' => [['transaction_journal_id' => $journalId, 'external_id' => 'sf-new']]
        ])->assertSuccessful();

        self::assertSame(['sf-new'], ForkExternalId::query()->where('transaction_journal_id', $journalId)->pluck('external_id')->all());
        // the old id is free again
        $this->createWithdrawal($user, ['external_id' => 'sf-old']);
        self::assertSame(2, ForkExternalId::query()->count());
    }

    public function testClearingTheExternalIdReleasesIt(): void
    {
        $user = $this->createAuthenticatedUser();
        $this->actingAs($user);
        $group     = $this->createWithdrawal($user, ['external_id' => 'sf-clear']);
        $journalId = (int) $group->transactionJournals()->first()->id;

        $this->putJson(route('api.v1.transactions.update', [$group->id]), [
            'transactions' => [['transaction_journal_id' => $journalId, 'external_id' => '']]
        ])->assertSuccessful();

        self::assertSame(0, ForkExternalId::query()->count());
    }

    public function testDifferentUserGroupsDoNotCollide(): void
    {
        $user  = $this->createAuthenticatedUser();
        $other = $this->createUser('other@email.com');
        $this->createWithdrawal($other, ['external_id' => 'shared']);
        $this->createWithdrawal($user, ['external_id' => 'shared']);

        self::assertSame(2, ForkExternalId::query()->where('external_id', 'shared')->count());
    }

    public function testDisabledFlagKeepsUpstreamBehaviour(): void
    {
        config(['fork.external_id_dedup' => false]);
        $user = $this->createAuthenticatedUser();
        $this->createWithdrawal($user, ['external_id' => 'sf-off']);
        $this->createWithdrawal($user, ['external_id' => 'sf-off', 'date' => Carbon::parse('2026-02-01', 'UTC')]);

        self::assertSame(2, TransactionGroup::query()->where('user_id', $user->id)->count());
        self::assertSame(0, ForkExternalId::query()->count());
    }

    public function testFactoryBindingIsTheForkWrapper(): void
    {
        self::assertInstanceOf(ForkFactory::class, app(TransactionJournalFactory::class));
    }

    public function testSameExternalIdWithADifferentRowIsRejectedAndRolledBack(): void
    {
        $user  = $this->createAuthenticatedUser();
        $first = $this->createWithdrawal($user, ['external_id' => 'sf-1', 'description' => 'Original']);

        try {
            $this->createWithdrawal($user, [
                'external_id' => 'sf-1',
                'description' => 'Re-delivered with a new date',
                'date'        => Carbon::parse('2026-02-01', 'UTC')
            ]);
            self::fail('expected DuplicateTransactionException');
        } catch (DuplicateTransactionException $e) {
            self::assertSame(sprintf('Duplicate of transaction #%d (external_id "sf-1" already exists).', $first->id), $e->getMessage());
        }

        self::assertSame(
            1,
            TransactionGroup::query()->withTrashed()->where('user_id', $user->id)->count(),
            'the rejected group must not exist, not even soft-deleted'
        );
        self::assertSame(1, TransactionJournal::query()->withTrashed()->where('user_id', $user->id)->count());
        self::assertSame(1, ForkExternalId::query()->count());
    }

    public function testSoftDeletedTransactionKeepsItsReservation(): void
    {
        $user = $this->createAuthenticatedUser();
        $this->actingAs($user);
        $group = $this->createWithdrawal($user, ['external_id' => 'sf-del']);
        $this->deleteJson(route('api.v1.transactions.delete', [$group->id]))->assertNoContent();

        self::assertSame(1, ForkExternalId::query()->where('external_id', 'sf-del')->count());
        $this->expectException(DuplicateTransactionException::class);
        $this->createWithdrawal($user, ['external_id' => 'sf-del']);
    }

    public function testStoringReservesTheExternalId(): void
    {
        $user  = $this->createAuthenticatedUser();
        $group = $this->createWithdrawal($user, ['external_id' => 'sf-1']);

        $row = ForkExternalId::query()->where('external_id', 'sf-1')->first();
        self::assertNotNull($row);
        self::assertSame((int) $user->user_group_id, (int) $row->user_group_id);
        self::assertSame((int) $group->transactionJournals()->first()->id, (int) $row->transaction_journal_id);
    }

    #[Override]
    protected function setUp(): void
    {
        parent::setUp();
        config(['fork.external_id_dedup' => true]);
    }

    /**
     * @return array<string, mixed>
     */
    private function payload(User $user, string $externalId, string $description, string $amount = '42.00'): array
    {
        return [
            'transactions' => [[
                'type'             => 'withdrawal',
                'date'             => '2026-01-15T12:00:00+00:00',
                'amount'           => $amount,
                'description'      => $description,
                'external_id'      => $externalId,
                'currency_code'    => 'EUR',
                'source_id'        => $this->assetAccount($user, 'Checking')->id,
                'destination_name' => 'Some shop'
            ]]
        ];
    }
}
