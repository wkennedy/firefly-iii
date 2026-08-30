<?php

/*
 * CalculateTool.php
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

use FireflyIII\Fork\Chat\Calculator;
use FireflyIII\User;
use Override;

/**
 * FORK: chat tool — arithmetic the model must not do in its head.
 *
 * Language models are unreliable at multi-digit money arithmetic and completely confident about it,
 * so the system prompt forbids computing and this tool makes obeying that cheaper than disobeying.
 */
final class CalculateTool implements ChatTool
{
    use FormatsMoney;

    public function __construct(private readonly Calculator $calculator) {}

    #[Override]
    public function definition(): array
    {
        return [
            'name'        => $this->name(),
            'description' => 'Work out an arithmetic expression exactly. Use this for EVERY calculation - percentages, differences, per-week or per-day figures, totals across rows - instead of doing the arithmetic yourself. Write percentages as decimals (30% of 250 is "0.30 * 250"). Numbers and + - * / ( ) only.',
            'parameters'  => [
                'type'       => 'object',
                'properties' => [
                    'expression' => ['type' => 'string', 'description' => 'For example "1071.56 / 3" or "(2200 + 411.88) * 0.25".'],
                ],
                'required'   => ['expression'],
            ],
        ];
    }

    #[Override]
    public function name(): string
    {
        return 'calculate';
    }

    #[Override]
    public function run(User $user, array $arguments): array
    {
        $expression = trim((string) ($arguments['expression'] ?? ''));
        $exact      = $this->calculator->evaluate($expression);

        return [
            'expression' => $expression,
            // Both forms: the exact value, and the one to quote when the answer is money.
            'result'     => rtrim(rtrim($exact, '0'), '.') ?: '0',
            'rounded_2'  => $this->money($exact),
        ];
    }
}
