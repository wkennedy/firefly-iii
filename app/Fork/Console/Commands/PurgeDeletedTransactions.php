<?php

/*
 * PurgeDeletedTransactions.php
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

use Carbon\Carbon;
use FireflyIII\Console\Commands\ShowsFriendlyMessages;
use FireflyIII\Fork\Dedup\ExternalIdRegistry;
use FireflyIII\Models\Attachment;
use FireflyIII\Models\Transaction;
use FireflyIII\Models\TransactionGroup;
use FireflyIII\Models\TransactionJournal;
use FireflyIII\Models\TransactionJournalLink;
use FireflyIII\Models\TransactionJournalMeta;
use FireflyIII\Repositories\Attachment\AttachmentRepositoryInterface;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * FORK: Firefly III only soft-deletes transactions, and a soft-deleted journal keeps its
 * external_id forever, so a re-import after a cleanup is blocked. This removes old soft-deleted
 * journals (and their empty groups) for good, which releases their ids; --release-only drops
 * only the reservations and leaves the rows.
 */
final class PurgeDeletedTransactions extends Command
{
    use ShowsFriendlyMessages;

    protected $description = 'FORK: permanently delete soft-deleted transactions older than N days so their external_ids can be re-imported.';

    protected $signature = 'firefly-iii:fork:purge-deleted-transactions
        {--older-than=30 : Only journals deleted at least this many days ago.}
        {--release-only : Release the external_id reservations but keep the soft-deleted rows.}
        {--dry-run : Report what would happen without changing anything.}';

    public function handle(ExternalIdRegistry $registry): int
    {
        $days        = (int) $this->option('older-than');
        $releaseOnly = (bool) $this->option('release-only');
        $dryRun      = (bool) $this->option('dry-run');
        $cutoff      = Carbon::now(config('app.timezone'))->subDays($days);

        $journals = TransactionJournal::query()->onlyTrashed()->where('deleted_at', '<', $cutoff)->orderBy('id')->get();
        $this->friendlyInfo(sprintf('%d soft-deleted journal(s) deleted before %s.', $journals->count(), $cutoff->format('Y-m-d')));

        $released = 0;
        $purged   = 0;
        foreach ($journals as $journal) {
            if ($dryRun) {
                $this->friendlyLine(sprintf(
                    '  would %s journal #%d (group #%d, deleted %s)',
                    $releaseOnly ? 'release' : 'purge',
                    $journal->id,
                    (int) $journal->transaction_group_id,
                    $journal->deleted_at?->format('Y-m-d')
                ));

                continue;
            }
            if ($releaseOnly) {
                $released += $registry->release($journal);

                continue;
            }
            $this->hardDelete($journal, $registry);
            ++$purged;
        }

        $groups = 0;
        if (!$dryRun && !$releaseOnly) {
            foreach (TransactionGroup::query()->onlyTrashed()->where('deleted_at', '<', $cutoff)->get() as $group) {
                if (0 === $group->transactionJournals()->withTrashed()->count()) {
                    $group->forceDelete();
                    ++$groups;
                }
            }
        }

        if ($dryRun) {
            $this->friendlyNeutral('Dry run: nothing changed.');

            return self::SUCCESS;
        }
        $this->friendlyPositive(
            $releaseOnly
                ? sprintf('Released %d reservation(s).', $released)
                : sprintf('Purged %d journal(s) and %d empty group(s); their external_ids can be imported again.', $purged, $groups)
        );

        return self::SUCCESS;
    }

    private function hardDelete(TransactionJournal $journal, ExternalIdRegistry $registry): void
    {
        DB::transaction(function () use ($journal, $registry): void {
            $registry->release($journal); // explicit: not every database enforces the FK cascade (sqlite)
            /** @var AttachmentRepositoryInterface $attachments */
            $attachments = app(AttachmentRepositoryInterface::class);
            $attachments->setUser($journal->user);
            foreach (Attachment::query()
                ->withTrashed()
                ->where('attachable_type', TransactionJournal::class)
                ->where('attachable_id', $journal->id)
                ->get() as $attachment) {
                $attachments->destroy($attachment);
                $attachment->forceDelete();
            }
            Transaction::query()->withTrashed()->where('transaction_journal_id', $journal->id)->forceDelete();
            TransactionJournalMeta::query()->withTrashed()->where('transaction_journal_id', $journal->id)->forceDelete();
            TransactionJournalLink::query()->where('source_id', $journal->id)->orWhere('destination_id', $journal->id)->delete();
            foreach (['budget_transaction_journal', 'category_transaction_journal', 'tag_transaction_journal'] as $pivot) {
                DB::table($pivot)->where('transaction_journal_id', $journal->id)->delete();
            }
            DB::table('notes')->where('noteable_type', TransactionJournal::class)->where('noteable_id', $journal->id)->delete();
            DB::table('locations')->where('locatable_type', TransactionJournal::class)->where('locatable_id', $journal->id)->delete();
            DB::table('audit_log_entries')->where('auditable_type', TransactionJournal::class)->where('auditable_id', $journal->id)->delete();
            DB::table('piggy_bank_events')->where('transaction_journal_id', $journal->id)->update(['transaction_journal_id' => null]);
            $journal->forceDelete();
        });
    }
}
