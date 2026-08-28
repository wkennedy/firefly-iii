<?php

/*
 * CleansPayeeDescriptions.php
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
use FireflyIII\Fork\Models\ForkPayeeAlias;
use FireflyIII\Fork\Payees\PayeeAliaser;
use FireflyIII\Models\Transaction;
use FireflyIII\Models\TransactionJournal;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * FORK: for aliases with clean_description, rewrites a new journal's description to the canonical
 * payee — but only while the description is still the raw bank string (it matches the alias
 * pattern). A description someone typed by hand never matches and is never touched.
 */
final class CleansPayeeDescriptions
{
    public function handle(CreatedSingleTransactionGroup $event): void
    {
        if (!PayeeAliaser::enabled()) {
            return;
        }

        /** @var TransactionJournal $journal */
        foreach ($event->objects->transactionJournals as $journal) {
            try {
                $this->clean($journal->fresh(['transactions.account.accountType']));
            } catch (Throwable $e) {
                Log::error(sprintf('FORK payee description cleaning failed for journal #%d: %s', $journal->id, $e->getMessage()));
            }
        }
    }

    private function clean(TransactionJournal $journal): void
    {
        $description = (string) $journal->description;
        foreach ($journal->transactions as $transaction) {
            /** @var Transaction $transaction */
            $account = $transaction->account;
            if (!in_array($account->accountType->type, PayeeAliaser::ALIASABLE_TYPES, true)) {
                continue;
            }
            $aliases = ForkPayeeAlias::query()
                ->where('user_group_id', $journal->user_group_id)
                ->where('account_type', $account->accountType->type)
                ->where('active', true)
                ->where('clean_description', true)
                ->where('canonical_name', $account->name)
                ->orderBy('order')
                ->orderBy('id')
                ->get();
            foreach ($aliases as $alias) {
                if ($alias->matches($description) && $description !== (string) $alias->canonical_name) {
                    $journal->description = (string) $alias->canonical_name;
                    $journal->save();
                    Log::debug(sprintf('FORK payee alias #%d cleaned description of journal #%d.', $alias->id, $journal->id));

                    return;
                }
            }
        }
    }
}
