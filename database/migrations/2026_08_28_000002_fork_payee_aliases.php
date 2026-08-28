<?php

/*
 * 2026_08_28_000002_fork_payee_aliases.php
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
 * FORK: payee alias rules, per user group. See FireflyIII\Fork\Payees\PayeeAliaser.
 */
return new class extends Migration {
    public function down(): void
    {
        Schema::dropIfExists('fork_payee_aliases');
    }

    public function up(): void
    {
        if (Schema::hasTable('fork_payee_aliases')) {
            return;
        }
        Schema::create('fork_payee_aliases', static function (Blueprint $table): void {
            $table->increments('id');
            $table->timestamps();
            $table->unsignedBigInteger('user_group_id');
            $table->string('account_type', 50); // "Expense account" | "Revenue account"
            $table->string('match_type', 10); // exact | prefix | regex
            $table->string('pattern', 255);
            $table->string('canonical_name', 255);
            $table->boolean('active')->default(true);
            $table->boolean('clean_description')->default(false);
            $table->unsignedInteger('order')->default(0);
            $table->unsignedInteger('hit_count')->default(0);

            $table->index(['user_group_id', 'account_type', 'active'], 'fork_payee_aliases_lookup');
            $table->foreign('user_group_id', 'fork_payee_aliases_user_group')->references('id')->on('user_groups')->onDelete('cascade');
        });
    }
};
