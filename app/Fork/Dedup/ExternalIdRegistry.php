<?php

/*
 * ExternalIdRegistry.php
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

namespace FireflyIII\Fork\Dedup;

use FireflyIII\Exceptions\DuplicateTransactionException;
use FireflyIII\Fork\Models\ForkExternalId;
use FireflyIII\Models\TransactionJournal;
use FireflyIII\Models\TransactionJournalMeta;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * FORK: keeps fork_external_ids in step with journal_meta rows named "external_id".
 *
 * The unique index on (user_group_id, external_id) is the guarantee: when two imports race,
 * the database lets exactly one reservation through and the other caller receives a
 * DuplicateTransactionException — which the API already turns into a 422 and the importer
 * already understands. Soft-deleting a journal keeps its reservation (matching upstream's
 * "already a (deleted) transaction" behaviour); hard-deleting releases it (FK cascade).
 */
final class ExternalIdRegistry
{
    public function enabled(): bool
    {
        return (bool) config('fork.external_id_dedup');
    }

    public function find(int $userGroupId, string $externalId): null|ForkExternalId
    {
        return ForkExternalId::query()->where('user_group_id', $userGroupId)->where('external_id', $externalId)->first();
    }

    public function release(TransactionJournal $journal): int
    {
        return ForkExternalId::query()->where('transaction_journal_id', $journal->id)->delete();
    }

    /**
     * @throws DuplicateTransactionException
     */
    public function reserve(TransactionJournal $journal, string $externalId): void
    {
        $userGroupId = (int) $journal->user_group_id;
        $existing    = $this->find($userGroupId, $externalId);
        if (null !== $existing) {
            if ((int) $existing->transaction_journal_id === (int) $journal->id) {
                return; // already ours
            }

            throw $this->duplicateOf($existing, $externalId);
        }

        try {
            // Nested transaction = savepoint: a unique violation then leaves the caller's
            // transaction usable (PostgreSQL would otherwise abort it), so upstream's own
            // cleanup can still run before the outer rollback.
            DB::transaction(static function () use ($userGroupId, $externalId, $journal): void {
                ForkExternalId::query()->create([
                    'user_group_id'          => $userGroupId,
                    'external_id'            => $externalId,
                    'transaction_journal_id' => $journal->id
                ]);
            });
        } catch (UniqueConstraintViolationException) {
            // lost the race between find() and create(): whoever won is the original.
            Log::warning(sprintf('FORK: external_id "%s" was reserved concurrently for user group #%d.', $externalId, $userGroupId));

            throw $this->duplicateOf($this->find($userGroupId, $externalId), $externalId);
        }
    }

    /**
     * Make the registry reflect this meta row: at most one reservation per journal.
     *
     * @throws DuplicateTransactionException
     */
    public function sync(TransactionJournalMeta $meta): void
    {
        /** @var null|TransactionJournal $journal */
        $journal = $meta->transactionJournal()->withTrashed()->first();
        if (null === $journal) {
            return;
        }
        $externalId = trim((string) $meta->data);

        // the journal's id changed (or was cleared): drop what no longer applies.
        ForkExternalId::query()->where('transaction_journal_id', $journal->id)->where('external_id', '!=', $externalId)->delete();
        if ('' === $externalId) {
            return;
        }
        $this->reserve($journal, $externalId);
    }

    private function duplicateOf(null|ForkExternalId $existing, string $externalId): DuplicateTransactionException
    {
        $groupId = null;
        if (null !== $existing) {
            $groupId = TransactionJournal::query()->withTrashed()->find($existing->transaction_journal_id)?->transaction_group_id;
        }
        if (null !== $groupId) {
            return new DuplicateTransactionException(sprintf('Duplicate of transaction #%d (external_id "%s" already exists).', (int) $groupId, $externalId));
        }

        return new DuplicateTransactionException(sprintf('Duplicate transaction (external_id "%s" already exists).', $externalId));
    }
}
