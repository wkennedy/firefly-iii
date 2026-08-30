<?php

/*
 * FormatsMoney.php
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

use FireflyIII\Support\Facades\Steam;

/**
 * FORK: one money format for every chat tool result.
 *
 * Steam::bcround() returns whole numbers untouched ("150") but pads anything with a decimal point
 * ("200.00"), because it is built for display where that reads fine. In a tool result the two forms
 * sit in the same table, and ragged decimals are exactly the sort of thing a language model
 * "corrects" by rewriting the number. So: round the way Steam does, then pad to a fixed width.
 */
trait FormatsMoney
{
    protected function money(string $amount, int $decimals = 2): string
    {
        return bcadd(Steam::bcround($amount, $decimals), '0', $decimals);
    }
}
