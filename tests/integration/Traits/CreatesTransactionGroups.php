<?php

/*
 * CreatesTransactionGroups.php
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

namespace Tests\integration\Traits;

use Carbon\Carbon;
use FireflyIII\Enums\AccountTypeEnum;
use FireflyIII\Enums\WebhookDelivery;
use FireflyIII\Enums\WebhookResponse;
use FireflyIII\Enums\WebhookTrigger;
use FireflyIII\Factory\TransactionGroupFactory;
use FireflyIII\Models\Account;
use FireflyIII\Models\AccountMeta;
use FireflyIII\Models\AccountType;
use FireflyIII\Models\GroupMembership;
use FireflyIII\Models\Rule;
use FireflyIII\Models\RuleAction;
use FireflyIII\Models\RuleGroup;
use FireflyIII\Models\RuleTrigger;
use FireflyIII\Models\TransactionCurrency;
use FireflyIII\Models\TransactionGroup;
use FireflyIII\Models\TransactionJournal;
use FireflyIII\Models\TransactionJournalMeta;
use FireflyIII\Models\UserGroup;
use FireflyIII\Models\UserRole;
use FireflyIII\Models\Webhook;
use FireflyIII\Models\WebhookDelivery as WebhookDeliveryModel;
use FireflyIII\Models\WebhookResponse as WebhookResponseModel;
use FireflyIII\Models\WebhookTrigger as WebhookTriggerModel;
use FireflyIII\User;

/**
 * FORK: fixtures the upstream suite lacks — users, accounts, whole transaction groups
 * (through the app's own TransactionGroupFactory, so validation and meta storage are
 * real), rules and webhooks. Everything is scoped to the given user's user group.
 */
trait CreatesTransactionGroups
{
    /**
     * Find-or-create an asset account by name for this user.
     */
    protected function assetAccount(User $user, string $name): Account
    {
        $existing = Account::query()
            ->where('user_id', $user->id)
            ->where('name', $name)
            ->whereRelation('accountType', 'type', AccountTypeEnum::ASSET->value)
            ->first();

        return $existing ?? $this->createAccount($user, AccountTypeEnum::ASSET, $name);
    }

