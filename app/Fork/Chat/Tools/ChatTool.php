<?php

/*
 * ChatTool.php
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

use FireflyIII\User;

/**
 * FORK: one read-only capability the chat model may call. Implementations answer strictly from the
 * given user's own data, through the same repositories the UI uses.
 */
interface ChatTool
{
    /**
     * The OpenAI "function" object: name, description and a JSON-schema parameter list. The
     * description is prompt surface — it is the only thing telling the model when to call this.
     *
     * @return array<string, mixed>
     */
    public function definition(): array;

    /**
     * Tool name as the model calls it. Must match definition()['name'].
     */
    public function name(): string;

    /**
     * @param array<string, mixed> $arguments  already JSON-decoded model arguments, untrusted
     *
     * @return array<string, mixed> JSON-encodable result handed back to the model
     */
    public function run(User $user, array $arguments): array;
}
