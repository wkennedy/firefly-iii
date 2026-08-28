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

use FireflyIII\Fork\Console\Commands\AutoBudgetCatchUp;
use FireflyIII\Fork\Support\Steam;
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
        if ($this->app->runningInConsole()) {
            $this->commands([AutoBudgetCatchUp::class]);
        }
    }

    #[Override]
    public function register(): void
    {
        // Steam with the floatalize() fix (see FireflyIII\Fork\Support\Steam).
        $this->app->bind('steam', static fn(): Steam => new Steam());
    }
}
