<?php

/*
 * PairingResult.php
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

namespace FireflyIII\Fork\Transfers;

use FireflyIII\Fork\Models\ForkTransferPair;

/**
 * FORK: outcome of one pairing attempt.
 */
final readonly class PairingResult
{
    public const string PAIRED       = 'paired';
    public const string DRY_RUN      = 'dry_run';
    public const string NO_CANDIDATE = 'no_candidate';
    public const string AMBIGUOUS    = 'ambiguous';
    public const string SKIPPED      = 'skipped';

    public function __construct(
        public string $status,
        public string $message,
        public null|ForkTransferPair $pair = null,
        public null|int $candidateJournalId = null
    ) {}

    public static function skipped(string $message): self
    {
        return new self(self::SKIPPED, $message);
    }
}
