<?php

/*
 * ForkPayeeAlias.php
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

namespace FireflyIII\Fork\Models;

use Illuminate\Database\Eloquent\Model;

use function Safe\preg_match;

/**
 * FORK: one payee alias rule. Matching is case-insensitive; `regex` patterns are PCRE bodies
 * without delimiters.
 */
final class ForkPayeeAlias extends Model
{
    public const string EXACT  = 'exact';
    public const string PREFIX = 'prefix';
    public const string REGEX  = 'regex';

    /** @var list<string> */
    public const array MATCH_TYPES = [self::EXACT, self::PREFIX, self::REGEX];

    protected $table    = 'fork_payee_aliases';
    protected $fillable = ['user_group_id', 'account_type', 'match_type', 'pattern', 'canonical_name', 'active', 'clean_description', 'order', 'hit_count'];

    public static function regex(string $pattern): string
    {
        return '~' . str_replace('~', '\~', $pattern) . '~i';
    }

    public function matches(string $name): bool
    {
        $name    = trim($name);
        $pattern = trim((string) $this->pattern);

        return match ((string) $this->match_type) {
            self::EXACT => 0 === strcasecmp($name, $pattern),
            self::PREFIX => '' !== $pattern && 0 === stripos($name, $pattern),
            self::REGEX => 1 === preg_match(self::regex($pattern), $name),
            default => false
        };
    }

    protected function casts(): array
    {
        return ['active' => 'boolean', 'clean_description' => 'boolean', 'order' => 'integer', 'hit_count' => 'integer', 'user_group_id' => 'integer'];
    }
}
