<?php

/*
 * SeedDevDataTest.php
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

namespace Tests\integration\Fork\Dev;

use Carbon\Carbon;
use FireflyIII\Fork\Dev\DevDataSeeder;
use FireflyIII\Models\Account;
use FireflyIII\Models\AutoBudget;
use FireflyIII\Models\TransactionJournal;
use FireflyIII\User;
use Illuminate\Support\Facades\Hash;
use Override;
use Tests\integration\TestCase;

/**
 * FORK: the synthetic dev dataset is deterministic, valid, and refuses to pile up by accident.
 *
 * @internal
 *
 * @coversNothing
 */
final class SeedDevDataTest extends TestCase
{
    public function testRefusesInProductionWithoutForce(): void
    {
        app()->detectEnvironment(static fn(): string => 'production');
        $this->artisan('firefly-iii:fork:seed-dev-data')->expectsOutputToContain('APP_ENV is production')->assertExitCode(1);
        self::assertSame(0, User::query()->where('email', DevDataSeeder::EMAIL)->count());
    }

    public function testSeedsAValidHistoryForADevUser(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-08-20', 'UTC'));

        $this->artisan('firefly-iii:fork:seed-dev-data', ['--months' => 2])->expectsOutputToContain('Seeded')->assertExitCode(0);

        $user = User::query()->where('email', DevDataSeeder::EMAIL)->firstOrFail();
        self::assertTrue(Hash::check(DevDataSeeder::PASSWORD, (string) $user->password));
        self::assertSame(
            6,
            Account::query()->where('user_id', $user->id)->whereRelation('accountType', 'type', 'Asset account')->count()
            + Account::query()->where('user_id', $user->id)->whereRelation('accountType', 'type', 'Loan')->count()
            + Account::query()->where('user_id', $user->id)->whereRelation('accountType', 'type', 'Mortgage')->count()
        );
        $journals = TransactionJournal::query()->where('user_id', $user->id)->get();
        self::assertGreaterThan(60, $journals->count());
        self::assertSame($journals->count(), $journals->pluck('id')->unique()->count());
        self::assertSame(3, AutoBudget::query()->count());
        // both legs of a card payment exist and every journal carries an external_id
        self::assertSame(3, $journals->where('description', 'VISA AUTOPAY PAYMENT')->count(), 'two complete months + the current one');
        self::assertSame(
            3,
            $journals
                ->where('description', 'PAYMENT THANK YOU')
                ->filter(
                    static fn(TransactionJournal $j): bool => 1 === $j
                        ->transactions()
                        ->where('amount', '>', 0)
                        ->whereRelation('account', 'name', 'Dev Visa')
                        ->count()
                )
                ->count()
        );
        self::assertSame(
            $journals->count(),
            \FireflyIII\Models\TransactionJournalMeta::query()->where('name', 'external_id')->whereIn('transaction_journal_id', $journals->pluck('id'))->count()
        );
        // nothing dated after "today"
        self::assertTrue($journals->every(static fn(TransactionJournal $j): bool => $j->date->lessThanOrEqualTo(Carbon::parse('2026-08-20 23:59:59', 'UTC'))));

        // a second run refuses; --append adds
        $before = $journals->count();
        $this->artisan('firefly-iii:fork:seed-dev-data', ['--months' => 2])->expectsOutputToContain('already has transactions')->assertExitCode(1);
        self::assertSame($before, TransactionJournal::query()->where('user_id', $user->id)->count());
        $this->artisan('firefly-iii:fork:seed-dev-data', ['--months' => 0, '--append' => true])->assertExitCode(0);
        self::assertGreaterThan($before, TransactionJournal::query()->where('user_id', $user->id)->count());
    }

    #[Override]
    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }
}
