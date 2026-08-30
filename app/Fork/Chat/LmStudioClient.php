<?php

/*
 * LmStudioClient.php
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

use GuzzleHttp\Client;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Exception\RequestException;
use Illuminate\Support\Facades\Log;
use Override;
use Psr\Http\Message\ResponseInterface;

/**
 * FORK: OpenAI-compatible chat completions against the LAN LM Studio instance (config
 * fork.chat_llm_url, which must be an IP literal + /v1 — mDNS .local names do not resolve from
 * inside the cluster).
 *
 * Retries the way the categorizer does: LM Studio JIT-loads a model and answers 400 or refuses the
 * connection for a few seconds while it does, which is a blip, not a failure.
 */
final class LmStudioClient implements ChatCompletionClient
{
    private const int MAX_ATTEMPTS = 3;

    public function __construct(private readonly Client $client) {}

    #[Override]
    public function complete(array $messages, array $tools): array
    {
        return $this->retrying(fn(): array => $this->attempt($this->payload($messages, $tools)));
    }

    #[Override]
    public function stream(array $messages, array $tools, callable $onDelta): array
    {
        $emitted = false;
        $once    = function (string $type, string $text) use ($onDelta, &$emitted): void {
            $emitted = true;
            $onDelta($type, $text);
        };

        // Retries stop the moment the first token reaches the browser: replaying a stream would
        // repeat half an answer, which is worse than the failure it is trying to paper over.
        return $this->retrying(fn(): array => $this->attemptStream($this->payload($messages, $tools, true), $once), $emitted);
    }

    /**
     * @param array<string, mixed> $payload
     *
     * @return array<string, mixed>
     */
    private function attempt(array $payload): array
    {
        $response = $this->post($payload, false);
        $json     = json_decode((string) $response->getBody(), true);
        if (!is_array($json) || !is_array($json['choices'][0]['message'] ?? null)) {
            throw new ChatException('model answered with something that is not a chat completion');
        }

        return $json['choices'][0]['message'];
    }

    /**
     * Read a server-sent-events completion, handing text to $onDelta as it lands and assembling the
     * message on the way. Tool calls arrive in fragments — an id here, a few characters of JSON
     * arguments there — and are stitched back together per index.
     *
     * @param array<string, mixed>           $payload
     * @param callable(string, string): void $onDelta
     *
     * @return array<string, mixed>
     */
    private function attemptStream(array $payload, callable $onDelta): array
    {
        $body    = $this->post($payload, true)->getBody();
        $buffer  = '';
        $content = '';
        $calls   = [];

        while (!$body->eof()) {
            $buffer .= $body->read(8192);
            while (false !== ($break = strpos($buffer, "\n"))) {
                $line   = trim(substr($buffer, 0, $break));
                $buffer = substr($buffer, $break + 1);
                if (!str_starts_with($line, 'data:')) {
                    continue;
                }
                $data = trim(substr($line, 5));
                if ('[DONE]' === $data || '' === $data) {
                    continue;
                }
                $chunk = json_decode($data, true);
                $delta = $chunk['choices'][0]['delta'] ?? null;
                if (!is_array($delta)) {
                    continue;
                }
                if (is_string($delta['reasoning_content'] ?? null) && '' !== $delta['reasoning_content']) {
                    $onDelta('reasoning', $delta['reasoning_content']);
                }
                if (is_string($delta['content'] ?? null) && '' !== $delta['content']) {
                    $content .= $delta['content'];
                    $onDelta('content', $delta['content']);
                }
                $this->mergeToolCalls($calls, $delta['tool_calls'] ?? null);
            }
        }

        $message = ['content' => $content];
        if ([] !== $calls) {
            ksort($calls);
            $message['tool_calls'] = array_values($calls);
        }

        return $message;
    }

    /**
     * @param array<int, array<string, mixed>> $calls
     */
    private function mergeToolCalls(array &$calls, mixed $fragments): void
    {
        if (!is_array($fragments)) {
            return;
        }
        foreach ($fragments as $position => $fragment) {
            if (!is_array($fragment)) {
                continue;
            }
            $index                                = (int) ($fragment['index'] ?? $position);
            $calls[$index] ??= ['id' => '', 'type' => 'function', 'function' => ['name' => '', 'arguments' => '']];
            if (is_string($fragment['id'] ?? null)) {
                $calls[$index]['id'] = $fragment['id'];
            }
            if (is_string($fragment['function']['name'] ?? null)) {
                $calls[$index]['function']['name'] .= $fragment['function']['name'];
            }
            if (is_string($fragment['function']['arguments'] ?? null)) {
                $calls[$index]['function']['arguments'] .= $fragment['function']['arguments'];
            }
        }
    }

    /**
     * @param array<string, mixed> $messages
     * @param list<array<string, mixed>> $tools
     *
     * @return array<string, mixed>
     */
    private function payload(array $messages, array $tools, bool $stream = false): array
    {
        $payload = ['model' => (string) config('fork.chat_model'), 'temperature' => 0, 'messages' => $messages];
        if ([] !== $tools) {
            $payload['tools'] = $tools;
        }
        if ($stream) {
            $payload['stream'] = true;
        }

        return $payload;
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function post(array $payload, bool $stream): ResponseInterface
    {
        $url = sprintf('%s/chat/completions', rtrim((string) config('fork.chat_llm_url'), '/'));

        try {
            return $this->client->post($url, [
                'json'    => $payload,
                'stream'  => $stream,
                'timeout' => (int) config('fork.chat_timeout'),
                'headers' => ['Content-Type' => 'application/json', 'Accept' => $stream ? 'text/event-stream' : 'application/json'],
            ]);
        } catch (ConnectException $e) {
            throw new ChatException(sprintf('cannot reach the model at %s (%s)', $url, $e->getMessage()));
        } catch (RequestException $e) {
            throw new ChatException(sprintf('model returned an error: %s', $e->getMessage()));
        }
    }

    /**
     * Run one round, retrying the blips LM Studio makes while it JIT-loads a model. $stop is
     * checked by reference after every failure: once it is true, retrying is off the table.
     *
     * @param callable(): array<string, mixed> $round
     *
     * @return array<string, mixed>
     */
    private function retrying(callable $round, bool &$stop = false): array
    {
        $last = 'no attempt was made';

        for ($attempt = 1; $attempt <= self::MAX_ATTEMPTS; ++$attempt) {
            try {
                return $round();
            } catch (ChatException $e) {
                $last = $e->getMessage();
                Log::warning(sprintf('fork.chat: completion attempt %d/%d failed: %s', $attempt, self::MAX_ATTEMPTS, $last));
                if ($stop) {
                    throw new ChatException(sprintf('the answer stopped part-way through: %s', $last));
                }
                if ($attempt < self::MAX_ATTEMPTS) {
                    usleep(500_000 * $attempt);
                }
            }
        }

        throw new ChatException(sprintf('the language model did not answer: %s', $last));
    }
}
