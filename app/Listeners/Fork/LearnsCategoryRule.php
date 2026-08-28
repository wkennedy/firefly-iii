<?php

/*
 * LearnsCategoryRule.php
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

namespace FireflyIII\Listeners\Fork;

use FireflyIII\Fork\Events\CategoryCorrected;
use FireflyIII\Fork\Rules\RuleLearner;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * FORK: a human correction becomes (or updates) a learned rule; automation never teaches.
 */
final class LearnsCategoryRule
{
    public function __construct(
        private readonly RuleLearner $learner
    ) {}

    public function handle(CategoryCorrected $event): void
    {
        if (!RuleLearner::enabled() || CategoryCorrected::HUMAN !== $event->source) {
            return;
        }

        try {
            $this->learner->learn($event->journal, $event->newCategory);
        } catch (Throwable $e) {
            Log::error(sprintf('FORK learning from journal #%d failed: %s', $event->journal->id, $e->getMessage()));
        }
    }
}
