<?php

/*
 * LearnedRulesTest.php
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

use Carbon\Carbon;
use FireflyIII\Fork\Services\JournalUpdateService as ForkUpdateService;
use FireflyIII\Models\Rule;
use FireflyIII\Models\RuleGroup;
use FireflyIII\Models\TransactionJournal;
use FireflyIII\Services\Internal\Update\JournalUpdateService;
use FireflyIII\User;
use Illuminate\Support\Facades\Artisan;
use Laravel\Passport\AccessToken;
use Laravel\Passport\Passport;
use Override;
use Tests\integration\TestCase;
use Tests\integration\Traits\CreatesTransactionGroups;

/**
 * FORK: learned rules from category corrections (config fork.learned_rules).
 *
 * @internal
 *
 * @coversNothing
 */
final class LearnedRulesTest extends TestCase
{
    use CreatesTransactionGroups;

    public function testAutomationNeverTeaches(): void
    {
        $user = $this->createAuthenticatedUser();
        $this->actingAs($user);
        $id = $this->store($user, 'WHOLEFDS MKT 10123', 'Whole Foods');

        $this->correct($id, 'Groceries', ['X-Fork-Source' => 'automation']);
        self::assertSame(0, Rule::query()->count());
        self::assertSame('Groceries', $this->journal($id)->categories()->first()?->name, 'the correction itself still applies');

        $this->correct($id, 'Groceries'); // no change → nothing to learn
        self::assertSame(0, Rule::query()->count());
    }

    public function testDepositsLearnOnTheSourceAndTransfersNeverLearn(): void
    {
        $user = $this->createAuthenticatedUser();
        $this->actingAs($user);
        $checking = $this->assetAccount($user, 'Checking');
        $savings  = $this->assetAccount($user, 'Savings');

        $deposit = $this->store($user, 'ACME PAYROLL', 'Acme Corp', ['type' => 'deposit', 'source_name' => 'Acme Corp', 'destination_id' => $checking->id]);
        $this->correct($deposit, 'Salary');
        $rule = Rule::query()->firstOrFail();
        self::assertSame(
            ['user_action' => 'store-journal', 'source_account_is' => 'Acme Corp'],
            $rule->ruleTriggers()->orderBy('order')->pluck('trigger_value', 'trigger_type')->all()
        );

        $transfer = $this->store($user, 'TO SAVINGS', 'Savings', ['type' => 'transfer', 'source_id' => $checking->id, 'destination_id' => $savings->id]);
        $this->correct($transfer, 'Moving money');
        self::assertSame(1, Rule::query()->count());
    }

    public function testDisabledFlagLearnsNothingAndRulesAreScopedToTheUser(): void
    {
        $user = $this->createAuthenticatedUser();
        $this->actingAs($user);
        $id = $this->store($user, 'WHOLEFDS MKT 10123', 'Whole Foods');

        config(['fork.learned_rules' => false]);
        $this->correct($id, 'Groceries');
        self::assertSame(0, Rule::query()->count());

        config(['fork.learned_rules' => true]);
        $this->correct($id, 'Food');
        $rule = Rule::query()->firstOrFail();
        self::assertSame($user->id, (int) $rule->user_id);
        self::assertSame((int) $user->user_group_id, (int) $rule->user_group_id);
        self::assertSame((int) $user->user_group_id, (int) $rule->ruleGroup->user_group_id);
    }

    public function testHumanCorrectionCreatesARuleThatFiresOnTheNextImport(): void
    {
        $user = $this->createAuthenticatedUser();
        $this->actingAs($user);
        $id = $this->store($user, 'WHOLEFDS MKT 10123', 'Whole Foods');

        $this->correct($id, 'Groceries');

        $group = RuleGroup::query()->where('user_id', $user->id)->where('title', 'Learned (fork)')->firstOrFail();
        self::assertTrue((bool) $group->active);
        $rule = Rule::query()->where('rule_group_id', $group->id)->firstOrFail();
        self::assertSame('Learned: Whole Foods', $rule->title);
        self::assertTrue((bool) $rule->active);
        self::assertTrue((bool) $rule->strict);
        self::assertSame(
            ['user_action' => 'store-journal', 'destination_account_is' => 'Whole Foods'],
            $rule->ruleTriggers()->orderBy('order')->pluck('trigger_value', 'trigger_type')->all()
        );
        self::assertSame(['set_category' => 'Groceries'], $rule->ruleActions()->pluck('action_value', 'action_type')->all());

        // the next import of that payee is categorised by Firefly itself
        $next = $this->store($user, 'WHOLEFDS MKT 20456', 'Whole Foods');
        self::assertSame('Groceries', $this->journal($next)->categories()->first()?->name);
    }

