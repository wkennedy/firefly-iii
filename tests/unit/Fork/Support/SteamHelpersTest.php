<?php

/*
 * SteamHelpersTest.php
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

namespace Tests\unit\Fork\Support;

use FireflyIII\Fork\Support\Steam as ForkSteam;
use FireflyIII\Support\Steam;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\integration\TestCase;

/**
 * FORK: the bcmath string helpers every balance and amount goes through. Pure
 * functions, previously untested. Tests run against the bound `steam` service, which
 * ForkServiceProvider points at FireflyIII\Fork\Support\Steam (floatalize() fix).
 * Some expectations document quirks on purpose (marked "quirk") — change them only
 * together with the code.
 *
 * @internal
 *
 * @coversNothing
 */
final class SteamHelpersTest extends TestCase
{
    /**
     * @return iterable<string, array{null|string, int, string}>
     */
    public static function bcroundCases(): iterable
    {
        yield 'half up' => ['1.005', 2, '1.01'];
        yield 'negative rounds away from 0' => ['-1.005', 2, '-1.01'];
        yield 'plain truncation' => ['1.2344', 2, '1.23'];
        yield 'to 3 places' => ['1.23456', 3, '1.235'];
        yield 'to 0 places' => ['2.5', 0, '3'];
        yield 'zero point one to 0 places' => ['0.1', 0, '0'];
        yield 'null' => [null, 2, '0'];
        yield 'empty string' => ['', 2, '0'];
        yield 'blank string' => ['   ', 2, '0'];
        yield 'scientific notation' => ['1.5E2', 2, '150.00'];
        yield 'quirk: integer input is returned untouched, precision ignored' => ['10', 2, '10'];
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function floatalizeCases(): iterable
    {
        yield 'plain number passes through' => ['123.45', '123.45'];
        yield 'positive exponent with decimals' => ['1.5E3', '1500.0'];
        yield 'negative exponent with decimals' => ['1.5E-3', '0.0015'];
        yield 'lowercase e' => ['2.5e2', '250.0'];
        yield 'no decimals + negative exponent (fixed in fork)' => ['2E-3', '0.002'];
        yield 'no decimals + positive exponent' => ['2E3', '2000'];
        yield 'negative number, negative exponent' => ['-1.25E-2', '-0.0125'];
        yield 'quirk: non-numeric text is upper-cased' => ['abc', 'ABC'];
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function negativeCases(): iterable
    {
        yield 'positive becomes negative' => ['10.50', '-10.50'];
        yield 'negative stays' => ['-10.50', '-10.50'];
        yield 'zero' => ['0', '0'];
        yield 'empty' => ['', '0'];
        yield 'scientific notation' => ['1.5E3', '-1500.0'];
        yield 'tiny amount in scientific notation (was lost upstream)' => ['2E-3', '-0.002'];
    }

    /**
     * @return iterable<string, array{string, int}>
     */
    public static function phpBytesCases(): iterable
    {
        yield 'bytes' => ['512', 512];
        yield 'k' => ['2k', 2048];
        yield 'kb' => ['2kb', 2048];
        yield 'K upper' => ['2K', 2048];
        yield 'm' => ['2M', 2_097_152];
        yield 'fractional m' => ['1.5m', 1_572_864];
        yield 'g' => ['1g', 1_073_741_824];
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function positiveCases(): iterable
    {
        yield 'negative' => ['-10.50', '10.50'];
        yield 'positive' => ['10.50', '10.50'];
        yield 'zero' => ['0', '0'];
        yield 'empty' => ['', '0'];
        yield 'not a number' => ['abc', '0'];
    }

    #[DataProvider('bcroundCases')]
    public function testBcround(null|string $number, int $precision, string $expected): void
    {
        self::assertSame($expected, $this->steam()->bcround($number, $precision));
    }

    #[DataProvider('floatalizeCases')]
    public function testFloatalize(string $value, string $expected): void
    {
        self::assertSame($expected, $this->steam()->floatalize($value));
    }

    #[DataProvider('negativeCases')]
    public function testNegative(string $amount, string $expected): void
    {
        self::assertSame(0, bccomp($expected, $this->steam()->negative($amount), 12), sprintf('negative(%s)', $amount));
    }

    public function testOpposite(): void
    {
        $steam = new Steam();
        self::assertNull($steam->opposite(null));
        self::assertSame(0, bccomp('-5', $steam->opposite('5'), 12));
        self::assertSame(0, bccomp('5', $steam->opposite('-5'), 12));
        self::assertSame(0, bccomp('-12.34', $steam->opposite('12.34'), 12));
    }

    #[DataProvider('phpBytesCases')]
    public function testPhpBytes(string $value, int $expected): void
    {
        self::assertSame($expected, $this->steam()->phpBytes($value));
    }

    #[DataProvider('positiveCases')]
    public function testPositive(string $amount, string $expected): void
    {
        // bootstrap/app.php sets bcscale(12), so bcmul() pads to 12 decimals: compare numerically.
        self::assertSame(0, bccomp($expected, $this->steam()->positive($amount), 12), sprintf('positive(%s)', $amount));
    }

    public function testPositiveKeepsNegativeZeroSign(): void
    {
        // quirk: bccomp('-0.00', '0') is 0, so the sign is never flipped and "-0.00" survives.
        self::assertSame('-0.00', $this->steam()->positive('-0.00'));
    }

    public function testSteamServiceIsTheForkOverride(): void
    {
        self::assertInstanceOf(ForkSteam::class, app('steam'));
        self::assertInstanceOf(Steam::class, app('steam'));
    }

    public function testUpstreamFloatalizeStillNeedsTheOverride(): void
    {
        // When this fails, upstream fixed Steam::floatalize(): delete FireflyIII\Fork\Support\Steam,
        // the `steam` rebinding in ForkServiceProvider, and this test.
        self::assertSame('0', new Steam()->floatalize('2E-3'));
        self::assertSame('0.002', new ForkSteam()->floatalize('2E-3'));
    }

    private function steam(): Steam
    {
        return app('steam');
    }
}
