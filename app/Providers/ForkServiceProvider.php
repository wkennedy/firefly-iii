<?php

/*
 * ForkServiceProvider.php
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

namespace FireflyIII\Providers;

use FireflyIII\Factory\TransactionJournalFactory as UpstreamTransactionJournalFactory;
use FireflyIII\Fork\Console\Commands\AutoBudgetCatchUp;
use FireflyIII\Fork\Console\Commands\ExternalIdsBackfill;
use FireflyIII\Fork\Console\Commands\PurgeDeletedTransactions;
use FireflyIII\Fork\Dedup\ExternalIdObserver;
use FireflyIII\Fork\Factory\TransactionJournalFactory;
use FireflyIII\Fork\Support\Steam;
use FireflyIII\Models\TransactionJournalMeta;
use Illuminate\Support\ServiceProvider;
use Override;

/**
 * FORK: the one place fork behaviour is wired in. Registered last in
 * bootstrap/providers.php so its bindings win over upstream's.
 */
final class ForkServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        TransactionJournalMeta::observe(ExternalIdObserver::class);

        if ($this->app->runningInConsole()) {
            $this->commands([AutoBudgetCatchUp::class, ExternalIdsBackfill::class, PurgeDeletedTransactions::class]);
        }
    }

    #[Override]
    public function register(): void
    {
        // Steam with the floatalize() fix (see FireflyIII\Fork\Support\Steam).
        $this->app->bind('steam', static fn(): Steam => new Steam());
        // Journal creation wrapped in a DB transaction so external_id reservations are atomic
        // (config fork.external_id_dedup; see FireflyIII\Fork\Dedup\ExternalIdRegistry).
        $this->app->bind(UpstreamTransactionJournalFactory::class, TransactionJournalFactory::class);
    }
}
