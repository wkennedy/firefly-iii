<?php

/*
 * AppliesDefaultBudget.php
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
use FireflyIII\Events\Model\TransactionGroup\UpdatedSingleTransactionGroup;
use FireflyIII\Fork\Budgets\DefaultBudgets;
use FireflyIII\Models\TransactionJournal;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * FORK: applies the category's default budget when a withdrawal is created or edited — so a
 * category correction (by hand or by the categorizer) also fills in the budget, which
 * store-journal rules never did.
 */
final class AppliesDefaultBudget
{
    public function __construct(
        private readonly DefaultBudgets $defaults
    ) {}

    public function handle(CreatedSingleTransactionGroup|UpdatedSingleTransactionGroup $event): void
    {
        if (!DefaultBudgets::enabled()) {
            return;
        }

        /** @var TransactionJournal $journal */
        foreach ($event->objects->transactionJournals as $journal) {
            try {
                $this->defaults->apply($journal->fresh(['transactionType']));
            } catch (Throwable $e) {
                Log::error(sprintf('FORK default budget failed for journal #%d: %s', $journal->id, $e->getMessage()));
            }
        }
    }
}
