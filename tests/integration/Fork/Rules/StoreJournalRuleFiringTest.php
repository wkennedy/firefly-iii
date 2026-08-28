<?php

/*
 * StoreJournalRuleFiringTest.php
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

namespace Tests\integration\Fork\Rules;

use FireflyIII\Models\TransactionJournal;
use FireflyIII\User;
use Tests\integration\TestCase;
use Tests\integration\Traits\CreatesTransactionGroups;

/**
 * FORK: the importer POSTs transactions and relies on rules firing at 'store-journal'
 * (ProcessesNewTransactionGroup → SupportsGroupProcessingTrait::processRules). End-to-end
 * through the API, the way production data arrives.
 *
 * @internal
 *
 * @coversNothing
 */
final class StoreJournalRuleFiringTest extends TestCase
{
    use CreatesTransactionGroups;

    public function testApplyRulesFalseSkipsRules(): void
    {
        $user = $this->createAuthenticatedUser();
        $this->actingAs($user);
        $this->createRule($user, ['description_contains' => 'WHOLEFDS'], [['set_category', 'Groceries']]);

        $journal = $this->storeViaApi($user, 'WHOLEFDS MKT 10123', ['apply_rules' => false]);

        self::assertSame(0, $journal->categories()->count());
    }

    public function testDestinationAccountStartsTriggerRetargetsPayee(): void
    {
        // "Payee consolidation": Amazon puts an order id in the payee, one expense account per order.
        $user = $this->createAuthenticatedUser();
        $this->actingAs($user);
        $this->createRule($user, ['destination_account_starts' => 'AMAZON'], [['set_destination_account', 'Amazon']]);

        $journal = $this->storeViaApi($user, 'Order 123', [], 'AMAZON MKTPL*AB12CD34');

        self::assertSame('Amazon', $journal->transactions()->where('amount', '>', 0)->first()->account->name);
    }

    public function testInactiveRuleDoesNotFire(): void
    {
        $user = $this->createAuthenticatedUser();
        $this->actingAs($user);
        $rule         = $this->createRule($user, ['description_contains' => 'WHOLEFDS'], [['set_category', 'Groceries']]);
        $rule->active = false;
        $rule->save();

        $journal = $this->storeViaApi($user, 'WHOLEFDS MKT 10123');

        self::assertSame(0, $journal->categories()->count());
    }

    public function testNonMatchingRuleLeavesJournalUntouched(): void
    {
        $user = $this->createAuthenticatedUser();
        $this->actingAs($user);
        $this->createRule($user, ['description_contains' => 'WHOLEFDS'], [['set_category', 'Groceries']]);

        $journal = $this->storeViaApi($user, 'SHELL OIL 1234');

        self::assertSame(0, $journal->categories()->count());
    }

    public function testStoreJournalRuleSetsCategoryAndTag(): void
    {
        $user = $this->createAuthenticatedUser();
        $this->actingAs($user);
        $this->createRule(
            $user,
            ['description_contains' => 'WHOLEFDS'],
            [
                ['set_category', 'Groceries'],
                ['add_tag',      'auto-rule']
            ]
        );

        $journal = $this->storeViaApi($user, 'WHOLEFDS MKT 10123');

        self::assertSame('Groceries', $journal->categories()->first()?->name);
        self::assertSame(['auto-rule'], $journal->tags()->pluck('tag')->all());
    }

    public function testUpdateJournalRuleDoesNotFireOnStore(): void
    {
        $user = $this->createAuthenticatedUser();
        $this->actingAs($user);
        $this->createRule($user, ['description_contains' => 'WHOLEFDS'], [['set_category', 'Groceries']], moment: 'update-journal');

        $journal = $this->storeViaApi($user, 'WHOLEFDS MKT 10123');

        self::assertSame(0, $journal->categories()->count());
    }

    /**
     * @param  array<string, mixed>  $top
     */
    private function storeViaApi(User $user, string $description, array $top = [], string $destinationName = 'Some shop'): TransactionJournal
    {
        $response = $this->postJson(
            route('api.v1.transactions.store'),
            $top
            + [
                'transactions' => [[
                    'type'             => 'withdrawal',
                    'date'             => '2026-01-15T12:00:00+00:00',
                    'amount'           => '45.67',
                    'description'      => $description,
                    'currency_code'    => 'EUR',
                    'source_id'        => $this->assetAccount($user, 'Checking')->id,
                    'destination_name' => $destinationName
                ]]
            ]
        );
        $response->assertSuccessful();

        return TransactionJournal::query()
            ->where('transaction_group_id', (int) $response->json('data.id'))
            ->firstOrFail();
    }
}
