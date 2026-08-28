<?php

/*
 * ExternalIdCommandsTest.php
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
use FireflyIII\Fork\Models\ForkExternalId;
use FireflyIII\Models\Transaction;
use FireflyIII\Models\TransactionGroup;
use FireflyIII\Models\TransactionJournal;
use FireflyIII\Models\TransactionJournalMeta;
use Override;
use Tests\integration\TestCase;
use Tests\integration\Traits\CreatesTransactionGroups;

/**
 * FORK: firefly-iii:fork:external-ids:backfill and firefly-iii:fork:purge-deleted-transactions.
 *
 * @internal
 *
 * @coversNothing
 */
final class ExternalIdCommandsTest extends TestCase
{
    use CreatesTransactionGroups;

    public function testBackfillReservesExistingIdsAndReportsDuplicates(): void
    {
        // history created before the feature existed: no reservations, and a real duplicate pair
        config(['fork.external_id_dedup' => false]);
        $user = $this->createAuthenticatedUser();
        $a    = $this->createWithdrawal($user, ['external_id' => 'sf-1']);
        $b    = $this->createWithdrawal($user, ['external_id' => 'sf-1', 'date' => Carbon::parse('2026-02-01', 'UTC')]);
        $this->createWithdrawal($user, ['external_id' => 'sf-2']);
        self::assertSame(0, ForkExternalId::query()->count());
        config(['fork.external_id_dedup' => true]);

        $this
            ->artisan('firefly-iii:fork:external-ids:backfill', ['--dry-run' => true])
            ->expectsOutputToContain('Would create 2 reservation(s); 0 already present; 1 collision(s).')
            ->assertExitCode(0);
        self::assertSame(0, ForkExternalId::query()->count());

        $this
            ->artisan('firefly-iii:fork:external-ids:backfill')
            ->expectsOutputToContain('1 external_id(s) exist more than once')
            ->expectsOutputToContain(sprintf('#%d (group #%d)', $b->transactionJournals()->first()->id, $b->id))
            ->expectsOutputToContain('Created 2 reservation(s); 0 already present; 1 collision(s).')
            ->assertExitCode(0);
        self::assertSame(
            (int) $a->transactionJournals()->first()->id,
            (int) ForkExternalId::query()->where('external_id', 'sf-1')->value('transaction_journal_id'),
            'the lowest journal keeps the id'
        );

        $this
            ->artisan('firefly-iii:fork:external-ids:backfill')
            ->expectsOutputToContain('Created 0 reservation(s); 2 already present; 1 collision(s).')
            ->assertExitCode(0);
    }

    public function testPurgeHardDeletesOldSoftDeletedTransactionsAndFreesTheId(): void
    {
        config(['fork.external_id_dedup' => true]);
        $user = $this->createAuthenticatedUser();
        $this->actingAs($user);
        $old    = $this->createWithdrawal($user, ['external_id' => 'sf-old', 'category_name' => 'Groceries', 'tags' => ['t1']]);
        $recent = $this->createWithdrawal($user, ['external_id' => 'sf-recent']);
        $oldId  = (int) $old->transactionJournals()->first()->id;

        Carbon::setTestNow(Carbon::parse('2026-06-01', 'UTC'));
        $this->deleteJson(route('api.v1.transactions.delete', [$old->id]))->assertNoContent();
        Carbon::setTestNow(Carbon::parse('2026-08-20', 'UTC'));
        $this->deleteJson(route('api.v1.transactions.delete', [$recent->id]))->assertNoContent();
        Carbon::setTestNow(Carbon::parse('2026-08-28', 'UTC'));

        $this
            ->artisan('firefly-iii:fork:purge-deleted-transactions', ['--older-than' => 30, '--dry-run' => true])
            ->expectsOutputToContain(sprintf('would purge journal #%d', $oldId))
            ->assertExitCode(0);
        self::assertNotNull(TransactionJournal::query()->withTrashed()->find($oldId));

        $this
            ->artisan('firefly-iii:fork:purge-deleted-transactions', ['--older-than' => 30])
            ->expectsOutputToContain('Purged 1 journal(s) and 1 empty group(s)')
            ->assertExitCode(0);

        self::assertNull(TransactionJournal::query()->withTrashed()->find($oldId));
        self::assertNull(TransactionGroup::query()->withTrashed()->find($old->id));
        self::assertSame(0, Transaction::query()->withTrashed()->where('transaction_journal_id', $oldId)->count());
        self::assertSame(0, TransactionJournalMeta::query()->withTrashed()->where('transaction_journal_id', $oldId)->count());
        self::assertSame(0, ForkExternalId::query()->where('external_id', 'sf-old')->count());
        self::assertNotNull(TransactionGroup::query()->withTrashed()->find($recent->id), 'recent deletions are kept');
        self::assertSame(1, ForkExternalId::query()->where('external_id', 'sf-recent')->count());

        // the purged id can be imported again
        $this->createWithdrawal($user, ['external_id' => 'sf-old']);
    }

    public function testReleaseOnlyKeepsRowsButFreesTheId(): void
    {
        config(['fork.external_id_dedup' => true]);
        $user = $this->createAuthenticatedUser();
        $this->actingAs($user);
        $group = $this->createWithdrawal($user, ['external_id' => 'sf-rel']);
        Carbon::setTestNow(Carbon::parse('2026-06-01', 'UTC'));
        $this->deleteJson(route('api.v1.transactions.delete', [$group->id]))->assertNoContent();
        Carbon::setTestNow(Carbon::parse('2026-08-28', 'UTC'));

        $this
            ->artisan('firefly-iii:fork:purge-deleted-transactions', ['--older-than' => 30, '--release-only' => true])
            ->expectsOutputToContain('Released 1 reservation(s).')
            ->assertExitCode(0);

        self::assertNotNull(TransactionGroup::query()->withTrashed()->find($group->id));
        self::assertSame(0, ForkExternalId::query()->count());
        $this->createWithdrawal($user, ['external_id' => 'sf-rel']);
        self::assertSame(1, ForkExternalId::query()->count());
    }

    #[Override]
    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }
}
