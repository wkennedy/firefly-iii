<?php

/*
 * fork-web.php
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
 * FORK web routes, registered by ForkServiceProvider under /fork with the `web` +
 * `user-full-auth` middleware (session, CSRF, 2FA). Route names: fork.<feature>.<action>.
 * The API equivalents live in routes/fork.php.
 */

use FireflyIII\Fork\Http\Controllers\ChatController;
use Illuminate\Support\Facades\Route;

Route::post('chat', [ChatController::class, 'send'])->middleware('throttle:30,1')->name('fork.chat.send');
Route::post('chat/stream', [ChatController::class, 'stream'])->middleware('throttle:30,1')->name('fork.chat.stream');
// Confirming a proposed change: a separate route, reachable only by the person clicking, never
// by a tool call (config fork.chat_writes).
Route::post('chat/apply', [ChatController::class, 'apply'])->middleware('throttle:30,1')->name('fork.chat.apply');
