<?php

/*
 * TransactionWebhookMessagesTest.php
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

namespace Tests\integration\Fork\Webhooks;

use FireflyIII\Enums\WebhookTrigger;
use FireflyIII\Jobs\SendWebhookMessage;
use FireflyIII\Models\WebhookMessage;
use FireflyIII\User;
use Illuminate\Support\Facades\Bus;
use Tests\integration\TestCase;
use Tests\integration\Traits\CreatesTransactionGroups;

/**
 * FORK: the categorizer is driven by STORE_TRANSACTION / UPDATE_TRANSACTION webhooks and
 * writes back with fire_webhooks=false to avoid looping. Pin both halves of that contract
 * at the webhook_messages level; actual delivery (SendWebhookMessage) is faked.
 *
 * @internal
 *
 * @coversNothing
 */
final class TransactionWebhookMessagesTest extends TestCase
{
    use CreatesTransactionGroups;

    public function testAnyTriggerWebhookReceivesStoreMessages(): void
    {
        Bus::fake([SendWebhookMessage::class]);
        $user = $this->createAuthenticatedUser();
        $this->actingAs($user);
        $this->createWebhook($user, WebhookTrigger::ANY);
        $this->createWebhook($user, WebhookTrigger::STORE_TRANSACTION);

        $this->storeViaApi($user);

        self::assertSame(2, WebhookMessage::query()->count());
    }

    public function testStoreTriggerWebhookDoesNotFireOnUpdate(): void
    {
        Bus::fake([SendWebhookMessage::class]);
        $user = $this->createAuthenticatedUser();
        $this->actingAs($user);
        $group = $this->createWithdrawal($user);
        $this->createWebhook($user, WebhookTrigger::STORE_TRANSACTION);

        $this->updateCategoryViaApi($group->id, (int) $group->transactionJournals()->first()->id, 'Groceries', fireWebhooks: true);

        self::assertSame(0, WebhookMessage::query()->count());
    }

    public function testStoringATransactionCreatesOneMessageForStoreTrigger(): void
    {
        Bus::fake([SendWebhookMessage::class]);
        $user = $this->createAuthenticatedUser();
        $this->actingAs($user);
        $webhook = $this->createWebhook($user, WebhookTrigger::STORE_TRANSACTION);

        $groupId = $this->storeViaApi($user, ['description' => 'WHOLEFDS MKT 10123']);

        self::assertSame(1, WebhookMessage::query()->count());
        $message = WebhookMessage::query()->first();
        self::assertSame($webhook->id, $message->webhook_id);
        self::assertNotSame('', $message->uuid);
        self::assertSame('STORE_TRANSACTION', $message->message['trigger']);
        self::assertSame('TRANSACTIONS', $message->message['response']);
        self::assertSame('v0', $message->message['version']); // StandardMessageGenerator::$version is 0 in v6.6.6
        self::assertSame($webhook->url, $message->message['url']);
        self::assertSame($groupId, (int) $message->message['content']['id']);
        self::assertSame('WHOLEFDS MKT 10123', $message->message['content']['transactions'][0]['description']);
    }

    public function testStoringWithFireWebhooksFalseCreatesNoMessage(): void
    {
        Bus::fake([SendWebhookMessage::class]);
        $user = $this->createAuthenticatedUser();
        $this->actingAs($user);
        $this->createWebhook($user, WebhookTrigger::STORE_TRANSACTION);

        $this->storeViaApi($user, [], ['fire_webhooks' => false]);

        self::assertSame(0, WebhookMessage::query()->count());
    }

    public function testUpdateHonoursFireWebhooksFlag(): void
    {
        Bus::fake([SendWebhookMessage::class]);
        $user = $this->createAuthenticatedUser();
        $this->actingAs($user);
        $group     = $this->createWithdrawal($user);
        $journalId = (int) $group->transactionJournals()->first()->id;
        $this->createWebhook($user, WebhookTrigger::UPDATE_TRANSACTION);

        // the categorizer's own write-back: must stay silent
        $this->updateCategoryViaApi($group->id, $journalId, 'Groceries', fireWebhooks: false);
        self::assertSame(0, WebhookMessage::query()->count());

        // a normal edit: exactly one UPDATE_TRANSACTION message
        $this->updateCategoryViaApi($group->id, $journalId, 'Dining', fireWebhooks: true);
        self::assertSame(1, WebhookMessage::query()->count());
        $message = WebhookMessage::query()->first();
        self::assertSame('UPDATE_TRANSACTION', $message->message['trigger']);
        self::assertSame('Dining', $message->message['content']['transactions'][0]['category_name']);
    }

    /**
     * @param  array<string, mixed>  $transaction
     * @param  array<string, mixed>  $top
     */
    private function storeViaApi(User $user, array $transaction = [], array $top = []): int
    {
        $payload = $top
        + [
            'transactions' => [
                $transaction
                    + [
                        'type'             => 'withdrawal',
                        'date'             => '2026-01-15T12:00:00+00:00',
                        'amount'           => '12.34',
                        'description'      => 'Fork webhook test',
                        'currency_code'    => 'EUR',
                        'source_id'        => $this->assetAccount($user, 'Checking')->id,
                        'destination_name' => 'Some shop'
                    ]
            ]
        ];
        $response = $this->postJson(route('api.v1.transactions.store'), $payload);
        $response->assertSuccessful();

        return (int) $response->json('data.id');
    }

    private function updateCategoryViaApi(int $groupId, int $journalId, string $category, bool $fireWebhooks): void
    {
        $this->putJson(route('api.v1.transactions.update', [$groupId]), [
            'fire_webhooks' => $fireWebhooks,
            'transactions'  => [['transaction_journal_id' => $journalId, 'category_name' => $category]]
        ])->assertSuccessful();
    }
}
