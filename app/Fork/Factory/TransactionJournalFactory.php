<?php

/*
 * TransactionJournalFactory.php
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

namespace FireflyIII\Fork\Factory;

use FireflyIII\Factory\TransactionJournalFactory as UpstreamFactory;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Override;

/**
 * FORK: bound in place of the upstream factory. Upstream creates a group's journals with no
 * database transaction, so a duplicate detected while storing meta would leave a half-created
 * journal behind. Wrapping create() means the reservation (ExternalIdObserver) and the journal
 * either both commit or both roll back.
 */
final class TransactionJournalFactory extends UpstreamFactory
{
    #[Override]
    public function create(array $data): Collection
    {
        if (!(bool) config('fork.external_id_dedup')) {
            return parent::create($data);
        }

        return DB::transaction(fn(): Collection => parent::create($data));
    }
}
