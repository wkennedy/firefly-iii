<?php

/*
 * CategoryBudgetsTest.php
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

namespace Tests\integration\Fork\Budgets;

use FireflyIII\Fork\Models\ForkCategoryBudget;
use FireflyIII\Models\Budget;
use FireflyIII\Models\Category;
use FireflyIII\Models\TransactionJournal;
use FireflyIII\User;
use Override;
use Tests\integration\TestCase;
use Tests\integration\Traits\CreatesTransactionGroups;

/**
 * FORK: category → default budget (config fork.category_budgets).
 *
 * @internal
 *
 * @coversNothing
 */
final class CategoryBudgetsTest extends TestCase
{
    use CreatesTransactionGroups;

    public function testApiCrud(): void
    {
        $user   = $this->createAuthenticatedUser();
        $other  = $this->createUser('other@email.com');
        $theirs = $this->map($other, 'Groceries', 'Their budget');
        $this->actingAs($user);
        $category = Category::create(['user_id' => $user->id, 'user_group_id' => $user->user_group_id, 'name' => 'Groceries']);
        $budget   = Budget::create([
            'user_id'       => $user->id,
            'user_group_id' => $user->user_group_id,
            'name'          => 'Groceries budget',
            'active'        => true,
            'order'         => 1
        ]);
        $other2 = Budget::create(['user_id' => $user->id, 'user_group_id' => $user->user_group_id, 'name' => 'Food', 'active' => true, 'order' => 2]);

        $this->getJson(route('api.v1.fork.category-budgets.index'))->assertOk()->assertJsonCount(0, 'data');
        $created = $this->postJson(route('api.v1.fork.category-budgets.store'), ['category_id' => $category->id, 'budget_id' => $budget->id]);
        $created->assertCreated()->assertJsonPath('data.category_name', 'Groceries')->assertJsonPath('data.budget_name', 'Groceries budget');
        $id = (int) $created->json('data.id');

        // one budget per category: posting again replaces
        $this->postJson(route('api.v1.fork.category-budgets.store'), [
            'category_id' => $category->id,
            'budget_id'   => $other2->id
        ])->assertOk()->assertJsonPath('data.id', $id)->assertJsonPath('data.budget_name', 'Food');
        $this->getJson(route('api.v1.fork.category-budgets.index'))->assertJsonCount(1, 'data');
        // foreign ids are rejected
        $this->postJson(route('api.v1.fork.category-budgets.store'), [
            'category_id' => $theirs->category_id,
            'budget_id'   => $budget->id
        ])->assertUnprocessable();
        $this->deleteJson(route('api.v1.fork.category-budgets.destroy', [$theirs->id]))->assertNotFound();

        $this->deleteJson(route('api.v1.fork.category-budgets.destroy', [$id]))->assertNoContent();
        self::assertSame(1, ForkCategoryBudget::query()->count(), 'only the other user group\'s mapping remains');
    }

    public function testBackfillCommandAndApiApply(): void
    {
        config(['fork.category_budgets' => false]);
        $user = $this->createAuthenticatedUser();
        $this->actingAs($user);
        $a = $this->store($user, ['category_name' => 'Groceries', 'date' => '2026-07-02T12:00:00+00:00']);
        $b = $this->store($user, ['category_name' => 'Groceries', 'date' => '2026-07-20T12:00:00+00:00']);
        $c = $this->store($user, ['category_name' => 'Groceries', 'date' => '2026-06-01T12:00:00+00:00']);
        config(['fork.category_budgets' => true]);
        $this->map($user, 'Groceries', 'Groceries budget');

        $this
            ->artisan('firefly-iii:fork:budgets:apply-defaults', ['--start' => '2026-07-01', '--end' => '2026-07-31', '--dry-run' => true])
            ->expectsOutputToContain('2 withdrawal(s) with a category and no budget between 2026-07-01 and 2026-07-31; would set 2.')
            ->assertExitCode(0);
        self::assertNull($this->journal($a)->budgets()->first());

        $this->postJson(route('api.v1.fork.category-budgets.apply'), [
            'start' => '2026-07-01',
            'end'   => '2026-07-31'
        ])->assertOk()->assertJsonPath('data.applied', 2)->assertJsonPath('data.budgets.Groceries budget', 2);
        self::assertSame('Groceries budget', $this->journal($a)->budgets()->first()?->name);
        self::assertSame('Groceries budget', $this->journal($b)->budgets()->first()?->name);
        self::assertNull($this->journal($c)->budgets()->first(), 'outside the range');

        $this
            ->artisan('firefly-iii:fork:budgets:apply-defaults', ['--start' => '2026-07-01', '--end' => '2026-07-31'])
            ->expectsOutputToContain('set 0.')
            ->assertExitCode(0);
    }

