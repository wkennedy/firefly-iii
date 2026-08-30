<?php

/*
 * CalculatorTest.php
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

namespace Tests\unit\Fork\Chat;

use FireflyIII\Fork\Chat\Calculator;
use FireflyIII\Fork\Chat\ToolException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\integration\TestCase;

/**
 * FORK: the chat's arithmetic (config fork.chat). The model is told never to compute; this is what
 * it computes with instead, so "exact" and "closed" are the two properties that matter.
 *
 * @internal
 *
 * @coversNothing
 */
final class CalculatorTest extends TestCase
{
    public static function expressions(): array
    {
        return [
            'addition'                => ['1071.56 + 411.88', '1483.44'],
            'float noise avoided'     => ['0.1 + 0.2', '0.3'],
            'percentage as a decimal' => ['0.30 * 463.10', '138.93'],
            'precedence'              => ['2 + 3 * 4', '14'],
            'parentheses'             => ['(2 + 3) * 4', '20'],
            'unary minus'             => ['-5 + 8', '3'],
            'negative result'         => ['411.88 - 1071.56', '-659.68'],
            'division'                => ['1071.56 / 4', '267.89'],
            'thousands separators'    => ['1,071.56 + 1,000', '2071.56'],
            'nested'                  => ['((100 + 50) / 3) * 2', '100'],
        ];
    }

    public static function rejected(): array
    {
        return [
            'division by zero'   => ['5 / 0', 'divide by zero'],
            'a variable'         => ['$total * 2', 'not something I can calculate'],
            'a function call'    => ['phpinfo()', 'not something I can calculate'],
            'a statement'        => ['1; echo 2', 'not something I can calculate'],
            'a bare word'        => ['groceries + 5', 'not something I can calculate'],
            'unbalanced bracket' => ['(2 + 3', 'without its'],
            'nothing at all'     => ['   ', 'nothing to calculate'],
            'dangling operator'  => ['2 +', 'ends where a number should be'],
        ];
    }

    #[DataProvider('expressions')]
    public function testEvaluates(string $expression, string $expected): void
    {
        // Trailing zeros are the calculator's own scale, not part of the answer.
        $result = rtrim(rtrim((new Calculator())->evaluate($expression), '0'), '.');
        self::assertSame($expected, '' === $result ? '0' : $result);
    }

    #[DataProvider('rejected')]
    public function testRefuses(string $expression, string $because): void
    {
        // Every refusal is a ToolException, which the registry hands back to the model as advice it
        // can act on rather than an error that ends the turn.
        $this->expectException(ToolException::class);
        $this->expectExceptionMessageMatches('/' . preg_quote($because, '/') . '/');
        (new Calculator())->evaluate($expression);
    }

    public function testRefusesSomethingEnormous(): void
    {
        $this->expectException(ToolException::class);
        $this->expectExceptionMessageMatches('/too long/');
        (new Calculator())->evaluate(implode(' + ', array_fill(0, 100, '1234567')));
    }
}
