<?php

/*
 * ForkTransferPair.php
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

namespace FireflyIII\Fork\Models;

use FireflyIII\Models\TransactionJournal;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * FORK: a funding withdrawal that was converted into a transfer, and the mirror deposit that was
 * removed for it. The mirror journal is soft-deleted, so its description/account are snapshotted.
 */
final class ForkTransferPair extends Model
{
    protected $table    = 'fork_transfer_pairs';
    protected $fillable = [
        'user_group_id',
        'funding_journal_id',
        'mirror_journal_id',
        'mirror_description',
        'mirror_account',
        'amount',
        'matched_on',
        'strategy'
    ];

    public function fundingJournal(): BelongsTo
    {
        return $this->belongsTo(TransactionJournal::class, 'funding_journal_id');
    }

    protected function casts(): array
    {
        return ['amount' => 'string', 'matched_on' => 'date'];
    }
}
