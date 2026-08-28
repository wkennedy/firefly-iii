<?php

/*
 * JournalUpdateService.php
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

namespace FireflyIII\Fork\Services;

use FireflyIII\Fork\Events\CategoryCorrected;
use FireflyIII\Models\TransactionJournal;
use FireflyIII\Services\Internal\Update\JournalUpdateService as UpstreamService;
use FireflyIII\User;
use Laravel\Passport\AccessToken;
use Laravel\Passport\Passport;
use Override;

/**
 * FORK: bound in place of the upstream service. Every category change made through the API or
 * the web forms passes through update(); comparing the category before and after is all that is
 * needed to notice a correction. Rule actions do not use this service, so rules never "teach".
 */
final class JournalUpdateService extends UpstreamService
{
    public const string SOURCE_HEADER = 'X-Fork-Source';

    private null|TransactionJournal $forkJournal = null;

    #[Override]
    public function setTransactionJournal(TransactionJournal $transactionJournal): void
    {
        parent::setTransactionJournal($transactionJournal);
        $this->forkJournal = $transactionJournal;
    }

    #[Override]
    public function update(): void
    {
        $journal = $this->forkJournal;
        $before  = null !== $journal ? $this->categoryName($journal) : null;

        parent::update();

        if (null === $journal || !(bool) config('fork.learned_rules')) {
            return;
        }
        $after = $this->categoryName($journal);
        if ($before === $after) {
            return;
        }
        event(new CategoryCorrected($journal->fresh(), $before, $after, $this->source()));
    }

    private function categoryName(TransactionJournal $journal): null|string
    {
        $name = $journal->categories()->first()?->name;

        return null === $name ? null : (string) $name;
    }

    /**
     * "automation" when the request says so (X-Fork-Source header) or when it was authenticated
     * with a personal access token whose name is listed in config fork.automation_token_names.
     */
    private function source(): string
    {
        if (CategoryCorrected::AUTOMATION === strtolower((string) request()->header(self::SOURCE_HEADER, ''))) {
            return CategoryCorrected::AUTOMATION;
        }
        $configured = array_map(static fn(string $n): string => strtolower(trim($n)), explode(',', (string) config('fork.automation_token_names')));
        $names      = array_values(array_filter($configured, static fn(string $n): bool => '' !== $n));
        if ([] === $names) {
            return CategoryCorrected::HUMAN;
        }
        $user  = auth()->user();
        $token = $user instanceof User ? $user->token() : null;
        if (!$token instanceof AccessToken) {
            return CategoryCorrected::HUMAN; // web session (TransientToken) or console
        }
        $id = (string) ($token->oauth_access_token_id ?? '');
        if ('' === $id) {
            return CategoryCorrected::HUMAN;
        }
        $name = Passport::token()->newQuery()->whereKey($id)->value('name');

        return null !== $name && in_array(strtolower((string) $name), $names, true) ? CategoryCorrected::AUTOMATION : CategoryCorrected::HUMAN;
    }
}
