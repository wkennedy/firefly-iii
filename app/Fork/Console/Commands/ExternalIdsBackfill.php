<?php

/*
 * ExternalIdsBackfill.php
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
use FireflyIII\Fork\Models\ForkExternalId;
use FireflyIII\Models\TransactionJournal;
use FireflyIII\Models\TransactionJournalMeta;
use Illuminate\Console\Command;

/**
 * FORK: seeds fork_external_ids from existing journal_meta rows (including soft-deleted
 * journals, which keep blocking re-imports) and reports every external_id that already exists
 * more than once — the duplicate finder that used to be a shell one-liner in the deployment repo.
 */
final class ExternalIdsBackfill extends Command
{
    use ShowsFriendlyMessages;

    protected $description = 'FORK: reserve existing external_ids in fork_external_ids and list duplicates.';

    protected $signature = 'firefly-iii:fork:external-ids:backfill {--dry-run : Only report; reserve nothing.}';

    public function handle(): int
    {
        $dryRun     = (bool) $this->option('dry-run');
        $reserved   = 0;
        $present    = 0;
        $collisions = [];
        $planned    = []; // dry run: "user_group:external_id" => journal id, so collisions are still detected

        $query = TransactionJournalMeta::query()->withTrashed()->where('name', 'external_id')->orderBy('transaction_journal_id');
        foreach ($query->cursor() as $meta) {
            /** @var null|TransactionJournal $journal */
            $journal    = $meta->transactionJournal()->withTrashed()->first();
            $externalId = trim((string) $meta->data);
            if (null === $journal || '' === $externalId) {
                continue;
            }
            $key      = sprintf('%d:%s', (int) $journal->user_group_id, $externalId);
            $existing = ForkExternalId::query()->where('user_group_id', $journal->user_group_id)->where('external_id', $externalId)->first();
            $holderId = null !== $existing ? (int) $existing->transaction_journal_id : $planned[$key] ?? null;
            if (null !== $holderId) {
                if ($holderId === (int) $journal->id) {
                    ++$present;

                    continue;
                }
                $original     = TransactionJournal::query()->withTrashed()->find($holderId);
                $collisions[] = [
                    $externalId,
                    sprintf('#%d (group #%d%s)', $holderId, (int) $original?->transaction_group_id, $original?->trashed() ? ', deleted' : ''),
                    sprintf('#%d (group #%d%s)', $journal->id, (int) $journal->transaction_group_id, $journal->trashed() ? ', deleted' : '')
                ];

                continue;
            }
            if ($dryRun) {
                $planned[$key] = (int) $journal->id;
                ++$reserved;

                continue;
            }
            ForkExternalId::query()->create([
                'user_group_id'          => $journal->user_group_id,
                'external_id'            => $externalId,
                'transaction_journal_id' => $journal->id
            ]);
            ++$reserved;
        }

        if ([] !== $collisions) {
            $this->friendlyWarning(sprintf(
                '%d external_id(s) exist more than once. The first (lowest) journal holds the reservation; the others are duplicates to review:',
                count($collisions)
            ));
            $this->table(['external_id', 'reserved by journal', 'duplicate journal'], $collisions);
        }
        $this->friendlyPositive(sprintf(
            '%s %d reservation(s); %d already present; %d collision(s).',
            $dryRun ? 'Would create' : 'Created',
            $reserved,
            $present,
            count($collisions)
        ));

        return self::SUCCESS;
    }
}
