<?php

/*
 * PairTransfers.php
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

namespace FireflyIII\Fork\Console\Commands;

use Carbon\Carbon;
use FireflyIII\Console\Commands\ShowsFriendlyMessages;
use FireflyIII\Fork\Transfers\TransferPairer;
use FireflyIII\User;
use Illuminate\Console\Command;

/**
 * FORK: the transfer-pairing sweep. Run daily after the import; also usable for a one-off catch-up.
 */
final class PairTransfers extends Command
{
    use ShowsFriendlyMessages;

    protected $description = 'FORK: pair card/loan payment legs (withdrawal + mirrored deposit) into transfers; tag what stays unpaired.';

    protected $signature = 'firefly-iii:fork:pair-transfers
        {--since= : Only funding legs dated on/after YYYY-MM-DD (default: 90 days ago).}
        {--dry-run : Report what would be paired without changing anything.}
        {--user= : Only this user (email); default: every user with pairing enabled.}';

    public function handle(TransferPairer $pairer): int
    {
        if (!TransferPairer::enabled()) {
            $this->friendlyWarning('Transfer pairing is disabled (FORK_TRANSFER_PAIRING).');

            return self::SUCCESS;
        }
        $sinceOption = $this->option('since');
        $since       = null !== $sinceOption && '' !== $sinceOption
            ? Carbon::createFromFormat('Y-m-d', (string) $sinceOption, config('app.timezone'))
            : Carbon::now(config('app.timezone'))->subDays(90);
        if (!$since instanceof Carbon) {
            $this->friendlyError(sprintf('"%s" is not a valid YYYY-MM-DD date.', (string) $sinceOption));

            return self::FAILURE;
        }
        $dryRun = (bool) $this->option('dry-run');
        $users  = null !== $this->option('user') ? User::query()->where('email', (string) $this->option('user'))->get() : User::query()->orderBy('id')->get();

        foreach ($users as $user) {
            $summary = $pairer->sweep($user, $since->clone(), $dryRun ? true : null);
            $this->friendlyInfo(sprintf(
                '%s: examined %d funding leg(s) since %s — paired %d, would pair %d, no candidate %d, ambiguous %d, skipped %d, tagged %d.',
                $user->email,
                $summary['examined'],
                $since->format('Y-m-d'),
                $summary['paired'],
                $summary['dry_run'],
                $summary['no_candidate'],
                $summary['ambiguous'],
                $summary['skipped'],
                $summary['tagged']
            ));
            foreach ($summary['results'] as $row) {
                if (in_array($row['status'], ['paired', 'dry_run', 'ambiguous'], true)) {
                    $this->friendlyLine(sprintf('  #%d %s: %s', $row['journal_id'], $row['status'], $row['message']));
                }
            }
        }

        return self::SUCCESS;
    }
}
