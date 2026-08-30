<?php

/*
 * SearchTransactionsTool.php
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

use Carbon\Carbon;
use FireflyIII\Enums\TransactionTypeEnum;
use FireflyIII\Fork\Chat\ToolException;
use FireflyIII\Helpers\Collector\GroupCollectorInterface;
use FireflyIII\Support\Facades\Steam;
use FireflyIII\User;
use Illuminate\Support\Collection;
use Override;

/**
 * FORK: chat tool — individual transactions, through the same GroupCollector the transaction list
 * uses. Rows are capped (config fork.chat_max_rows): the answer says how many matched in total and
 * shows the most recent ones, so the model can be honest about a long tail instead of guessing at
 * one, and a wide question cannot blow the model's context window.
 */
final class SearchTransactionsTool implements ChatTool
{
    use FormatsMoney;
    use ResolvesArguments;

    #[Override]
    public function definition(): array
    {
        return [
            'name'        => $this->name(),
            'description' => 'List individual transactions between two dates. Use this for "what did I buy at X", "show me transactions over 100", "what was my biggest purchase" (order_by amount_desc, limit 1), or whenever the person asks about specific transactions rather than a total. Returns at most a limited number of rows plus the total number that matched.',
            'parameters'  => [
                'type'       => 'object',
                'properties' => [
                    'start'      => ['type' => 'string', 'description' => 'First day of the range, YYYY-MM-DD.'],
                    'end'        => ['type' => 'string', 'description' => 'Last day of the range, YYYY-MM-DD (inclusive).'],
                    'query'      => ['type' => 'string', 'description' => 'Optional words to look for in the description, e.g. a shop name.'],
                    'categories' => ['type' => 'array', 'items' => ['type' => 'string'], 'description' => 'Optional: only these categories, by exact name.'],
                    'type'       => [
                        'type'        => 'string',
                        'enum'        => ['withdrawal', 'deposit', 'transfer', 'all'],
                        'description' => 'Defaults to withdrawal (money spent).',
                    ],
                    'min_amount' => ['type' => 'string', 'description' => 'Optional: only transactions of at least this amount (positive number).'],
                    'max_amount' => ['type' => 'string', 'description' => 'Optional: only transactions of at most this amount (positive number).'],
                    'order_by'   => [
                        'type'        => 'string',
                        'enum'        => ['date_desc', 'date_asc', 'amount_desc', 'amount_asc'],
                        'description' => 'Sort order. Use amount_desc with limit 1 to find the single biggest transaction.',
                    ],
                    'limit'      => ['type' => 'integer', 'description' => 'How many rows to return. Defaults to the maximum.'],
                ],
                'required'   => ['start', 'end'],
            ],
        ];
    }

    #[Override]
    public function name(): string
    {
        return 'search_transactions';
    }

    #[Override]
    public function run(User $user, array $arguments): array
    {
        [$start, $end] = $this->range($arguments);
        $maximum       = max(1, (int) config('fork.chat_max_rows'));
        $limit         = (int) ($arguments['limit'] ?? $maximum);
        $limit         = min($maximum, max(1, $limit));
        $categories    = $this->categories($user, $arguments);

        /** @var GroupCollectorInterface $collector */
        $collector     = app(GroupCollectorInterface::class);
        $collector
            ->setUser($user)
            ->setRange($start, $end)
            ->setTypes($this->types($arguments))
            ->withAccountInformation()
            ->withCategoryInformation()
            ->withBudgetInformation()
        ;
        if ($categories instanceof Collection) {
            $collector->setCategories($categories);
        }
        $words         = $this->words($arguments);
        if ([] !== $words) {
            $collector->setSearchWords($words);
        }
        $this->amounts($collector, $arguments);

        // Sorting and slicing happen here rather than through setSorting()/setLimit(): the
        // collector skips its own slice as soon as sort instructions are present, which would
        // hand back the whole range. The date range is required, so the query itself is bounded.
        $journals      = $collector->getExtractedJournals();
        usort($journals, $this->order((string) ($arguments['order_by'] ?? 'date_desc')));
        $matched       = count($journals);
        $rows          = array_map($this->row(...), array_slice($journals, 0, $limit));

        return [
            'start'      => $start->format('Y-m-d'),
            'end'        => $end->format('Y-m-d'),
            'order_by'   => (string) ($arguments['order_by'] ?? 'date_desc'),
            'matched'    => $matched,
            'showing'    => count($rows),
            'truncated'  => $matched > count($rows),
            'transactions' => $rows,
        ];
    }

    /**
     * The comparator for one of the four sort orders. Amounts are compared as decimal strings, so
     * the "biggest transaction" question does not go through a float.
     *
     * @return callable(array<string, mixed>, array<string, mixed>): int
     */
    private function order(string $order): callable
    {
        $amount = static fn(array $journal): string => Steam::positive((string) $journal['amount']);

        return match ($order) {
            'date_asc'    => static fn(array $a, array $b): int => $a['date'] <=> $b['date'],
            'amount_desc' => static fn(array $a, array $b): int => bccomp($amount($b), $amount($a), 12),
            'amount_asc'  => static fn(array $a, array $b): int => bccomp($amount($a), $amount($b), 12),
            default       => static fn(array $a, array $b): int => $b['date'] <=> $a['date'],
        };
    }

    /**
     * @param array<string, mixed> $arguments
     */
    private function amounts(GroupCollectorInterface $collector, array $arguments): void
    {
        foreach (['min_amount' => 'amountMore', 'max_amount' => 'amountLess'] as $key => $method) {
            $value = $arguments[$key] ?? null;
            if (null === $value || '' === $value) {
                continue;
            }
            if (!is_numeric($value)) {
                throw new ToolException(sprintf('"%s" ("%s") is not a number.', $key, (string) $value));
            }
            $collector->{$method}((string) $value);
        }
    }

    /**
     * @param array<string, mixed> $journal
     *
     * @return array<string, mixed>
     */
    private function row(array $journal): array
    {
        $date = $journal['date'];

        return [
            'id'          => (int) $journal['transaction_journal_id'],
            'date'        => $date instanceof Carbon ? $date->format('Y-m-d') : (string) $date,
            'description' => (string) $journal['description'],
            'amount'      => $this->money(Steam::positive((string) $journal['amount']), (int) $journal['currency_decimal_places']),
            'currency'    => (string) $journal['currency_code'],
            'type'        => strtolower((string) $journal['transaction_type_type']),
            'category'    => $journal['category_name'] ?? null,
            'budget'      => $journal['budget_name'] ?? null,
            'from'        => $journal['source_account_name'] ?? null,
            'to'          => $journal['destination_account_name'] ?? null,
        ];
    }

    /**
     * @param array<string, mixed> $arguments
     *
     * @return list<string>
     */
    private function types(array $arguments): array
    {
        return match ($arguments['type'] ?? 'withdrawal') {
            'deposit'  => [TransactionTypeEnum::DEPOSIT->value],
            'transfer' => [TransactionTypeEnum::TRANSFER->value],
            'all'      => [TransactionTypeEnum::WITHDRAWAL->value, TransactionTypeEnum::DEPOSIT->value, TransactionTypeEnum::TRANSFER->value],
            default    => [TransactionTypeEnum::WITHDRAWAL->value],
        };
    }

    /**
     * @param array<string, mixed> $arguments
     *
     * @return list<string>
     */
    private function words(array $arguments): array
    {
        $query = trim((string) ($arguments['query'] ?? ''));
        if ('' === $query) {
            return [];
        }

        return array_values(array_filter(explode(' ', $query), static fn(string $word): bool => '' !== trim($word)));
    }
}
