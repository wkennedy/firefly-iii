<?php

/*
 * CategoryCorrected.php
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

namespace FireflyIII\Fork\Events;

use FireflyIII\Models\TransactionJournal;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * FORK: a transaction's category was changed through the update service.
 * `source` is "automation" for requests carrying `X-Fork-Source: automation`, else "human".
 */
final class CategoryCorrected
{
    use Dispatchable;

    public const string HUMAN      = 'human';
    public const string AUTOMATION = 'automation';

    public function __construct(
        public readonly TransactionJournal $journal,
        public readonly null|string $oldCategory,
        public readonly null|string $newCategory,
        public readonly string $source
    ) {}
}
