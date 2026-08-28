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
 * FORK: feature switches for fork behaviour. Every fork feature defaults to OFF here and is
 * enabled per deployment through the environment, so an upstream sync or a bad rollout can be
 * neutralised without a code change.
 */
return [
    // Reserve every transaction's external_id in fork_external_ids (unique per user group) inside
    // the same database transaction that creates it, so two overlapping imports cannot both insert.
    // After enabling on an existing database run: php artisan firefly-iii:fork:external-ids:backfill
    'external_id_dedup' => (bool) env('FORK_EXTERNAL_ID_DEDUP', false)
];
