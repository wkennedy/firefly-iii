<?php

/*
 * fork.php
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
 * FORK API routes, registered by ForkServiceProvider under /api/v1/fork with the `api` middleware
 * group (Passport auth, JSON accept headers). Route names: api.v1.fork.<resource>.<action>.
 */

use FireflyIII\Fork\Http\Controllers\TransferPairController;
use Illuminate\Support\Facades\Route;

Route::get('transfer-pairs', [TransferPairController::class, 'index'])->name('api.v1.fork.transfer-pairs.index');
Route::post('transfer-pairs/run', [TransferPairController::class, 'run'])->name('api.v1.fork.transfer-pairs.run');
Route::get('transfer-pairs/settings', [TransferPairController::class, 'settings'])->name('api.v1.fork.transfer-pairs.settings');
Route::put('transfer-pairs/settings', [TransferPairController::class, 'updateSettings'])->name('api.v1.fork.transfer-pairs.settings.update');
