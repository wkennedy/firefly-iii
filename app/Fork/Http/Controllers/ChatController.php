<?php

/*
 * ChatController.php
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

namespace FireflyIII\Fork\Http\Controllers;

use FireflyIII\Fork\Chat\CategoryChanger;
use FireflyIII\Fork\Chat\ChatAgent;
use FireflyIII\Fork\Chat\ChatException;
use FireflyIII\Fork\Chat\ProposalStore;
use FireflyIII\Fork\Http\StreamedEventResponse;
use FireflyIII\Http\Controllers\Controller;
use FireflyIII\Models\TransactionJournal;
use FireflyIII\User;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * FORK: POST /fork/chat — one turn of the in-app assistant (config fork.chat).
 *
 * A session route, not /api/v1/fork: the widget runs in the logged-in UI, which is not a Passport
 * client, and Firefly's CSP (connect-src 'self') means the browser could not reach the model
 * directly even if we wanted it to.
 *
 * The endpoint is stateless. The conversation lives in the browser and is posted back each turn, so
 * nothing anyone types is stored server-side.
 */
final class ChatController extends Controller
{
    public function send(Request $request, ChatAgent $agent): JsonResponse
    {
        $data = $this->validated($request);

        try {
            $answer = $agent->answer($this->user(), $data['message'], $data['history'] ?? [], $data['context'] ?? []);
        } catch (ChatException $e) {
            // The model is down or unreachable: a real 502, and worth saying so plainly rather than
            // dressing it up as an answer.
            return response()->json(['message' => $e->getMessage()], 502);
        }

        return response()->json(['data' => [
            'answer'          => $answer->answer,
            'tools'           => $answer->toolCalls,
            'rounds'          => $answer->rounds,
            'hit_round_limit' => $answer->hitRoundLimit,
            'proposals'       => app(ProposalStore::class)->fresh(),
        ]]);
    }

    /**
     * Apply a change the person confirmed. The only route in the chat that writes, and it takes a
     * token rather than a description of what to do: nothing here can be steered by the model or by
     * whatever text a shop put in a transaction description.
     */
    public function apply(Request $request, CategoryChanger $changer, ProposalStore $proposals): JsonResponse
    {
        if (true !== config('fork.chat') || true !== config('fork.chat_writes')) {
            abort(404);
        }
        $data     = $request->validate(['token' => ['required', 'string', 'max:64']]);
        $user     = $this->user();
        $proposal = $proposals->take($data['token'], $user->id);
        if (null === $proposal) {
            return response()->json(['message' => 'That confirmation has expired or was already used. Ask again and confirm the new card.'], 410);
        }

        $journal  = TransactionJournal::query()->where('id', $proposal['journal_id'])->where('user_id', $user->id)->first();
        if (!$journal instanceof TransactionJournal) {
            return response()->json(['message' => 'That transaction no longer exists. Nothing was changed.'], 404);
        }

        // The proposal describes a world that may have moved on — the categorizer, a rule or another
        // window may have set a category since the card was drawn. Confirming a card that says
        // "Shopping → Household" must not silently overwrite something else.
        $current  = $journal->categories()->first()?->name;
        if ($current !== $proposal['from_category']) {
            return response()->json([
                'message' => sprintf(
                    'This transaction is now "%s", not "%s" as it was when the change was suggested. Nothing was changed.',
                    $current ?? 'uncategorised',
                    $proposal['from_category'] ?? 'uncategorised'
                ),
            ], 409);
        }

        $result   = $changer->apply($user, $journal, (string) $proposal['to_category']);

        return response()->json(['data' => [
            'applied'   => true,
            'category'  => $result['category'],
            'overruled' => $result['overruled'],
            'message'   => $result['overruled']
                ? sprintf('Set to "%s", but a rule then changed it to "%s".', $proposal['to_category'], $result['category'] ?? 'no category')
                : sprintf('Changed to "%s".', $result['category'] ?? 'no category'),
        ]]);
    }

    /**
     * The same turn as send(), streamed over SSE so the widget can show the model working. Every
     * event is one JSON object; the `done` event carries the canonical answer for the transcript.
     */
    public function stream(Request $request, ChatAgent $agent): StreamedEventResponse
    {
        // Validation runs here, outside the stream: a 422 must be an ordinary response, because
        // once the first byte of an event stream is out the status code is already spent.
        $data = $this->validated($request);
        $user = $this->user();

        $stream = new StreamedEventResponse(function () use ($agent, $user, $data): void {
            $emit = function (string $event, array $payload): void {
                echo sprintf("event: %s\ndata: %s\n\n", $event, json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
                $this->push();
            };

            try {
                $answer = $agent->stream($user, $data['message'], $data['history'] ?? [], $data['context'] ?? [], $emit);
                $emit('done', [
                    'answer'          => $answer->answer,
                    'tools'           => $answer->toolCalls,
                    'rounds'          => $answer->rounds,
                    'hit_round_limit' => $answer->hitRoundLimit,
                    // Confirmation cards ride on the finished turn, not on their own event: they are
                    // an action to take, not narration to follow along with.
                    'proposals'       => app(ProposalStore::class)->fresh(),
                ]);
            } catch (ChatException $e) {
                $emit('error', ['message' => $e->getMessage()]);
            }
        }, 200, [
            'Content-Type'      => 'text/event-stream',
            'Cache-Control'     => 'no-cache',
            'Connection'        => 'keep-alive',
            // nginx buffers a PHP-FPM response by default, which would hold the whole answer back
            // until it ends and make streaming pointless. This header turns that off per response,
            // so no nginx configuration has to ship with the image.
            'X-Accel-Buffering' => 'no',
        ]);

        return $stream;
    }

    /**
     * Get one event out of PHP and onto the wire now rather than at the end of the request.
     */
    private function push(): void
    {
        if (app()->runningUnitTests()) {
            // The test harness captures the stream in its own output buffer; flushing it here would
            // print the events instead.
            return;
        }
        if (0 < ob_get_level()) {
            ob_flush();
        }
        flush();
    }

    /**
     * @return array<string, mixed>
     */
    private function validated(Request $request): array
    {
        if (true !== config('fork.chat')) {
            abort(404);
        }

        return $request->validate([
            'message'           => ['required', 'string', 'max:2000'],
            'history'           => ['nullable', 'array', 'max:100'],
            'history.*.role'    => ['required', 'string', 'in:user,assistant'],
            'history.*.content' => ['required', 'string', 'max:8000'],
            'context'           => ['nullable', 'array'],
            'context.start'     => ['nullable', 'date_format:Y-m-d'],
            'context.end'       => ['nullable', 'date_format:Y-m-d'],
        ]);
    }

    private function user(): User
    {
        $user = auth()->user();
        if (!$user instanceof User) {
            throw new AuthenticationException();
        }

        return $user;
    }
}
