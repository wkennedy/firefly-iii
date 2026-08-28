<?php

/*
 * Steam.php
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

namespace FireflyIII\Fork\Support;

use FireflyIII\Support\Steam as UpstreamSteam;
use Illuminate\Support\Facades\Log;
use Override;

/**
 * FORK: bound as the `steam` service by ForkServiceProvider.
 *
 * Fixes floatalize() for scientific notation without a decimal point and a negative
 * exponent: upstream computes the number of decimals only when the mantissa contains
 * a '.', so "2E-3" is formatted with 0 decimals and becomes "0". negative() runs
 * every amount through floatalize(), so the bug is reachable from balance code.
 * Remove this override once upstream's Steam::floatalize('2E-3') returns '0.002'
 * (SteamHelpersTest::testUpstreamFloatalizeStillNeedsTheOverride will tell you).
 */
final class Steam extends UpstreamSteam
{
    #[Override]
    public function floatalize(string $value): string
    {
        $value = strtoupper($value);
        if (!str_contains($value, 'E')) {
            return $value;
        }
        Log::debug(sprintf('Floatalizing %s', $value));

        $exponent = (int) strpos($value, 'E');
        $mantissa = substr($value, 0, $exponent);
        $power    = (int) substr($value, $exponent + 1);
        $decimals = str_contains($mantissa, '.') ? strlen(substr($mantissa, (int) strpos($mantissa, '.') + 1)) : 0;
        if ($power < 0) {
            $decimals += abs($power);
        }

        return number_format((float) $value, $decimals, '.', '');
    }
}
