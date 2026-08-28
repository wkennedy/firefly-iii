<?php

/*
 * 2026_08_28_000001_fork_transfer_pairs.php
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

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * FORK: record of every funding/mirror pair merged by FireflyIII\Fork\Transfers\TransferPairer.
 */
return new class extends Migration {
    public function down(): void
    {
        Schema::dropIfExists('fork_transfer_pairs');
    }

    public function up(): void
    {
        if (Schema::hasTable('fork_transfer_pairs')) {
            return;
        }
        Schema::create('fork_transfer_pairs', static function (Blueprint $table): void {
            $table->increments('id');
            $table->timestamps();
            $table->unsignedBigInteger('user_group_id');
            $table->unsignedInteger('funding_journal_id');
            $table->unsignedInteger('mirror_journal_id');
            $table->string('mirror_description', 1024);
            $table->string('mirror_account', 1024);
            $table->decimal('amount', 32, 12);
            $table->date('matched_on');
            $table->string('strategy', 50);

            $table->unique('funding_journal_id', 'fork_transfer_pairs_funding');
            $table->unique('mirror_journal_id', 'fork_transfer_pairs_mirror');
            $table->foreign('user_group_id', 'fork_transfer_pairs_user_group')->references('id')->on('user_groups')->onDelete('cascade');
            $table->foreign('funding_journal_id', 'fork_transfer_pairs_funding_journal')->references('id')->on('transaction_journals')->onDelete('cascade');
        });
    }
};
