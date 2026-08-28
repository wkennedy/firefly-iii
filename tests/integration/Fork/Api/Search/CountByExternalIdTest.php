<?php

/*
 * CountByExternalIdTest.php
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

namespace Tests\integration\Fork\Api\Search;

use FireflyIII\Models\TransactionJournalMeta;
use Tests\integration\TestCase;
use Tests\integration\Traits\CreatesTransactionGroups;

/**
 * FORK: the importer's dedup check is GET /api/v1/search/transactions/count?external_identifier=…
 * (JournalRepository::countByMeta). These pin the behaviour it relies on.
 *
 * @internal
 *
 * @coversNothing
 */
final class CountByExternalIdTest extends TestCase
{
    use CreatesTransactionGroups;

    public function testCountsAreScopedToTheAuthenticatedUser(): void
    {
        $user  = $this->createAuthenticatedUser();
        $other = $this->createUser('other@email.com');
        $this->createWithdrawal($other, ['external_id' => 'shared-id']);
        $this->createWithdrawal($user, ['external_id' => 'shared-id']);

        $this->actingAs($user);
        $this->countByExternalId('shared-id')->assertExactJson(['count' => 1]);
    }

    public function testCountsLiveTransactionByExternalId(): void
    {
        $user = $this->createAuthenticatedUser();
        $this->actingAs($user);
        $this->createWithdrawal($user, ['external_id' => 'simplefin-abc-123']);

        $this->countByExternalId('simplefin-abc-123')->assertOk()->assertExactJson(['count' => 1]);
        $this->countByExternalId('simplefin-unknown')->assertOk()->assertExactJson(['count' => 0]);
    }

    public function testExternalIdWithQuotesAndUnicodeMatches(): void
    {
        $user = $this->createAuthenticatedUser();
        $this->actingAs($user);
        $externalId = 'Café "Zürich" #1 / ÄÖÜ';
        $this->createWithdrawal($user, ['external_id' => $externalId]);

        self::assertSame(1, TransactionJournalMeta::query()->where('name', 'external_id')->count());
        $this->countByExternalId($externalId)->assertOk()->assertExactJson(['count' => 1]);
    }

    public function testSoftDeletedTransactionIsHiddenUnlessIncludeDeleted(): void
    {
        $user = $this->createAuthenticatedUser();
        $this->actingAs($user);
        $group   = $this->createWithdrawal($user, ['external_id' => 'simplefin-deleted']);
        $journal = $group->transactionJournals()->first();

        $this->deleteJson(route('api.v1.transactions.delete', [$group->id]))->assertNoContent();

        // journal + meta are soft-deleted together (DeletedTransactionJournalObserver)
        $meta = $this->journalMeta($journal, 'external_id');
        self::assertNotNull($meta);
        self::assertNotNull($meta->deleted_at);
        self::assertSame('simplefin-deleted', $meta->data);

        $this->countByExternalId('simplefin-deleted')->assertExactJson(['count' => 0]);
        $this->countByExternalId('simplefin-deleted', includeDeleted: true)->assertExactJson(['count' => 1]);
    }

    private function countByExternalId(string $externalId, bool $includeDeleted = false): \Illuminate\Testing\TestResponse
    {
        $query = ['external_identifier' => $externalId, 'include_deleted' => $includeDeleted ? 'true' : 'false'];

        return $this->getJson(route('api.v1.search.count') . '?' . http_build_query($query));
    }
}
