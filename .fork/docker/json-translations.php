<?php

/*
 * json-translations.php
 * Copyright (c) 2026 the fork authors.
 *
 * This file is part of Firefly III (https://github.com/firefly-iii).
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

/*
 * FORK: writes public/v3/i18n/<locale>.json, the i18next files the v3 frontend loads.
 * Upstream generates them in its release pipeline with an out-of-tree tool
 * (JC5/firefly-iii-dev, "ff3:json-translations v3") and ships them in the release
 * archive; they are gitignored, so an image built from a checkout has none and every
 * i18next.t() returns its key ("(DATE ERROR)" chart labels, untranslated UI strings).
 *
 * Output matches upstream's release files: { "<file>": { "<key>": "<string>" } } for the
 * keys listed in config/translations.php, Laravel ":name" placeholders rewritten to
 * i18next "{{name}}", a missing key rendered as "(<file>.<key>)", written as xx-YY.json
 * plus upstream's short alias. Plain PHP (no Laravel boot), so it runs in a Docker stage.
 *
 * Usage: php .fork/docker/json-translations.php [app-root]
 */

$root   = rtrim($argv[1] ?? dirname(__DIR__, 2), '/');
$keys   = (require $root . '/config/translations.php')['json']['v3'];
$outDir = $root . '/public/v3/i18n';

// upstream aliases these locales with a lowercase full name instead of the bare language code
$lowercaseAliases = ['en_GB', 'pt_BR', 'zh_CN', 'zh_TW'];

if (!is_dir($outDir) && !mkdir($outDir, 0o755, true)) {
    fwrite(STDERR, sprintf("cannot create %s\n", $outDir));
    exit(1);
}

$written = 0;
foreach (glob($root . '/resources/lang/*', GLOB_ONLYDIR) ?: [] as $dir) {
    $locale = basename($dir);
    $json   = [];
    foreach ($keys as $file => $fileKeys) {
        $path         = sprintf('%s/%s.php', $dir, $file);
        $strings      = is_file($path) ? require $path : [];
        $json[$file]  = [];
        foreach ($fileKeys as $key) {
            $value              = $strings[$key] ?? null;
            $json[$file][$key]  = is_string($value)
                ? preg_replace('/(?<![A-Za-z]):([A-Za-z_]+)/', '{{$1}}', $value)
                : sprintf('(%s.%s)', $file, $key);
        }
    }
    $encoded = json_encode($json, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);

    [$language] = explode('_', $locale);
    $names      = [str_replace('_', '-', $locale), in_array($locale, $lowercaseAliases, true) ? strtolower(str_replace('_', '-', $locale)) : $language];
    foreach (array_unique($names) as $name) {
        file_put_contents(sprintf('%s/%s.json', $outDir, $name), $encoded);
        ++$written;
    }
}

printf("wrote %d translation file(s) to %s\n", $written, $outDir);
