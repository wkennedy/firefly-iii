<?php

/*
 * SeedDevData.php
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

namespace FireflyIII\Fork\Console\Commands;

use Carbon\Carbon;
use FireflyIII\Console\Commands\ShowsFriendlyMessages;
use FireflyIII\Fork\Dev\DevDataSeeder;
use FireflyIII\User;
use Illuminate\Console\Command;

/**
 * FORK: synthetic data for local development (see .fork/dev-up.sh --seed).
 */
final class SeedDevData extends Command
{
    use ShowsFriendlyMessages;

    protected $description = 'FORK: seed deterministic synthetic accounts, budgets and a bank-feed-shaped history for development.';

    protected $signature = 'firefly-iii:fork:seed-dev-data
        {--user= : Seed into this user (email). Default: create/use dev@example.invalid (password devpassword).}
        {--months=3 : Complete months of history before the current month.}
        {--append : Add another batch even if the user already has transactions.}
        {--force : Required when APP_ENV=production (the Docker image runs as production).}';

    public function handle(DevDataSeeder $seeder): int
    {
        if (app()->isProduction() && !(bool) $this->option('force')) {
            $this->friendlyError('APP_ENV is production. This seeds fake data — pass --force only on a throwaway database.');

            return self::FAILURE;
        }
        $user = null;
        if (null !== $this->option('user')) {
            $user = User::query()
                ->where('email', (string) $this->option('user'))
                ->first();
            if (null === $user) {
                $this->friendlyError(sprintf('No user "%s".', (string) $this->option('user')));

                return self::FAILURE;
            }
        }
        if (null !== $user && $seeder->hasData($user) && !(bool) $this->option('append')) {
            $this->friendlyError(sprintf('%s already has transactions; pass --append to add another batch.', $user->email));

            return self::FAILURE;
        }
        $devUser = User::query()->where('email', DevDataSeeder::EMAIL)->first();
        if (null === $user && null !== $devUser && $seeder->hasData($devUser) && !(bool) $this->option('append')) {
            $this->friendlyError(sprintf('%s already has transactions; pass --append to add another batch.', DevDataSeeder::EMAIL));

            return self::FAILURE;
        }

        $result = $seeder->seed($user, max(0, (int) $this->option('months')), Carbon::now(config('app.timezone')));
        $this->friendlyPositive(sprintf(
            'Seeded %d transaction(s) across %d account(s) for %s, %s → %s.',
            $result['journals'],
            $result['accounts'],
            $result['user'],
            $result['start'],
            $result['end']
        ));
        if (null === $user) {
            $this->friendlyInfo(sprintf('Login: %s / %s', DevDataSeeder::EMAIL, DevDataSeeder::PASSWORD));
        }

        return self::SUCCESS;
    }
}
