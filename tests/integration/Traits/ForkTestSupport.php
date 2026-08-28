<?php

/*
 * ForkTestSupport.php
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

namespace Tests\integration\Traits;

use Illuminate\Contracts\Auth\Authenticatable as UserContract;
use Illuminate\Support\Facades\Artisan;
use Laravel\Passport\Passport;

/**
 * FORK: makes the integration suite work against Firefly III's real auth stack.
 *
 * - API routes run under `auth:api` (Passport). Laravel's actingAs() only sets the
 *   `web` guard, so upstream's authenticated API tests answer 401 unless state leaks
 *   from an earlier test. Overriding actingAs() here fixes every test file at once.
 * - Passport resolves its RSA keys when the `api` guard is built; without
 *   storage/oauth-*.key every authenticated request 500s ("Invalid key supplied").
 *   Generate them once per checkout (the files are gitignored).
 */
trait ForkTestSupport
{
    /**
     * @param  null|string  $guard
     *
     * @return $this
     */
    public function actingAs(UserContract $user, $guard = null)
    {
        parent::actingAs($user, $guard);
        if (null === $guard) {
            Passport::actingAs($user);
        }

        return $this;
    }

    protected function setUpForkTestSupport(): void
    {
        if (!file_exists(storage_path('oauth-private.key'))) {
            Artisan::call('passport:keys', ['--no-interaction' => true]);
        }
        // .env.testing sets the legacy QUEUE_DRIVER, not QUEUE_CONNECTION; pin it so
        // ShouldQueue listeners (rules, webhooks) run inline in tests.
        config(['queue.default' => 'sync']);
    }
}
