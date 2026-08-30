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

    // In-app chat assistant: a chat field in the UI that answers questions about transactions,
    // budgets and balances by calling read-only tools against the asking user's own data, with a
    // local LLM (LM Studio) doing the language part. No tool can write. The URL must be an IP
    // literal ending in /v1 — mDNS .local names do not resolve from inside the cluster. See
    // .fork/CHAT-DESIGN.md.
    'chat'            => (bool) env('FORK_CHAT', false),
    'chat_llm_url'    => (string) env('FORK_CHAT_LLM_URL', ''),
    'chat_model'      => (string) env('FORK_CHAT_MODEL', 'qwen3.8-27b'),
    // How many model→tool→model rounds one question may take before an answer is forced.
    'chat_max_rounds' => (int) env('FORK_CHAT_MAX_ROUNDS', 4),
    // Seconds to wait for one completion. A thinking model on a busy GPU is not fast.
    'chat_timeout'    => (int) env('FORK_CHAT_TIMEOUT', 120),
    // How many earlier turns of the conversation the browser may replay into a request.
    'chat_history'    => (int) env('FORK_CHAT_HISTORY', 12),
    // Hard cap on transaction rows handed to the model in one tool result.
    'chat_max_rows'   => (int) env('FORK_CHAT_MAX_ROWS', 50),
    // Hard cap on the JSON size of one tool result handed to the model. Over this, the longest
    // list in the result is shortened and the result says it was truncated.
    'chat_max_result_bytes' => (int) env('FORK_CHAT_MAX_RESULT_BYTES', 12000),

    // Let the chat propose changes to your data (currently: one transaction's category). Off by
    // default, and separate from FORK_CHAT on purpose — turning the chat on must not turn writing
    // on. Even with this enabled the model cannot write: it proposes, a person confirms, and the
    // confirmation is a different request on a route no tool can reach. See .fork/CHAT-DESIGN.md.
    'chat_writes'     => (bool) env('FORK_CHAT_WRITES', false),

    // Expose the chat's READ-ONLY tools over the API (GET/POST /api/v1/fork/chat/tools), for the
    // stdio MCP shim in .fork/mcp to proxy to LM Studio. Its own switch, independent of FORK_CHAT:
    // the tool surface and the in-app widget are separate decisions. Write tools are never exposed
    // here, whatever FORK_CHAT_WRITES says.
    'chat_tools_api'  => (bool) env('FORK_CHAT_TOOLS_API', false),

    // Stream the answer to the widget over SSE. On by default: a question that takes three rounds
    // is ~10 seconds, and a silent panel reads as broken. Set false if a proxy insists on buffering
    // the response despite the X-Accel-Buffering header; the widget then posts to /fork/chat.
    'chat_stream'     => (bool) env('FORK_CHAT_STREAM', true),

    // Modern-look CSS overlay for the v1 (Twig / AdminLTE 2) layout: public/fork/css/overlay*.css,
    // loaded after firefly.css by resources/views/fork/layout/overlay.twig. Purely visual, no
    // markup or JS changes, follows the user's dark-mode preference. The one switch that is ON by
    // default (it changes nothing but looks); FORK_UI_OVERLAY=false restores the stock upstream look.
    'ui_overlay' => (bool) env('FORK_UI_OVERLAY', true)
];
