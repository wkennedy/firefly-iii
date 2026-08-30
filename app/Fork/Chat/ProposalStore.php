<?php

/*
 * ProposalStore.php
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

use Carbon\Carbon;
use Illuminate\Contracts\Cache\Repository;

/**
 * FORK: the gap between what the model asks for and what happens.
 *
 * A write tool does not write. It puts a proposal in here and gets back nothing the model can use;
 * the token stays server-side and travels to the browser only in the finished turn. Applying it
 * needs a request the model cannot make — a click, on a different route.
 *
 * So the model cannot confirm its own write, cannot use a proposal belonging to someone else (each
 * one records whose it is and is checked on the way out), and cannot sit on one indefinitely
 * (proposals expire, and each is single use).
 *
 * Backed by the cache rather than the session, and that is not a detail: a proposal is created
 * inside the streamed response, and StartSession has already written the session by the time the
 * stream callback runs, so anything put in the session there is silently dropped. That bug survived
 * unit tests and only showed up when a browser clicked the button.
 */
final class ProposalStore
{
    private const string PREFIX = 'fork.chat.proposal.';

    private const int TTL_SECONDS = 900;

    /** @var list<array<string, mixed>> proposals created during THIS request */
    private array $fresh = [];

    public function __construct(
        private readonly Repository $cache
    ) {}

    /**
     * The cards created during this request, for the finished turn to carry to the browser.
     *
     * @return list<array<string, mixed>>
     */
    public function fresh(): array
    {
        return $this->fresh;
    }

    /**
     * Store a proposal and return the part the browser needs. The token is deliberately not part of
     * what the tool hands back to the model.
     *
     * @param array<string, mixed> $card  what the confirmation card shows
     *
     * @return array<string, mixed>
     */
    public function put(array $card, int $userId, int $journalId, null|string $fromCategory, string $toCategory): array
    {
        $token    = bin2hex(random_bytes(16));
        $proposal = [
            'token'         => $token,
            'user_id'       => $userId,
            'journal_id'    => $journalId,
            'from_category' => $fromCategory,
            'to_category'   => $toCategory,
            'expires_at'    => Carbon::now()->getTimestamp() + self::TTL_SECONDS,
            'card'          => $card + ['token' => $token]
        ];
        $this->cache->put(self::PREFIX . $token, $proposal, self::TTL_SECONDS);
        $this->fresh[] = $proposal['card'];

        return $proposal['card'];
    }

    /**
     * Take a proposal out of the store for this user. Single use: pulled, so a replayed
     * confirmation is simply unknown rather than a second write. A proposal belonging to someone
     * else, or one that has aged out, comes back as nothing.
     *
     * @return null|array<string, mixed>
     */
    public function take(#[\SensitiveParameter] string $token, int $userId): null|array
    {
        $proposal = $this->cache->pull(self::PREFIX . $token);
        if (!is_array($proposal)) {
            return null;
        }
        if ((int) ($proposal['user_id'] ?? 0) !== $userId) {
            return null;
        }
        if ((int) ($proposal['expires_at'] ?? 0) < Carbon::now()->getTimestamp()) {
            return null;
        }

        return $proposal;
    }
}
