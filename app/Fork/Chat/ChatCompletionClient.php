<?php

/*
 * ChatCompletionClient.php
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

/**
 * FORK: the one call the chat agent makes against a model. Kept behind an interface so the whole
 * agent is testable without a model: the fork test suite binds a replaying fake (no network in CI).
 */
interface ChatCompletionClient
{
    /**
     * One completion round. Returns the assistant message as the API gave it — either
     * `['content' => '...']` or `['tool_calls' => [...]]`.
     *
     * @param list<array<string, mixed>> $messages
     * @param list<array<string, mixed>> $tools     OpenAI tool definitions; empty forbids tool use
     *
     * @return array<string, mixed>
     *
     * @throws ChatException
     */
    public function complete(array $messages, array $tools): array;

    /**
     * The same round, streamed. Calls $onDelta('content'|'reasoning', $text) as text arrives and
     * returns the assembled assistant message, so the caller sees exactly what complete() returns.
     *
     * @param list<array<string, mixed>>       $messages
     * @param list<array<string, mixed>>       $tools
     * @param callable(string, string): void   $onDelta
     *
     * @return array<string, mixed>
     *
     * @throws ChatException
     */
    public function stream(array $messages, array $tools, callable $onDelta): array;
}
