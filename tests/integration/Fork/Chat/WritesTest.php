<?php

/*
 * WritesTest.php
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
use FireflyIII\Fork\Chat\ChatCompletionClient;
use FireflyIII\Fork\Chat\ProposalStore;
use FireflyIII\Fork\Chat\ToolRegistry;
use FireflyIII\Models\Category;
use FireflyIII\Models\TransactionJournal;
use FireflyIII\User;
use Tests\integration\TestCase;
use Tests\integration\Traits\CreatesTransactionGroups;

/**
 * FORK: the one thing the chat can change (config fork.chat_writes).
 *
 * The property under test throughout is that the model proposes and a person disposes: a tool call
 * never writes, the token never reaches the model, and confirming happens on a route no tool can
 * call. Most of these tests assert on the database AFTER a turn the model believes went its way.
 *
 * @internal
 *
 * @coversNothing
 */
final class WritesTest extends TestCase
{
    use CreatesTransactionGroups;

    private FakeCompletionClient $model;

    private User $user;

    public function testAConfirmationCanOnlyBeUsedOnce(): void
    {
        $journal = $this->journalFor('Shopping');
        $token   = $this->propose($journal->id, 'Household');
        $store   = app(ProposalStore::class);

        self::assertNotNull($store->take($token, $this->user->id));
        self::assertNull($store->take($token, $this->user->id), 'a replayed confirmation is simply unknown');
    }

    public function testAConfirmationExpires(): void
    {
        $journal = $this->journalFor('Shopping');
        $token   = $this->propose($journal->id, 'Household');

        $this->travel(16)->minutes();
        self::assertNull(app(ProposalStore::class)->take($token, $this->user->id));

        $this->travelBack();
        $this->applyToken($token)->assertStatus(410);
        self::assertSame('Shopping', $this->categoryOf($journal), 'an expired confirmation writes nothing');
    }

    public function testConfirmingAppliesTheChange(): void
    {
        $journal  = $this->journalFor('Shopping');
        $token    = $this->propose($journal->id, 'Household');

        $response = $this->applyToken($token);
        $response->assertOk()->assertJsonPath('data.applied', true)->assertJsonPath('data.category', 'Household');
        self::assertFalse($response->json('data.overruled'));
        self::assertSame('Household', $this->categoryOf($journal));
    }

    public function testConfirmingSomethingThatMovedUnderneathIsRefused(): void
    {
        $journal = $this->journalFor('Shopping');
        $token   = $this->propose($journal->id, 'Household');

        // The categorizer, a rule or another window got there first.
        $this->setCategory($journal, 'Groceries');

        $this->applyToken($token)
            ->assertStatus(409)
            ->assertJsonPath('message', 'This transaction is now "Groceries", not "Shopping" as it was when the change was suggested. Nothing was changed.')
        ;
        self::assertSame('Groceries', $this->categoryOf($journal), 'the newer category survives');
    }

    public function testProposingWritesNothingEvenWhenTheModelSaysOtherwise(): void
    {
        $journal = $this->journalFor('Shopping');

        // The model does what a confident model does: calls the tool and announces success.
        $this->model
            ->willCall('propose_category_change', ['transaction_id' => $journal->id, 'category' => 'Household'])
            ->willSay('Done - I have changed it to Household.')
        ;
        $response = $this->postJson(route('fork.chat.send'), ['message' => 'that one is household']);
        $response->assertOk();

        self::assertSame('Shopping', $this->categoryOf($journal), 'the ledger is untouched until a person confirms');
        $card     = $response->json('data.proposals.0');
        self::assertSame('category_change', $card['type']);
        self::assertSame('Shopping', $card['from']);
        self::assertSame('Household', $card['to']);
        self::assertSame('40.00', $card['amount']);

        // The token travels to the browser, never through the model.
        $sent     = json_encode($this->model->calls[1]['messages']);
        self::assertStringNotContainsString($card['token'], (string) $sent, 'the model must not be handed the confirmation token');
        self::assertStringContainsString('NOTHING HAS CHANGED YET', (string) $sent);
    }

    public function testAProposalMadeWhileStreamingSurvivesToTheConfirmation(): void
    {
        $journal = $this->journalFor('Shopping');
        $this->model
            ->willCall('propose_category_change', ['transaction_id' => $journal->id, 'category' => 'Household'])
            ->willSay('Confirm the card and I will change it.')
        ;

        // Regression: proposals used to live in the session, and StartSession has already written
        // the session by the time a streamed response's callback runs — so the proposal vanished and
        // every confirmation came back "expired". Nothing but a real click through the streaming
        // endpoint showed it, hence this test.
        $stream = $this->post(route('fork.chat.stream'), ['message' => 'that one is household'], ['Accept' => 'text/event-stream']);
        $stream->assertOk();

        $token  = null;
        foreach ($this->events($stream->streamedContent()) as $event) {
            if ('done' === $event['event']) {
                $token = $event['data']['proposals'][0]['token'] ?? null;
            }
        }
        self::assertNotNull($token, 'the finished turn carries the confirmation card');

        $this->applyToken((string) $token)->assertOk()->assertJsonPath('data.category', 'Household');
        self::assertSame('Household', $this->categoryOf($journal));
    }

