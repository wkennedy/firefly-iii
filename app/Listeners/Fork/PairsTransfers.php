<?php

/*
 * PairsTransfers.php
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

namespace FireflyIII\Listeners\Fork;

use FireflyIII\Events\Model\TransactionGroup\CreatedSingleTransactionGroup;
use FireflyIII\Fork\Transfers\TransferPairer;
use FireflyIII\Models\TransactionJournal;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * FORK: tries to pair every newly stored withdrawal/deposit with its opposite leg. Runs inline;
 * order relative to upstream's (queued) listener is irrelevant because pairing is idempotent and
 * the daily sweep catches anything missed. Never lets an exception escape into the store path.
 */
final class PairsTransfers
{
    public function __construct(
        private readonly TransferPairer $pairer
    ) {}

    public function handle(CreatedSingleTransactionGroup $event): void
    {
        if (!TransferPairer::enabled()) {
            return;
        }

        /** @var TransactionJournal $journal */
        foreach ($event->objects->transactionJournals as $journal) {
            try {
                $result = $this->pairer->pairJournal($journal->fresh(['transactions.account.accountType', 'transactionType', 'transactionGroup']));
                Log::debug(sprintf('FORK transfer pairing for journal #%d: %s (%s)', $journal->id, $result->status, $result->message));
            } catch (Throwable $e) {
                Log::error(sprintf('FORK transfer pairing failed for journal #%d: %s', $journal->id, $e->getMessage()));
            }
        }
    }
}
