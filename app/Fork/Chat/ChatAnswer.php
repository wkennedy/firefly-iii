<?php

/*
 * ChatAnswer.php
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
 * FORK: the outcome of one chat turn — the answer plus the trace of what was actually consulted to
 * produce it. The trace is not decoration: it is how someone checks that a number came from their
 * ledger rather than from the model.
 */
final readonly class ChatAnswer
{
    /**
     * @param list<array{name: string, arguments: array<string, mixed>}> $toolCalls
     */
    public function __construct(
        public string $answer,
        public array $toolCalls = [],
        public int $rounds = 0,
        public bool $hitRoundLimit = false
    ) {}
}
