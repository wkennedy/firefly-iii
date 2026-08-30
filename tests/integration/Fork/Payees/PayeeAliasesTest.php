<?php

/*
 * PayeeAliasesTest.php
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

namespace Tests\integration\Fork\Payees;

use FireflyIII\Enums\AccountTypeEnum;
use FireflyIII\Factory\AccountFactory;
use FireflyIII\Fork\Factory\AccountFactory as ForkAccountFactory;
use FireflyIII\Fork\Models\ForkPayeeAlias;
use FireflyIII\Models\Account;
use FireflyIII\Models\TransactionJournal;
use FireflyIII\User;
use Override;
use Tests\integration\TestCase;
use Tests\integration\Traits\CreatesTransactionGroups;

/**
 * FORK: payee aliases (config fork.payee_aliases).
 *
 * @internal
 *
 * @coversNothing
 */
final class PayeeAliasesTest extends TestCase
{
    use CreatesTransactionGroups;

    public function testAliasesNeverTouchAssetAccountsOrOtherUserGroups(): void
    {
        $user  = $this->createAuthenticatedUser();
        $other = $this->createUser('other@email.com');
        $this->alias($other, ForkPayeeAlias::PREFIX, 'AMAZON', 'Amazon'); // someone else's rule
        $this->actingAs($user);
        $this->store($user, 'withdrawal', 'AMAZON MKTPL*AB12CD34');
        self::assertSame(['AMAZON MKTPL*AB12CD34'], $this->expenseNames($user));

        // an asset account named like an expense alias is left alone
        $this->alias($user, ForkPayeeAlias::PREFIX, 'AMAZON', 'Amazon');
        $asset = app(AccountFactory::class);
        $asset->setUser($user);
        self::assertSame('AMAZON STORE CARD', $asset->findOrCreate('AMAZON STORE CARD', AccountTypeEnum::ASSET->value)->name);
    }

    public function testANewAliasReportsItselfActive(): void
    {
        $user = $this->createAuthenticatedUser();
        $this->actingAs($user);

        // `active` defaults to true in the database. Before the model was read back, creating an
        // alias answered active:false, which reads as "created but switched off".
        $this->postJson(route('api.v1.fork.payee-aliases.store'), [
            'account_type'   => 'expense',
            'match_type'     => 'prefix',
            'pattern'        => 'AMAZON',
            'canonical_name' => 'Amazon'
        ])->assertCreated()->assertJsonPath('data.active', true);
    }

    public function testApiCrudAndMerge(): void
    {
        $user  = $this->createAuthenticatedUser();
        $other = $this->createUser('other@email.com');
        $this->alias($other, ForkPayeeAlias::PREFIX, 'THEIRS', 'Theirs');
        $this->actingAs($user);

        $this->getJson(route('api.v1.fork.payee-aliases.index'))->assertOk()->assertJsonCount(0, 'data');
        $created = $this->postJson(route('api.v1.fork.payee-aliases.store'), [
            'account_type'      => 'expense',
            'match_type'        => 'prefix',
            'pattern'           => 'AMAZON',
            'canonical_name'    => 'Amazon',
            'clean_description' => true
        ]);
        $created->assertCreated()->assertJsonPath('data.account_type', AccountTypeEnum::EXPENSE->value)->assertJsonPath('data.clean_description', true);
        $id = (int) $created->json('data.id');

        $this->postJson(route('api.v1.fork.payee-aliases.store'), [
            'account_type'   => 'expense',
            'match_type'     => 'regex',
            'pattern'        => '(',
            'canonical_name' => 'Broken'
        ])->assertUnprocessable();
        $this->postJson(route('api.v1.fork.payee-aliases.store'), [
            'account_type'   => 'asset',
            'match_type'     => 'prefix',
            'pattern'        => 'X',
            'canonical_name' => 'Y'
        ])->assertUnprocessable();

        $this
            ->putJson(route('api.v1.fork.payee-aliases.update', [$id]), ['canonical_name' => 'Amazon.com', 'order' => 5])
            ->assertOk()
            ->assertJsonPath('data.canonical_name', 'Amazon.com')
            ->assertJsonPath('data.order', 5);
        $this->getJson(route('api.v1.fork.payee-aliases.index'))->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $id);

        config(['fork.payee_aliases' => false]);
        $this->store($user, 'withdrawal', 'AMAZON MKTPL*AB12CD34');
        config(['fork.payee_aliases' => true]);
        $this
            ->postJson(route('api.v1.fork.payee-aliases.merge'), ['dry_run' => true])
            ->assertOk()
            ->assertJsonPath('data.output', fn(string $o): bool => str_contains($o, 'Would merge 1 account(s)'));
        $this->postJson(route('api.v1.fork.payee-aliases.merge'))->assertOk();
        self::assertSame(['Amazon.com'], $this->expenseNames($user));