    public function testBudgetIsAppliedOnCategoryCorrection(): void
    {
        $user = $this->createAuthenticatedUser();
        $this->actingAs($user);
        $this->map($user, 'Groceries', 'Groceries budget');
        $id = $this->store($user, []); // no category on import
        self::assertNull($this->journal($id)->budgets()->first());

        // the categorizer (or a human) sets the category afterwards
        $journalId = (int) $this->journal($id)->id;
        $this->putJson(route('api.v1.transactions.update', [$id]), [
            'fire_webhooks' => false,
            'transactions'  => [['transaction_journal_id' => $journalId, 'category_name' => 'Groceries']]
        ])->assertSuccessful();

        self::assertSame('Groceries budget', $this->journal($id)->budgets()->first()?->name);
    }

    public function testBudgetIsAppliedOnStoreWhenCategoryIsMapped(): void
    {
        $user = $this->createAuthenticatedUser();
        $this->actingAs($user);
        $this->map($user, 'Groceries', 'Groceries budget');

        $id = $this->store($user, ['category_name' => 'Groceries']);

        self::assertSame('Groceries budget', $this->journal($id)->budgets()->first()?->name);
    }

    public function testDepositsTransfersUnmappedAndInactiveAreIgnored(): void
    {
        $user = $this->createAuthenticatedUser();
        $this->actingAs($user);
        $mapping  = $this->map($user, 'Groceries', 'Groceries budget');
        $checking = $this->assetAccount($user, 'Checking');
        $savings  = $this->assetAccount($user, 'Savings');

        $deposit  = $this->store($user, ['type' => 'deposit', 'category_name' => 'Groceries', 'source_name' => 'Refund', 'destination_id' => $checking->id]);
        $transfer = $this->store($user, ['type' => 'transfer', 'category_name' => 'Groceries', 'source_id' => $checking->id, 'destination_id' => $savings->id]);
        $unmapped = $this->store($user, ['category_name' => 'Dining']);
        self::assertNull($this->journal($deposit)->budgets()->first());
        self::assertNull($this->journal($transfer)->budgets()->first());
        self::assertNull($this->journal($unmapped)->budgets()->first());

        $mapping->budget->update(['active' => false]);
        $inactive = $this->store($user, ['category_name' => 'Groceries']);
        self::assertNull($this->journal($inactive)->budgets()->first());
    }

    public function testDisabledFlagAndOtherUserGroupsDoNothing(): void
    {
        $user  = $this->createAuthenticatedUser();
        $other = $this->createUser('other@email.com');
        $this->map($other, 'Groceries', 'Their budget');
        $this->actingAs($user);
        $id = $this->store($user, ['category_name' => 'Groceries']);
        self::assertNull($this->journal($id)->budgets()->first(), 'another user group\'s mapping must not apply');

        config(['fork.category_budgets' => false]);
        $this->map($user, 'Groceries', 'Groceries budget');
        $off = $this->store($user, ['category_name' => 'Groceries']);
        self::assertNull($this->journal($off)->budgets()->first());
    }

    public function testExistingBudgetIsNeverReplaced(): void
    {
        $user = $this->createAuthenticatedUser();
        $this->actingAs($user);
        $this->map($user, 'Groceries', 'Groceries budget');
        Budget::create(['user_id' => $user->id, 'user_group_id' => $user->user_group_id, 'name' => 'Holiday fund', 'active' => true, 'order' => 2]);

        $id = $this->store($user, ['category_name' => 'Groceries', 'budget_name' => 'Holiday fund']);

        self::assertSame('Holiday fund', $this->journal($id)->budgets()->first()?->name);
    }

    #[Override]
    protected function setUp(): void
    {
        parent::setUp();
        config(['fork.category_budgets' => true]);
    }

    private function journal(int $groupId): TransactionJournal
    {
        return TransactionJournal::query()->where('transaction_group_id', $groupId)->firstOrFail();
    }

    private function map(User $user, string $categoryName, string $budgetName): ForkCategoryBudget
    {
        $category = Category::query()->firstOrCreate(['user_group_id' => $user->user_group_id, 'name' => $categoryName], ['user_id' => $user->id]);
        $budget   = Budget::query()->firstOrCreate(['user_group_id' => $user->user_group_id, 'name' => $budgetName], [
            'user_id' => $user->id,
            'active'  => true,
            'order'   => 1
        ]);

        return ForkCategoryBudget::query()->create(['user_group_id' => $user->user_group_id, 'category_id' => $category->id, 'budget_id' => $budget->id]);
    }

    /**
     * @param array<string, mixed> $overrides
     */
    private function store(User $user, array $overrides): int
    {
        $defaults = [
            'type'          => 'withdrawal',
            'date'          => '2026-07-15T12:00:00+00:00',
            'amount'        => '19.99',
            'description'   => 'WHOLEFDS MKT',
            'currency_code' => 'EUR'
        ];
        if (!array_key_exists('source_id', $overrides) && !array_key_exists('source_name', $overrides)) {
            $defaults['source_id'] = $this->assetAccount($user, 'Checking')->id;
        }
        if (!array_key_exists('destination_id', $overrides) && !array_key_exists('destination_name', $overrides)) {
            $defaults['destination_name'] = 'Some shop';
        }
        $transaction = $overrides + $defaults;
        $response    = $this->postJson(route('api.v1.transactions.store'), ['transactions' => [$transaction]]);
        $response->assertSuccessful();

        return (int) $response->json('data.id');
    }
}
