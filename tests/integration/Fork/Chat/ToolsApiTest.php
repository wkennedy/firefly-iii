<?php

/*
 * ToolsApiTest.php
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

namespace Tests\integration\Fork\Chat;

use Carbon\Carbon;
use FireflyIII\User;
use Tests\integration\TestCase;
use Tests\integration\Traits\CreatesTransactionGroups;

/**
 * FORK: /api/v1/fork/chat/tools (config fork.chat_tools_api) — the read-only tool surface the stdio
 * MCP shim proxies.
 *
 * The property that matters here is the one a config flag cannot give you: a personal access token
 * pointed at this endpoint can read, and cannot write, whatever else is switched on.
 *
 * @internal
 *
 * @coversNothing
 */
final class ToolsApiTest extends TestCase
{
    use CreatesTransactionGroups;

    private User $user;

    public function testAnUnknownToolIsNotFound(): void
    {
        $this->postJson(route('api.v1.fork.chat.tools.execute', ['tool' => 'delete_everything']))->assertNotFound();
    }

    public function testItAnswersOnlyForTheTokensOwnUser(): void
    {
        $other = $this->createUser('other@email.com');
        $this->spend($other, 'Groceries', '999.00', '2026-05-02', 'THEIRS');
        $this->spend($this->user, 'Groceries', '25.00', '2026-05-03', 'MINE');

        $data  = $this
            ->postJson(route('api.v1.fork.chat.tools.execute', ['tool' => 'search_transactions']), [
                'arguments' => ['start' => '2026-05-01', 'end' => '2026-05-31'],
            ])
            ->assertOk()
            ->json('data')
        ;
        self::assertSame(1, $data['matched']);
        self::assertSame('MINE', $data['transactions'][0]['description']);
    }

    public function testItExecutesAReadTool(): void
    {
        $this->spend($this->user, 'Groceries', '112.44', '2026-05-06', 'WHOLEFDS');

        $this
            ->postJson(route('api.v1.fork.chat.tools.execute', ['tool' => 'sum_by_category']), [
                'arguments' => ['start' => '2026-05-01', 'end' => '2026-05-31'],
            ])
            ->assertOk()
            ->assertJsonPath('data.totals.0.category', 'Groceries')
            ->assertJsonPath('data.totals.0.amount', '112.44')
        ;
    }

    public function testItIsInvisibleWhenTheFlagIsOff(): void
    {
        config(['fork.chat_tools_api' => false]);

        $this->getJson(route('api.v1.fork.chat.tools.index'))->assertNotFound();
        $this->postJson(route('api.v1.fork.chat.tools.execute', ['tool' => 'list_categories']))->assertNotFound();
    }

    public function testItListsReadToolsAndNeverTheWriteOne(): void
    {
        // Writes are ON for the in-app chat, which is exactly when this must still hold.
        config(['fork.chat_writes' => true]);

        $names = array_column($this->getJson(route('api.v1.fork.chat.tools.index'))->assertOk()->json('data'), 'name');

        self::assertContains('sum_by_category', $names);
        self::assertContains('calculate', $names);
        self::assertNotContains('propose_category_change', $names, 'the API must not offer a tool that can lead to a write');
        self::assertCount(11, $names, 'the eleven read tools');
    }

    public function testItRefusesTheWriteToolEvenWhenWritesAreOn(): void
    {
        config(['fork.chat_writes' => true]);
        $this->spend($this->user, 'Shopping', '40.00', '2026-05-04', 'AMAZON MKTPL');

        // From out here the write tool does not exist, and the body says why rather than leaving a
        // reader hunting for a routing problem.
        $this
            ->postJson(route('api.v1.fork.chat.tools.execute', ['tool' => 'propose_category_change']), [
                'arguments' => ['transaction_id' => 1, 'category' => 'Groceries'],
            ])
            ->assertNotFound()
            ->assertJsonPath('message', 'There is no read-only tool called "propose_category_change". This endpoint never exposes tools that can change data.')
        ;
    }

    public function testItWantsAToken(): void
    {
        // Passport, like the rest of the fork API.
        $this->app['auth']->forgetGuards();
        $this->getJson(route('api.v1.fork.chat.tools.index'))->assertUnauthorized();
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpForkTestSupport();
        config(['fork.chat_tools_api' => true, 'fork.chat_writes' => false, 'fork.chat_max_rows' => 50, 'fork.chat_max_result_bytes' => 12000]);
        $this->user = $this->createAuthenticatedUser();
        $this->actingAs($this->user);
    }

    private function spend(User $user, string $category, string $amount, string $date, string $description): void
    {
        $this->createWithdrawal($user, [
            'category_name' => $category,
            'amount'        => $amount,
            'date'          => Carbon::parse(sprintf('%s 12:00:00', $date), 'UTC'),
            'description'   => $description,
            'currency_code' => 'EUR',
        ]);
    }
}
