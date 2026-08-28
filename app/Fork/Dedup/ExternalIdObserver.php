<?php

/*
 * ExternalIdObserver.php
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

use FireflyIII\Models\TransactionJournalMeta;

/**
 * FORK: mirrors journal_meta "external_id" rows into the registry. Attached in ForkServiceProvider.
 */
final class ExternalIdObserver
{
    public function __construct(
        private readonly ExternalIdRegistry $registry
    ) {}

    /**
     * Fires for soft deletes too. A journal being deleted soft-deletes its meta as well — in that
     * case the reservation must survive (deleted transactions keep blocking re-imports, as upstream
     * does). Only an explicit "clear the external_id" on a live journal releases it.
     */
    public function deleted(TransactionJournalMeta $meta): void
    {
        if ('external_id' !== $meta->name || !$this->registry->enabled()) {
            return;
        }
        $journal = $meta->transactionJournal()->withTrashed()->first();
        if (null === $journal || $journal->trashed()) {
            return;
        }
        $this->registry->release($journal);
    }

    public function saved(TransactionJournalMeta $meta): void
    {
        if ('external_id' !== $meta->name || !$this->registry->enabled()) {
            return;
        }
        $this->registry->sync($meta);
    }
}
