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
 * FORK: feature switches for fork behaviour. Every fork feature that changes behaviour defaults to
 * OFF here and is enabled per deployment through the environment, so an upstream sync or a bad
 * rollout can be neutralised without a code change (the purely visual ui_overlay is the exception).
 */
return [
    // Reserve every transaction's external_id in fork_external_ids (unique per user group) inside
    // the same database transaction that creates it, so two overlapping imports cannot both insert.
    // After enabling on an existing database run: php artisan firefly-iii:fork:external-ids:backfill
    'external_id_dedup' => (bool) env('FORK_EXTERNAL_ID_DEDUP', false),

    // Allow transfers from asset accounts to liabilities (loan, debt, mortgage) and make the
    // "correct transaction types" command expect exactly that for such pairs. Loan payments then
    // leave spending reports and show up as transfers; balances are unaffected. Also enables the
    // `convert_liability_transfer` rule action. After enabling on an existing database run
    //   php artisan correction:transaction-types   (converts existing asset→liability withdrawals)
    'liability_transfers' => (bool) env('FORK_LIABILITY_TRANSFERS', false),

    // Master switch for transfer pairing: a bank feed that reports both legs of a card/loan payment
    // (withdrawal from checking + deposit into the card) is merged into one transfer by amount and
    // date. Per-user behaviour (patterns, window, target accounts, dry run) is a preference managed
    // through GET/PUT /api/v1/fork/transfer-pairs/settings; the daily cron runs the sweep.
    'transfer_pairing' => (bool) env('FORK_TRANSFER_PAIRING', false),

    // Payee aliases: map raw payee strings ("AMAZON MKTPL*AB12CD34") to one canonical expense or
    // revenue account ("Amazon") BEFORE the account is created, so fragments never exist. Managed
    // through /api/v1/fork/payee-aliases; `firefly-iii:fork:payees:merge` folds existing fragments.
    'payee_aliases' => (bool) env('FORK_PAYEE_ALIASES', false),

    // Category → default budget: a withdrawal that has a category but no budget gets the budget
    // mapped for that category, on creation AND on later category corrections. Never overrides a
    // budget that is already set. Managed through /api/v1/fork/category-budgets;
    // `firefly-iii:fork:budgets:apply-defaults` backfills a date range.
    'category_budgets' => (bool) env('FORK_CATEGORY_BUDGETS', false),

    // Learned rules: when a person changes a transaction's category, upsert a rule in the
    // "Learned (fork)" rule group — payee → category — so the next import of that payee is
    // categorised by Firefly itself. Automation never teaches: requests carrying the header
    // `X-Fork-Source: automation`, or authenticated with a token named below.
    'learned_rules'       => (bool) env('FORK_LEARNED_RULES', false),
    'learned_rules_group' => (string) env('FORK_LEARNED_RULES_GROUP', 'Learned (fork)'),

    // Comma-separated names of personal access tokens whose writes count as automation (never
    // teach a learned rule), e.g. the categorizer's token. Case-insensitive.
    'automation_token_names' => (string) env('FORK_AUTOMATION_TOKEN_NAMES', ''),

    // Modern-look CSS overlay for the v1 (Twig / AdminLTE 2) layout: public/fork/css/overlay*.css,
    // loaded after firefly.css by resources/views/fork/layout/overlay.twig. Purely visual, no
    // markup or JS changes, follows the user's dark-mode preference. The one switch that is ON by
    // default (it changes nothing but looks); FORK_UI_OVERLAY=false restores the stock upstream look.
    'ui_overlay' => (bool) env('FORK_UI_OVERLAY', true)
];
