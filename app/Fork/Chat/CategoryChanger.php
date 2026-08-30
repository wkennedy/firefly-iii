<?php

/*
 * CategoryChanger.php
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

namespace FireflyIII\Fork\Chat;

use FireflyIII\Events\Model\TransactionGroup\TransactionGroupEventFlags;
use FireflyIII\Events\Model\TransactionGroup\TransactionGroupEventObjects;
use FireflyIII\Events\Model\TransactionGroup\UpdatedSingleTransactionGroup;
use FireflyIII\Events\Model\Webhook\WebhookMessagesRequestSending;
use FireflyIII\Models\TransactionJournal;
use FireflyIII\Repositories\TransactionGroup\TransactionGroupRepositoryInterface;
use FireflyIII\User;

/**
 * FORK: applies a confirmed category change, deliberately by the same route the API takes
 * (Api\V1\Controllers\Models\Transaction\UpdateController::update): the group repository, then the
 * same two events with the same flags.
 *
 * Doing it "more simply" through JournalUpdateService would skip the events, and skipping them
 * would mean rules do not run and no webhook fires — so the categorizer would never learn from a
 * correction made in chat, while learning from the identical correction made in the UI. A write
 * that behaves differently depending on which window it came from is the bug this class exists to
 * avoid.
 */
final class CategoryChanger
{
    public function __construct(private readonly TransactionGroupRepositoryInterface $repository) {}

    /**
     * @return array{category: null|string, overruled: bool}
     */
    public function apply(User $user, TransactionJournal $journal, string $category): array
    {
        $this->repository->setUser($user);
        $group   = $journal->transactionGroup;
        $objects = TransactionGroupEventObjects::collectFromTransactionGroup($group);
        $group   = $this->repository->update($group, [
            'transactions' => [['transaction_journal_id' => $journal->id, 'category_name' => $category]],
        ]);
        $objects->appendFromTransactionGroup($group);

        $flags                    = new TransactionGroupEventFlags();
        $flags->applyRules        = true;
        $flags->fireWebhooks      = true;
        // A category cannot change an amount, so there is nothing to recalculate.
        $flags->recalculateCredit = false;
        event(new UpdatedSingleTransactionGroup($flags, $objects));
        event(new WebhookMessagesRequestSending());

        // Rules run on update, and a rule is allowed to overrule what was just confirmed. Read back
        // what the ledger actually says rather than reporting the request as though it were the
        // outcome: this whole feature is worthless the moment an answer stops matching the data.
        $actual                   = $journal->fresh()?->categories()->first()?->name;

        return ['category' => $actual, 'overruled' => $actual !== $category];
    }
}