        $this->deleteJson(route('api.v1.fork.payee-aliases.destroy', [$id]))->assertNoContent();
        $this->getJson(route('api.v1.fork.payee-aliases.index'))->assertJsonCount(0, 'data');
        // the other user's alias is invisible and untouchable
        $theirs = ForkPayeeAlias::query()->where('pattern', 'THEIRS')->firstOrFail();
        $this->deleteJson(route('api.v1.fork.payee-aliases.destroy', [$theirs->id]))->assertNotFound();
    }

    public function testCleanDescriptionRewritesOnlyRawBankStrings(): void
    {
        $user = $this->createAuthenticatedUser();
        $this->actingAs($user);
        $this->alias($user, ForkPayeeAlias::PREFIX, 'AMAZON', 'Amazon', ['clean_description' => true]);

        $raw  = $this->store($user, 'withdrawal', 'AMAZON MKTPL*AB12CD34', 'AMAZON MKTPL*AB12CD34');
        $hand = $this->store($user, 'withdrawal', 'AMAZON MKTPL*EF56GH78', 'Birthday present for mum');

        self::assertSame('Amazon', TransactionJournal::query()->where('transaction_group_id', $raw)->firstOrFail()->description);
        self::assertSame('Birthday present for mum', TransactionJournal::query()->where('transaction_group_id', $hand)->firstOrFail()->description);
    }

    public function testDisabledFlagCreatesFragments(): void
    {
        config(['fork.payee_aliases' => false]);
        $user = $this->createAuthenticatedUser();
        $this->actingAs($user);
        $this->alias($user, ForkPayeeAlias::PREFIX, 'AMAZON', 'Amazon');
        $this->store($user, 'withdrawal', 'AMAZON MKTPL*AB12CD34');

        self::assertSame(['AMAZON MKTPL*AB12CD34'], $this->expenseNames($user));
    }

    public function testFactoryBindingIsTheForkFactory(): void
    {
        self::assertInstanceOf(ForkAccountFactory::class, app(AccountFactory::class));
    }

    public function testFindOrCreateAppliesAliasesForRuleActions(): void
    {
        $user = $this->createAuthenticatedUser();
        $this->alias($user, ForkPayeeAlias::PREFIX, 'AMAZON', 'Amazon');
        $factory = app(AccountFactory::class);
        $factory->setUser($user);

        $account = $factory->findOrCreate('AMAZON MKTPL*ZZ99', AccountTypeEnum::EXPENSE->value);

        self::assertSame('Amazon', $account->name);
        self::assertSame($account->id, $factory->findOrCreate('AMAZON MKTPL*YY88', AccountTypeEnum::EXPENSE->value)->id);
    }

    public function testMergeCommandFoldsExistingFragments(): void
    {
        config(['fork.payee_aliases' => false]);
        $user = $this->createAuthenticatedUser();
        $this->actingAs($user);
        $this->store($user, 'withdrawal', 'AMAZON MKTPL*AB12CD34');
        $this->store($user, 'withdrawal', 'AMAZON MKTPL*EF56GH78');
        $this->store($user, 'withdrawal', 'AMAZON MKTPL*EF56GH78');
        $this->store($user, 'withdrawal', 'Other shop');
        self::assertCount(3, $this->expenseNames($user));

        config(['fork.payee_aliases' => true]);
        $this->alias($user, ForkPayeeAlias::PREFIX, 'AMAZON', 'Amazon');

        $this
            ->artisan('firefly-iii:fork:payees:merge', ['--dry-run' => true])
            ->expectsOutputToContain('Would merge 2 account(s), 0 transaction(s) moved.')
            ->assertExitCode(0);
        self::assertCount(3, $this->expenseNames($user));

        $this->artisan('firefly-iii:fork:payees:merge')->expectsOutputToContain('Merged 2 account(s), 3 transaction(s) moved.')->assertExitCode(0);
        self::assertSame(['Amazon', 'Other shop'], $this->expenseNames($user));
        self::assertSame(3, Account::query()->where('name', 'Amazon')->firstOrFail()->transactions()->count());
        self::assertSame(4, TransactionJournal::query()->where('user_id', $user->id)->count(), 'no journal lost');
    }

    public function testMergeWorksOverHttpNotOnlyFromTheConsole(): void
    {
        $user = $this->createAuthenticatedUser();
        $this->actingAs($user);
        $this->alias($user, 'prefix', 'AMAZON', 'Amazon');

        // Regression: fork commands used to be registered only under runningInConsole(), so this
        // endpoint — which reaches the command through Artisan::call() during an HTTP request —
        // answered "the command does not exist" every time, including in the documented rollout.
        $this
            ->postJson(route('api.v1.fork.payee-aliases.merge'), ['dry_run' => true])
            ->assertOk()
            ->assertJsonStructure(['data' => ['output']]);
    }

    public function testPrefixAliasCollapsesFragmentsIntoOneExpenseAccount(): void
    {
        $user = $this->createAuthenticatedUser();
        $this->actingAs($user);
        $alias = $this->alias($user, ForkPayeeAlias::PREFIX, 'AMAZON', 'Amazon');

        $this->store($user, 'withdrawal', 'AMAZON MKTPL*AB12CD34');
        $this->store($user, 'withdrawal', 'AMAZON MKTPL*EF56GH78');
        $this->store($user, 'withdrawal', 'amazon prime');

        self::assertSame(['Amazon'], $this->expenseNames($user));
        self::assertSame(3, Account::query()->where('name', 'Amazon')->firstOrFail()->transactions()->count());
        self::assertSame(3, $alias->fresh()->hit_count);
        self::assertSame(
            0,
            Account::query()->whereIn('name', ['AMAZON MKTPL*AB12CD34', 'AMAZON MKTPL*EF56GH78', 'amazon prime'])->count(),
            'fragments must never be created'
        );
    }

    public function testPruneEmptyDeletesOnlyUnusedPayees(): void
    {
        $user = $this->createAuthenticatedUser();
        $this->actingAs($user);
        $this->store($user, 'withdrawal', 'Used shop');
        $this->createAccount($user, AccountTypeEnum::EXPENSE, 'Empty shop');
        $this->createAccount($user, AccountTypeEnum::REVENUE, 'Empty employer');
        $this->createAccount($user, AccountTypeEnum::ASSET, 'Empty asset');

        $this
            ->artisan('firefly-iii:fork:payees:prune-empty', ['--dry-run' => true])
            ->expectsOutputToContain('Would delete 2 empty account(s).')
            ->assertExitCode(0);
        $this->artisan('firefly-iii:fork:payees:prune-empty')->expectsOutputToContain('Deleted 2 empty account(s).')->assertExitCode(0);

        self::assertSame(['Used shop'], $this->expenseNames($user));
        self::assertNotNull(Account::query()->where('name', 'Empty asset')->first(), 'asset accounts are never pruned');
    }

    public function testRegexExactAndInactiveAliases(): void
    {
        $user = $this->createAuthenticatedUser();
        $this->actingAs($user);
        $this->alias($user, ForkPayeeAlias::REGEX, '^SQ \*|^SQUARE ', 'Square merchants');
        $this->alias($user, ForkPayeeAlias::EXACT, 'shell oil 1234', 'Shell');
        $this->alias($user, ForkPayeeAlias::PREFIX, 'NETFLIX', 'Netflix', ['active' => false]);

        $this->store($user, 'withdrawal', 'SQ *COFFEE PLACE');
        $this->store($user, 'withdrawal', 'SHELL OIL 1234');
        $this->store($user, 'withdrawal', 'NETFLIX.COM');
        $this->store($user, 'withdrawal', 'Other shop');

        self::assertSame(['NETFLIX.COM', 'Other shop', 'Shell', 'Square merchants'], $this->expenseNames($user));
    }

    public function testRevenueAliasAppliesToDepositSources(): void
    {
        $user = $this->createAuthenticatedUser();
        $this->actingAs($user);
        $this->alias($user, ForkPayeeAlias::PREFIX, 'ACME CORP PAYROLL', 'Acme Corp', [], AccountTypeEnum::REVENUE);

        $this->store($user, 'deposit', 'ACME CORP PAYROLL 2026-07');
        $this->store($user, 'deposit', 'ACME CORP PAYROLL 2026-08');

        $names = Account::query()
            ->where('user_id', $user->id)
            ->whereRelation('accountType', 'type', AccountTypeEnum::REVENUE->value)
            ->pluck('name')
            ->sort()
            ->values()
            ->all();
        self::assertSame(['Acme Corp'], $names);
    }

    #[Override]
    protected function setUp(): void
    {
        parent::setUp();
        config(['fork.payee_aliases' => true]);
    }

    /**
     * @param array<string, mixed> $extra
     */
    private function alias(
        User $user,
        string $matchType,
        string $pattern,
        string $canonical,
        array $extra = [],
        AccountTypeEnum $type = AccountTypeEnum::EXPENSE
    ): ForkPayeeAlias {
        return ForkPayeeAlias::query()->create(
            $extra
            + [
                'user_group_id'  => $user->user_group_id,
                'account_type'   => $type->value,
                'match_type'     => $matchType,
                'pattern'        => $pattern,
                'canonical_name' => $canonical,
                'active'         => true
            ]
        );
    }

    /**
     * @return list<string>
     */
    private function expenseNames(User $user): array
    {
        return Account::query()
            ->where('user_id', $user->id)
            ->whereRelation('accountType', 'type', AccountTypeEnum::EXPENSE->value)
            ->pluck('name')
            ->sort()
            ->values()
            ->all();
    }

    private function store(User $user, string $type, string $payee, null|string $description = null): int
    {
        $checking    = $this->assetAccount($user, 'Checking');
        $transaction = [
            'type'          => $type,
            'date'          => '2026-07-15T12:00:00+00:00',
            'amount'        => '19.99',
            'description'   => $description ?? $payee,
            'currency_code' => 'EUR'
        ];
        $transaction += 'deposit' === $type
            ? ['source_name' => $payee, 'destination_id' => $checking->id]
            : ['source_id' => $checking->id, 'destination_name' => $payee];
        $response = $this->postJson(route('api.v1.transactions.store'), ['transactions' => [$transaction]]);
        $response->assertSuccessful();

        return (int) $response->json('data.id');
    }
}
