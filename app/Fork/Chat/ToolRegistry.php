<?php

/*
 * ToolRegistry.php
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

use FireflyIII\Fork\Chat\Tools\AccountBalancesTool;
use FireflyIII\Fork\Chat\Tools\BudgetStatusTool;
use FireflyIII\Fork\Chat\Tools\BudgetSuggestionsTool;
use FireflyIII\Fork\Chat\Tools\CalculateTool;
use FireflyIII\Fork\Chat\Tools\ChatTool;
use FireflyIII\Fork\Chat\Tools\IncomeVsExpenseTool;
use FireflyIII\Fork\Chat\Tools\ListAccountsTool;
use FireflyIII\Fork\Chat\Tools\ListBudgetsTool;
use FireflyIII\Fork\Chat\Tools\ListCategoriesTool;
use FireflyIII\Fork\Chat\Tools\ProposeCategoryChangeTool;
use FireflyIII\Fork\Chat\Tools\SearchTransactionsTool;
use FireflyIII\Fork\Chat\Tools\SumByCategoryTool;
use FireflyIII\Fork\Chat\Tools\UnbudgetedSpendingTool;
use FireflyIII\User;
use Illuminate\Contracts\Container\Container;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * FORK: every capability the chat model has. Read-only by construction — there is no write tool to
 * disable, because none is registered. Adding one is a deliberate act with its own review, not a
 * config flag (see .fork/CHAT-DESIGN.md, phase 4d).
 */
final class ToolRegistry
{
    /**
     * Order matters a little: this is the order the model sees the tools in, and the grounding
     * tools (what exists) come before the ones that add up what exists.
     *
     * @var list<class-string<ChatTool>>
     */
    private const array TOOLS = [
        ListCategoriesTool::class,
        ListAccountsTool::class,
        ListBudgetsTool::class,
        SumByCategoryTool::class,
        SearchTransactionsTool::class,
        BudgetStatusTool::class,
        UnbudgetedSpendingTool::class,
        AccountBalancesTool::class,
        IncomeVsExpenseTool::class,
        BudgetSuggestionsTool::class,
        CalculateTool::class,
    ];

    /**
     * Tools that can lead to a write. Registered only when fork.chat_writes is on, so with the
     * switch off the model is not told they exist and there is nothing to call.
     *
     * @var list<class-string<ChatTool>>
     */
    private const array WRITE_TOOLS = [
        ProposeCategoryChangeTool::class,
    ];

    /** @var array<string, ChatTool> */
    private array $tools = [];

    /** @var list<string> names of the registered tools that can lead to a write */
    private array $writeNames = [];

    public function __construct(Container $container)
    {
        $classes = self::TOOLS;
        if (true === config('fork.chat_writes')) {
            $classes = array_merge($classes, self::WRITE_TOOLS);
        }
        foreach ($classes as $class) {
            /** @var ChatTool $tool */
            $tool                       = $container->make($class);
            $this->tools[$tool->name()] = $tool;
            if (in_array($class, self::WRITE_TOOLS, true)) {
                $this->writeNames[] = $tool->name();
            }
        }
    }

    /**
     * Whether a tool can lead to a write. Consumers that must stay read-only (the tool API behind
     * the MCP shim) ask this rather than keeping their own list, so adding a write tool cannot
     * quietly widen what they expose.
     */
    public function isWrite(string $name): bool
    {
        return in_array($name, $this->writeNames, true);
    }

    /**
     * The tool list as the completions API wants it.
     *
     * @return list<array<string, mixed>>
     */
    public function definitions(bool $includeWrites = true): array
    {
        $definitions = [];
        foreach ($this->tools as $name => $tool) {
            if (!$includeWrites && $this->isWrite($name)) {
                continue;
            }
            $definitions[] = ['type' => 'function', 'function' => $tool->definition()];
        }

        return $definitions;
    }

    /**
     * Run one tool call for this user. Anything that goes wrong — an unknown tool, arguments that
     * are not JSON, a bad date, an exception inside the tool — comes back as a result the model can
     * read and retry from. A tool call is a guess by a language model; it is not a request that
     * deserves to take the whole turn down.
     *
     * @return array<string, mixed>
     */
    public function execute(User $user, string $name, string $rawArguments): array
    {
        $tool      = $this->tools[$name] ?? null;
        if (!$tool instanceof ChatTool) {
            return ['error' => sprintf('there is no tool called "%s". Available: %s.', $name, implode(', ', array_keys($this->tools)))];
        }
        $arguments = '' === trim($rawArguments) ? [] : json_decode($rawArguments, true);
        if (!is_array($arguments)) {
            return ['error' => 'the arguments were not valid JSON.'];
        }

        try {
            return $this->fit($name, $tool->run($user, $arguments));
        } catch (ToolException $e) {
            return ['error' => $e->getMessage()];
        } catch (Throwable $e) {
            Log::error(sprintf('fork.chat: tool "%s" failed: %s', $name, $e->getMessage()), ['exception' => $e]);

            return ['error' => sprintf('the "%s" tool failed and returned nothing.', $name)];
        }
    }

    /**
     * @return list<string>
     */
    public function names(): array
    {
        return array_keys($this->tools);
    }

    /**
     * Keep a tool result inside the model's context budget.
     *
     * A wide question ("everything I spent this year") can produce more rows than the model can
     * read, and an over-long result does not fail loudly — it silently pushes the earlier
     * conversation out of the window. So results are measured, and when one is too big the longest
     * list in it is shortened from the end and the result says so, which the prompt tells the model
     * to repeat rather than present a partial list as complete.
     *
     * @param array<string, mixed> $result
     *
     * @return array<string, mixed>
     */
    private function fit(string $name, array $result): array
    {
        $budget = max(1000, (int) config('fork.chat_max_result_bytes'));
        if (strlen((string) json_encode($result)) <= $budget) {
            return $result;
        }
        $longest = null;
        $count   = 0;
        foreach ($result as $key => $value) {
            if (is_array($value) && array_is_list($value) && count($value) > $count) {
                $longest = $key;
                $count   = count($value);
            }
        }
        if (null === $longest) {
            Log::warning(sprintf('fork.chat: result of "%s" is over budget and has no list to shorten', $name));

            return $result;
        }
        while (count($result[$longest]) > 1 && strlen((string) json_encode($result)) > $budget) {
            array_pop($result[$longest]);
        }
        $result['truncated']      = true;
        $result['truncated_note'] = sprintf(
            'Only the first %d of %d "%s" entries are shown; say so rather than presenting this as the whole list.',
            count($result[$longest]),
            $count,
            $longest
        );

        return $result;
    }
}
