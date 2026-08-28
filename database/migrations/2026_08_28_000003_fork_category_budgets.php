<?php

/*
 * 2026_08_28_000003_fork_category_budgets.php
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
 * FORK: default budget per category, per user group. See FireflyIII\Fork\Budgets\DefaultBudgets.
 */
return new class extends Migration {
    public function down(): void
    {
        Schema::dropIfExists('fork_category_budgets');
    }

    public function up(): void
    {
        if (Schema::hasTable('fork_category_budgets')) {
            return;
        }
        Schema::create('fork_category_budgets', static function (Blueprint $table): void {
            $table->increments('id');
            $table->timestamps();
            $table->unsignedBigInteger('user_group_id');
            $table->unsignedInteger('category_id');
            $table->unsignedInteger('budget_id');

            $table->unique(['user_group_id', 'category_id'], 'fork_category_budgets_unique');
            $table->foreign('user_group_id', 'fork_category_budgets_user_group')->references('id')->on('user_groups')->onDelete('cascade');
            $table->foreign('category_id', 'fork_category_budgets_category')->references('id')->on('categories')->onDelete('cascade');
            $table->foreign('budget_id', 'fork_category_budgets_budget')->references('id')->on('budgets')->onDelete('cascade');
        });
    }
};
