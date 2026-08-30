<?php

/*
 * ProposeCategoryChangeTool.php
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

namespace FireflyIII\Fork\Chat\Tools;

use FireflyIII\Fork\Chat\ProposalStore;
use FireflyIII\Fork\Chat\ToolException;
use FireflyIII\Models\Category;
use FireflyIII\Models\TransactionJournal;
use FireflyIII\Repositories\Category\CategoryRepositoryInterface;
use FireflyIII\Support\Facades\Steam;
use FireflyIII\User;
use Override;

/**
 * FORK: chat tool — propose a category change (config fork.chat_writes, off by default).
 *
 * The only tool in the registry that leads to a write, and it does not perform one. It puts a
 * proposal in the session and returns a description of it; the change happens when the person
 * clicks the confirmation card, on a route the model has no way to call. Everything the model can
 * get wrong here — the wrong transaction, the wrong category, a category that does not exist —
 * fails in front of the person before anything is written.
 */
final class ProposeCategoryChangeTool implements ChatTool
{
    use FormatsMoney;

    public function __construct(
        private readonly ProposalStore $proposals
    ) {}

    #[Override]
    public function definition(): array
    {
        return [
            'name'        => $this->name(),
            'description' => 'Propose changing the category of ONE transaction, by its id from search_transactions. This does not change anything by itself: it shows the person a confirmation card, and only their click applies it. Say that you have prepared the change and that they need to confirm it - never say it is done.',
            'parameters'  => [
                'type'       => 'object',
                'properties' => [
                    'transaction_id' => ['type' => 'integer', 'description' => 'The "id" field of a transaction returned by search_transactions.'],
                    'category'       => ['type' => 'string', 'description' => 'The exact name of an existing category (see list_categories).']
                ],
                'required'   => ['transaction_id', 'category']
            ]
        ];
    }

    #[Override]
    public function name(): string
    {
        return 'propose_category_change';
    }

    #[Override]
    public function run(User $user, array $arguments): array
    {
        $id     = (int) ($arguments['transaction_id'] ?? 0);
        $wanted = trim((string) ($arguments['category'] ?? ''));
        if (0 === $id) {
            throw new ToolException('"transaction_id" is required; take it from the "id" field of a search_transactions row.');
        }
        if ('' === $wanted) {
            throw new ToolException('"category" is required.');
        }

        // Scoped to this user's own journals: an id the model invented, or one belonging to someone
        // else, is simply not found.
        $journal = TransactionJournal::query()->where('id', $id)->where('user_id', $user->id)->first();
        if (!$journal instanceof TransactionJournal) {
            throw new ToolException(sprintf('there is no transaction #%d in this ledger.', $id));
        }

        /** @var CategoryRepositoryInterface $categories */
        $categories = app(CategoryRepositoryInterface::class);
        $categories->setUser($user);
        $category = $categories->findByName($wanted);
        if (!$category instanceof Category) {
            throw new ToolException(sprintf('there is no category called "%s". Call list_categories and use a name from it.', $wanted));
        }

        $current = $journal->categories()->first()?->name;
        if ($current === $category->name) {
            return ['proposed' => false, 'reason' => sprintf('transaction #%d is already categorised as "%s".', $id, $category->name)];
        }

        $transaction = $journal->transactions()->where('amount', '<', 0)->first() ?? $journal->transactions()->first();
        $card        = $this->proposals->put(
            [
                'type'        => 'category_change',
                'description' => (string) $journal->description,
                'date'        => $journal->date->format('Y-m-d'),
                'amount'      => $this->money(Steam::positive((string) ($transaction->amount ?? '0'))),
                'currency'    => (string) $transaction?->transactionCurrency?->code,
                'from'        => $current,
                'to'          => $category->name
            ],
            $user->id,
            $journal->id,
            $current,
            $category->name
        );

        return [
            'proposed'    => true,
            'transaction' => ['id' => $journal->id, 'description' => $card['description'], 'date' => $card['date']],
            'from'        => $current,
            'to'          => $category->name,
            'note'        => 'NOTHING HAS CHANGED YET. The person has been shown a confirmation card; tell them to confirm it. Do not claim the category was changed.'
        ];
    }
}
