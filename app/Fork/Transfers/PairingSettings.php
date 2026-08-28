<?php

/*
 * PairingSettings.php
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

use FireflyIII\Support\Facades\Preferences;
use FireflyIII\User;
use InvalidArgumentException;
use Throwable;

use function Safe\preg_match;

/**
 * FORK: per-user transfer-pairing settings, stored as the `fork_transfer_pairing` preference.
 *
 *  enabled      pairing runs for this user (also needs config fork.transfer_pairing)
 *  window_days  a mirror may be dated this many days before or after the funding leg
 *  patterns     PCRE bodies (no delimiters, case-insensitive) a description must match to take
 *               part; empty = every withdrawal/deposit is a candidate (amount + date only)
 *  accounts     ids of asset/liability accounts allowed as the transfer's destination; empty = all
 *  dry_run      evaluate and report, change nothing
 */
final class PairingSettings
{
    public const string PREFERENCE = 'fork_transfer_pairing';

    /** @var array{enabled: bool, window_days: int, patterns: list<string>, accounts: list<int>, dry_run: bool} */
    public const array DEFAULTS = ['enabled' => false, 'window_days' => 3, 'patterns' => [], 'accounts' => [], 'dry_run' => false];

    public static function regex(string $pattern): string
    {
        return '~' . str_replace('~', '\~', $pattern) . '~i';
    }

    /**
     * @param array<string, mixed> $values
     *
     * @return array{enabled: bool, window_days: int, patterns: list<string>, accounts: list<int>, dry_run: bool}
     */
    private static function normalise(array $values): array
    {
        $patterns = array_values(array_filter(array_map('strval', (array) ($values['patterns'] ?? [])), static fn(string $p): bool => '' !== trim($p)));
        $accounts = array_values(array_unique(array_map('intval', (array) ($values['accounts'] ?? []))));

        return [
            'enabled'     => (bool) ($values['enabled'] ?? self::DEFAULTS['enabled']),
            'window_days' => max(0, min(31, (int) ($values['window_days'] ?? self::DEFAULTS['window_days']))),
            'patterns'    => $patterns,
            'accounts'    => $accounts,
            'dry_run'     => (bool) ($values['dry_run'] ?? self::DEFAULTS['dry_run'])
        ];
    }

    /**
     * @return array{enabled: bool, window_days: int, patterns: list<string>, accounts: list<int>, dry_run: bool}
     */
    public function forUser(User $user): array
    {
        $stored = Preferences::getForUser($user, self::PREFERENCE, [])?->data;

        return self::normalise(is_array($stored) ? $stored : []);
    }

    /**
     * @param array<string, mixed> $values
     *
     * @return array{enabled: bool, window_days: int, patterns: list<string>, accounts: list<int>, dry_run: bool}
     *
     * @throws InvalidArgumentException
     */
    public function save(User $user, array $values): array
    {
        $settings = self::normalise(array_merge($this->forUser($user), $values));
        foreach ($settings['patterns'] as $pattern) {
            try {
                preg_match(self::regex($pattern), '');
            } catch (Throwable) { // Safe raises PcreException; PHP itself raises a warning → ErrorException
                throw new InvalidArgumentException(sprintf('"%s" is not a valid regular expression.', $pattern));
            }
        }
        Preferences::setForUser($user, self::PREFERENCE, $settings);

        return $settings;
    }
}
