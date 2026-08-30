<?php

/*
 * Calculator.php
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
 * FORK: exact arithmetic for the chat's `calculate` tool.
 *
 * A hand-written recursive-descent parser over bcmath, NOT eval() and not a library: the input is a
 * string a language model wrote, so the set of things it can express has to be closed by
 * construction — numbers, + - * / and parentheses, nothing else. There is no identifier, no call,
 * no variable, so there is nothing to escape from.
 *
 * bcmath rather than floats because this multiplies money: 0.1 + 0.2 has to be 0.3.
 */
final class Calculator
{
    private const int SCALE = 12;

    private const int MAX_LENGTH = 200;

    /** @var list<string> */
    private array $tokens = [];

    private int $position = 0;

    /**
     * @throws ToolException
     */
    public function evaluate(string $expression): string
    {
        if (mb_strlen($expression) > self::MAX_LENGTH) {
            throw new ToolException(sprintf('that expression is too long (limit %d characters).', self::MAX_LENGTH));
        }
        $this->tokens   = $this->tokenize($expression);
        $this->position = 0;
        if ([] === $this->tokens) {
            throw new ToolException('there is nothing to calculate.');
        }
        $value          = $this->expression();
        if ($this->position < count($this->tokens)) {
            throw new ToolException(sprintf('I could not read "%s" as arithmetic.', $expression));
        }

        return $value;
    }

    private function expression(): string
    {
        $value = $this->term();
        while (in_array($this->peek(), ['+', '-'], true)) {
            $operator = (string) $this->next();
            $right    = $this->term();
            $value    = '+' === $operator ? bcadd($value, $right, self::SCALE) : bcsub($value, $right, self::SCALE);
        }

        return $value;
    }

    private function next(): ?string
    {
        $token = $this->peek();
        ++$this->position;

        return $token;
    }

    private function peek(): ?string
    {
        return $this->tokens[$this->position] ?? null;
    }

    private function primary(): string
    {
        $token = $this->next();
        if (null === $token) {
            throw new ToolException('the expression ends where a number should be.');
        }
        if ('(' === $token) {
            $value = $this->expression();
            if (')' !== $this->next()) {
                throw new ToolException('there is a "(" without its ")".');
            }

            return $value;
        }
        if (1 !== preg_match('/^\d+(\.\d+)?$/', $token)) {
            throw new ToolException(sprintf('"%s" is not a number.', $token));
        }

        return $token;
    }

    private function term(): string
    {
        $value = $this->unary();
        while (in_array($this->peek(), ['*', '/'], true)) {
            $operator = (string) $this->next();
            $right    = $this->unary();
            if ('/' === $operator) {
                if (0 === bccomp($right, '0', self::SCALE)) {
                    throw new ToolException('that would divide by zero.');
                }
                $value = bcdiv($value, $right, self::SCALE);

                continue;
            }
            $value    = bcmul($value, $right, self::SCALE);
        }

        return $value;
    }

    /**
     * @return list<string>
     */
    private function tokenize(string $expression): array
    {
        // Thousands separators are stripped: the model copies "1,071.56" straight out of a tool
        // result, and refusing that would only teach it to retype numbers by hand.
        $clean  = str_replace([',', ' ', "\t"], '', trim($expression));
        $tokens = [];
        $length = mb_strlen($clean);
        $index  = 0;
        while ($index < $length) {
            $character = mb_substr($clean, $index, 1);
            if (in_array($character, ['+', '-', '*', '/', '(', ')'], true)) {
                $tokens[] = $character;
                ++$index;

                continue;
            }
            if (1 === preg_match('/[\d.]/', $character)) {
                $number = '';
                while ($index < $length && 1 === preg_match('/[\d.]/', mb_substr($clean, $index, 1))) {
                    $number .= mb_substr($clean, $index, 1);
                    ++$index;
                }
                $tokens[] = $number;

                continue;
            }

            throw new ToolException(sprintf('"%s" is not something I can calculate with; use numbers and + - * / ( ) only.', $character));
        }

        return $tokens;
    }

    private function unary(): string
    {
        if ('-' === $this->peek()) {
            $this->next();

            return bcsub('0', $this->unary(), self::SCALE);
        }
        if ('+' === $this->peek()) {
            $this->next();
        }

        return $this->primary();
    }
}