    public function testSomebodyElsesTransactionCannotBeProposed(): void
    {
        $other   = $this->createUser('other@email.com');
        $group   = $this->createWithdrawal($other, [
            'category_name' => 'Shopping',
            'amount'        => '99.00',
            'date'          => Carbon::parse('2026-05-04 12:00:00', 'UTC'),
            'description'   => 'THEIRS',
            'currency_code' => 'EUR',
        ]);
        $journal = $group->transactionJournals()->first();

        $result  = app(ToolRegistry::class)->execute(
            $this->user,
            'propose_category_change',
            (string) json_encode(['transaction_id' => $journal->id, 'category' => 'Household'])
        );
        self::assertStringContainsString(sprintf('there is no transaction #%d', $journal->id), $result['error']);
        self::assertSame([], app(ProposalStore::class)->fresh());
    }

    public function testTheWriteToolIsAbsentWhenWritesAreOff(): void
    {
        config(['fork.chat_writes' => false]);
        $journal = $this->journalFor('Shopping');

        self::assertNotContains('propose_category_change', app(ToolRegistry::class)->names());

        // Even if a model asks for it by name, there is nothing behind the name.
        $result  = app(ToolRegistry::class)->execute(
            $this->user,
            'propose_category_change',
            (string) json_encode(['transaction_id' => $journal->id, 'category' => 'Household'])
        );
        self::assertStringContainsString('there is no tool called "propose_category_change"', $result['error']);

        // And the route that writes is not there either.
        $this->postJson(route('fork.chat.apply'), ['token' => 'whatever'])->assertNotFound();
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpForkTestSupport();
        config([
            'fork.chat'            => true,
            'fork.chat_writes'     => true,
            'fork.chat_max_rounds' => 4,
            'fork.chat_history'    => 12,
            'fork.chat_max_rows'   => 50,
            'fork.learned_rules'   => false,
        ]);
        $this->model = new FakeCompletionClient();
        $this->app->instance(ChatCompletionClient::class, $this->model);
        $this->user  = $this->createAuthenticatedUser();
        $this->actingAs($this->user);
    }

    private function applyToken(string $token): \Illuminate\Testing\TestResponse
    {
        return $this->postJson(route('fork.chat.apply'), ['token' => $token]);
    }

    /**
     * @return list<array{event: string, data: array<string, mixed>}>
     */
    private function events(string $stream): array
    {
        $events = [];
        foreach (array_filter(explode("\n\n", trim($stream))) as $block) {
            $event = '';
            $data  = '';
            foreach (explode("\n", $block) as $line) {
                if (str_starts_with($line, 'event: ')) {
                    $event = substr($line, 7);
                }
                if (str_starts_with($line, 'data: ')) {
                    $data = substr($line, 6);
                }
            }
            $events[] = ['event' => $event, 'data' => json_decode($data, true, 512, JSON_THROW_ON_ERROR)];
        }

        return $events;
    }

    private function categoryOf(TransactionJournal $journal): ?string
    {
        return $journal->fresh()?->categories()->first()?->name;
    }

    private function journalFor(string $category): TransactionJournal
    {
        $group = $this->createWithdrawal($this->user, [
            'category_name' => $category,
            'amount'        => '40.00',
            'date'          => Carbon::parse('2026-05-04 12:00:00', 'UTC'),
            'description'   => 'AMAZON MKTPL*AB12CD34',
            'currency_code' => 'EUR',
        ]);
        // The target category has to exist before it can be proposed.
        Category::firstOrCreate(['user_id' => $this->user->id, 'user_group_id' => $this->user->user_group_id, 'name' => 'Household']);
        Category::firstOrCreate(['user_id' => $this->user->id, 'user_group_id' => $this->user->user_group_id, 'name' => 'Groceries']);

        return $group->transactionJournals()->first();
    }

    private function propose(int $journalId, string $category): string
    {
        $result = app(ToolRegistry::class)->execute(
            $this->user,
            'propose_category_change',
            (string) json_encode(['transaction_id' => $journalId, 'category' => $category])
        );
        self::assertTrue($result['proposed'], (string) json_encode($result));
        self::assertArrayNotHasKey('token', $result, 'the tool result the model sees carries no token');

        return (string) app(ProposalStore::class)->fresh()[0]['token'];
    }

    private function setCategory(TransactionJournal $journal, string $name): void
    {
        $category = Category::firstOrCreate(['user_id' => $this->user->id, 'user_group_id' => $this->user->user_group_id, 'name' => $name]);
        $journal->categories()->sync([$category->id]);
    }
}
