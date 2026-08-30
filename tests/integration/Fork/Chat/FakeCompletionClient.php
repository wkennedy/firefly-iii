<?php

/*
 * FakeCompletionClient.php
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

namespace Tests\integration\Fork\Chat;

use FireflyIII\Fork\Chat\ChatCompletionClient;
use FireflyIII\Fork\Chat\ChatException;
use Override;

/**
 * FORK: a language model that does exactly what the test says. Replays queued assistant messages in
 * order and records what it was asked, so the suite can assert on the tool results the agent fed
 * back — the part that has to be right — without a model, a GPU or a network in CI.
 */
final class FakeCompletionClient implements ChatCompletionClient
{
    /** @var list<array{messages: list<array<string, mixed>>, tools: list<array<string, mixed>>}> */
    public array $calls  = [];

    /** @var list<array<string, mixed>> */
    private array $queue = [];

    private bool $explode = false;

    /**
     * @param array<string, mixed> $arguments
     */
    public static function toolCall(string $name, array $arguments, string $id = 'call-1'): array
    {
        return ['content' => '', 'tool_calls' => [[
            'id'       => $id,
            'type'     => 'function',
            'function' => ['name' => $name, 'arguments' => json_encode($arguments, JSON_THROW_ON_ERROR)],
        ]]];
    }

    #[Override]
    public function complete(array $messages, array $tools): array
    {
        $this->calls[] = ['messages' => $messages, 'tools' => $tools];
        if ($this->explode) {
            throw new ChatException('the language model did not answer: fake failure');
        }

        return array_shift($this->queue) ?? ['content' => 'nothing queued'];
    }

    #[Override]
    public function stream(array $messages, array $tools, callable $onDelta): array
    {
        $message = $this->complete($messages, $tools);
        $onDelta('reasoning', 'thinking about it');
        // Word by word, so a test can prove the widget is fed pieces rather than one blob.
        foreach (array_filter(explode(' ', (string) ($message['content'] ?? '')), static fn(string $word): bool => '' !== $word) as $index => $word) {
            $onDelta('content', 0 === $index ? $word : ' ' . $word);
        }

        return $message;
    }

    public function fail(): self
    {
        $this->explode = true;

        return $this;
    }

    /**
     * The most recent tool result the agent had handed back by the given call, decoded — i.e. what
     * the tool called in the previous round actually produced.
     *
     * @return array<string, mixed>
     */
    public function toolResult(int $call = 1): array
    {
        foreach (array_reverse($this->calls[$call]['messages'] ?? []) as $message) {
            if ('tool' === ($message['role'] ?? '')) {
                return json_decode((string) $message['content'], true, 512, JSON_THROW_ON_ERROR);
            }
        }

        return [];
    }

    /**
     * @param array<string, mixed> $message
     */
    public function willAnswer(array $message): self
    {
        $this->queue[] = $message;

        return $this;
    }

    public function willSay(string $content): self
    {
        return $this->willAnswer(['content' => $content]);
    }

    /**
     * @param array<string, mixed> $arguments
     */
    public function willCall(string $name, array $arguments): self
    {
        return $this->willAnswer(self::toolCall($name, $arguments));
    }
}