    protected function createAccount(User $user, AccountTypeEnum $type, string $name, string $currencyCode = 'EUR'): Account
    {
        $accountType = AccountType::query()->where('type', $type->value)->first();
        $account     = Account::create([
            'user_id'         => $user->id,
            'user_group_id'   => $user->user_group_id,
            'account_type_id' => $accountType->id,
            'name'            => $name,
            'active'          => true,
            'virtual_balance' => '0'
        ]);
        // Accounts created through the UI/API always carry a currency_id meta row for these
        // types; code such as ConvertToTransfer dereferences it without a null check.
        if (in_array($type->value, config('firefly.valid_currency_account_types'), true)) {
            AccountMeta::create([
                'account_id' => $account->id,
                'name'       => 'currency_id',
                'data'       => (string) TransactionCurrency::query()->where('code', $currencyCode)->firstOrFail()->id
            ]);
        }

        return $account;
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    protected function createDeposit(User $user, array $overrides = []): TransactionGroup
    {
        return $this->createTransactionGroup($user, [$overrides + ['type' => 'deposit']]);
    }

    /**
     * Create an active rule (in its own active rule group) that fires at $moment
     * ('store-journal' or 'update-journal'), plus the given triggers and actions.
     *
     * @param  array<string, string>       $triggers  trigger_type => trigger_value
     * @param  array<int, array<int, string>>  $actions   [[action_type, action_value], ...] — ordered
     */
    protected function createRule(
        User $user,
        array $triggers,
        array $actions,
        string $moment = 'store-journal',
        bool $strict = true,
        string $title = 'Fork test rule'
    ): Rule {
        $group = RuleGroup::create([
            'user_id'         => $user->id,
            'user_group_id'   => $user->user_group_id,
            'title'           => $title . ' group',
            'order'           => (int) RuleGroup::query()->where('user_id', $user->id)->max('order') + 1,
            'active'          => true,
            'stop_processing' => false
        ]);
        $rule = Rule::create([
            'rule_group_id' => $group->id,
            'user_id'       => $user->id,
            'user_group_id' => $user->user_group_id,
            'title'         => $title,
            'order'         => 1,
            'active'        => true,
            'strict'        => $strict
        ]);
        $order = 1;
        RuleTrigger::create([
            'rule_id'         => $rule->id,
            'trigger_type'    => 'user_action',
            'trigger_value'   => $moment,
            'order'           => $order++,
            'active'          => true,
            'stop_processing' => false
        ]);
        foreach ($triggers as $type => $value) {
            RuleTrigger::create([
                'rule_id'         => $rule->id,
                'trigger_type'    => $type,
                'trigger_value'   => $value,
                'order'           => $order++,
                'active'          => true,
                'stop_processing' => false
            ]);
        }
        $order = 1;
        foreach ($actions as [$type, $value]) {
            RuleAction::create([
                'rule_id'         => $rule->id,
                'action_type'     => $type,
                'action_value'    => $value,
                'order'           => $order++,
                'active'          => true,
                'stop_processing' => false
            ]);
        }

        return $rule->fresh(['ruleTriggers', 'ruleActions']);
    }

    /**
     * Create a transaction group with one row per entry in $transactions. Each row is
     * merged over sensible defaults for its 'type' (withdrawal by default): a "Checking"
     * asset account as source, an expense account created from destination_name, EUR,
     * amount 10.00, a fixed date. Any key TransactionJournalFactory understands can be
     * overridden (external_id, category_name, tags, source_id, destination_id, ...).
     *
     * @param  array<int, array<string, mixed>>  $transactions
     */
    protected function createTransactionGroup(User $user, array $transactions, null|string $title = null, bool $errorIfDuplicate = false): TransactionGroup
    {
        $rows = array_map(fn(array $row): array => $this->transactionRow($user, $row), $transactions);

        /** @var TransactionGroupFactory $factory */
        $factory = app(TransactionGroupFactory::class);
        $factory->setUser($user);
        $factory->setUserGroup($user->userGroup);

        return $factory->create([
            'user'                    => $user,
            'user_group'              => $user->userGroup,
            'group_title'             => $title,
            'error_if_duplicate_hash' => $errorIfDuplicate,
            'transactions'            => $rows
        ]);
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    protected function createTransfer(User $user, array $overrides = []): TransactionGroup
    {
        return $this->createTransactionGroup($user, [$overrides + ['type' => 'transfer']]);
    }

    protected function createUser(string $email = 'second@email.com'): User
    {
        $group = UserGroup::create(['title' => $email]);
        $role  = UserRole::query()->where('title', 'owner')->first();
        $user  = User::create(['email' => $email, 'password' => 'password', 'user_group_id' => $group->id]);
        GroupMembership::create(['user_id' => $user->id, 'user_group_id' => $group->id, 'user_role_id' => $role->id]);

        return $user;
    }

    /**
     * Mirrors WebhookRepository::store(): the legacy trigger/response/delivery columns are
     * kept, but message generation selects webhooks through the seeded pivot tables
     * (webhook_triggers etc., matched by title), so those are attached too.
     */
    protected function createWebhook(
        User $user,
        WebhookTrigger $trigger,
        #[\SensitiveParameter]
        string $secret = 'fork-test-secret',
        string $url = 'https://webhook.example.invalid/receive'
    ): Webhook {
        $webhook = Webhook::create([
            'user_id'       => $user->id,
            'user_group_id' => $user->user_group_id,
            'active'        => true,
            'title'         => sprintf('Fork test webhook (%s)', $trigger->name),
            'trigger'       => $trigger->value,
            'response'      => WebhookResponse::TRANSACTIONS->value,
            'delivery'      => WebhookDelivery::JSON->value,
            'url'           => $url,
            'secret'        => $secret
        ]);
        $webhook->webhookTriggers()->save(WebhookTriggerModel::query()->where('title', $trigger->name)->firstOrFail());
        $webhook->webhookResponses()->save(WebhookResponseModel::query()->where('title', WebhookResponse::TRANSACTIONS->name)->firstOrFail());
        $webhook->webhookDeliveries()->save(WebhookDeliveryModel::query()->where('title', WebhookDelivery::JSON->name)->firstOrFail());

        return $webhook;
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    protected function createWithdrawal(User $user, array $overrides = []): TransactionGroup
    {
        return $this->createTransactionGroup($user, [$overrides + ['type' => 'withdrawal']]);
    }

    protected function journalMeta(TransactionJournal $journal, string $name): null|TransactionJournalMeta
    {
        return TransactionJournalMeta::query()->withTrashed()->where('transaction_journal_id', $journal->id)->where('name', $name)->first();
    }

    /**
     * @param  array<string, mixed>  $row
     *
     * @return array<string, mixed>
     */
    private function transactionRow(User $user, array $row): array
    {
        $type     = $row['type'] ?? 'withdrawal';
        $checking = fn(): int => $this->assetAccount($user, 'Checking')->id;
        $defaults = [
            'type'          => $type,
            'date'          => Carbon::parse('2026-01-15 12:00:00', 'UTC'),
            'amount'        => '10.00',
            'description'   => 'Fork test transaction',
            'currency_code' => 'EUR'
        ];

        $hasSource      = null !== ($row['source_id'] ?? null) || null !== ($row['source_name'] ?? null);
        $hasDestination = null !== ($row['destination_id'] ?? null) || null !== ($row['destination_name'] ?? null);

        switch ($type) {
            case 'deposit':
                if (!$hasSource) {
                    $defaults['source_name'] = 'Employer';
                }
                if (!$hasDestination) {
                    $defaults['destination_id'] = $checking();
                }

                break;

            case 'transfer':
                if (!$hasSource) {
                    $defaults['source_id'] = $checking();
                }
                if (!$hasDestination) {
                    $defaults['destination_id'] = $this->assetAccount($user, 'Savings')->id;
                }

                break;

            default: // withdrawal
                if (!$hasSource) {
                    $defaults['source_id'] = $checking();
                }
                if (!$hasDestination) {
                    $defaults['destination_name'] = 'Some shop';
                }
        }

        return $row + $defaults;
    }
}