    public function testReCorrectionUpdatesAndClearingDeactivates(): void
    {
        $user = $this->createAuthenticatedUser();
        $this->actingAs($user);
        $id = $this->store($user, 'SHELL OIL 1234', 'Shell');

        $this->correct($id, 'Transport');
        $this->correct($id, 'Fuel');
        self::assertSame(1, Rule::query()->count(), 'one rule per payee');
        $rule = Rule::query()->firstOrFail();
        self::assertSame('Fuel', $rule->ruleActions()->where('action_type', 'set_category')->value('action_value'));

        $this->correct($id, null);
        self::assertFalse((bool) $rule->fresh()->active, 'clearing the category deactivates, never deletes');
        $this->correct($id, 'Transport');
        self::assertTrue((bool) $rule->fresh()->active);
        self::assertSame('Transport', $rule->ruleActions()->where('action_type', 'set_category')->value('action_value'));
    }

    public function testServiceBindingIsTheForkService(): void
    {
        self::assertInstanceOf(ForkUpdateService::class, app(JournalUpdateService::class));
    }

    public function testWritesWithAListedTokenNameNeverTeach(): void
    {
        config(['fork.automation_token_names' => 'Categorizer, report-bot']);
        $user = $this->createAuthenticatedUser();
        $this->actingAs($user);
        $id = $this->store($user, 'WHOLEFDS MKT 10123', 'Whole Foods');

        $this->actAsToken($user, 'categorizer');
        $this->correct($id, 'Groceries');
        self::assertSame(0, Rule::query()->count(), 'the categorizer token must not teach');
        self::assertSame('Groceries', $this->journal($id)->categories()->first()?->name);

        $this->actAsToken($user, 'phone');
        $this->correct($id, 'Food');
        self::assertSame(1, Rule::query()->count(), 'an unlisted token is a person');

        config(['fork.automation_token_names' => '']);
        $this->actAsToken($user, 'categorizer');
        $this->correct($id, 'Groceries');
        self::assertSame(
            'Groceries',
            Rule::query()->firstOrFail()->ruleActions()->where('action_type', 'set_category')->value('action_value'),
            'with no names configured every token is a person'
        );
    }

    #[Override]
    protected function setUp(): void
    {
        parent::setUp();
        config(['fork.learned_rules' => true]);
    }

    /**
     * Authenticate the API guard with a real personal access token row named $name.
     */
    private function actAsToken(User $user, string $name): void
    {
        $client = Passport::client()->query()->first();
        if (null === $client) {
            Artisan::call('passport:client', ['--personal' => true, '--name' => 'test', '--no-interaction' => true]);
            $client = Passport::client()->query()->firstOrFail();
        }
        $token = Passport::token()->forceFill([
            'id'         => bin2hex(random_bytes(20)),
            'user_id'    => $user->id,
            'client_id'  => $client->getKey(),
            'name'       => $name,
            'scopes'     => [],
            'revoked'    => false,
            'expires_at' => Carbon::now()->addDay()
        ]);
        $token->save();
        Passport::actingAs($user, [], 'api', $client);
        $user->withAccessToken(new AccessToken([
            'oauth_access_token_id' => $token->getKey(),
            'oauth_client_id'       => $client->getKey(),
            'oauth_user_id'         => $user->id,
            'oauth_scopes'          => []
        ]));
    }

    /**
     * @param array<string, string> $headers
     */
    private function correct(int $groupId, null|string $category, array $headers = []): void
    {
        $journalId = (int) $this->journal($groupId)->id;
        $this->putJson(
            route('api.v1.transactions.update', [$groupId]),
            ['fire_webhooks' => false, 'transactions' => [['transaction_journal_id' => $journalId, 'category_name' => $category ?? '']]],
            $headers
        )->assertSuccessful();
    }

    private function journal(int $groupId): TransactionJournal
    {
        return TransactionJournal::query()->where('transaction_group_id', $groupId)->firstOrFail();
    }

    /**
     * @param array<string, mixed> $overrides
     */
    private function store(User $user, string $description, string $payee, array $overrides = []): int
    {
        $defaults = [
            'type'          => 'withdrawal',
            'date'          => '2026-07-15T12:00:00+00:00',
            'amount'        => '42.00',
            'description'   => $description,
            'currency_code' => 'EUR'
        ];
        if (!array_key_exists('source_id', $overrides) && !array_key_exists('source_name', $overrides)) {
            $defaults['source_id'] = $this->assetAccount($user, 'Checking')->id;
        }
        if (!array_key_exists('destination_id', $overrides) && !array_key_exists('destination_name', $overrides)) {
            $defaults['destination_name'] = $payee;
        }
        $response = $this->postJson(route('api.v1.transactions.store'), ['transactions' => [$overrides + $defaults]]);
        $response->assertSuccessful();

        return (int) $response->json('data.id');
    }
}
