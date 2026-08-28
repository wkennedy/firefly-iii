<?php

/*
 * mktoken.php
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

/*
 * FORK dev helper: prints a personal access token for a user of the running dev container.
 * Run inside the container:  php /tmp/mktoken.php [email]   (default: the first user)
 */

require '/var/www/html/vendor/autoload.php';
$app = require '/var/www/html/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

$email  = $argv[1] ?? null;
$query  = FireflyIII\User::query()->orderBy('id');
$user   = null === $email ? $query->firstOrFail() : $query->where('email', $email)->firstOrFail();
$client = Laravel\Passport\Passport::client()
    ->query()
    ->get()
    ->first(static fn($c): bool => in_array('personal_access', (array) ($c->grant_types ?? []), true));
if (null === $client) {
    Illuminate\Support\Facades\Artisan::call('passport:client', ['--personal' => true, '--name' => 'dev', '--no-interaction' => true]);
}
// last line of stdout is the token; anything the app logs to stdout comes before it.
echo PHP_EOL, $user->createToken('dev')->accessToken, PHP_EOL;
