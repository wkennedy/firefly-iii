<?php

/*
 * Sha3SignatureGeneratorTest.php
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

namespace Tests\unit\Fork\Helpers\Webhook;

use Carbon\Carbon;
use FireflyIII\Exceptions\FireflyException;
use FireflyIII\Helpers\Webhook\Sha3SignatureGenerator;
use FireflyIII\Models\Webhook;
use FireflyIII\Models\WebhookMessage;
use Override;
use Tests\integration\TestCase;

/**
 * FORK: the categorizer verifies this signature; pin the exact scheme
 * ("t=<unix ts>,v1=<HMAC-SHA3-256 of '<ts>.<json>' with the webhook secret>").
 * The doc comment in the generator says two dots; the code — and this test — use one.
 *
 * @internal
 *
 * @coversNothing
 */
final class Sha3SignatureGeneratorTest extends TestCase
{
    public function testMessageOfDeletedWebhookCannotBeSigned(): void
    {
        $message          = new WebhookMessage();
        $message->message = ['uuid' => 'abc'];
        $message->setRelation('webhook', null);

        $this->expectException(FireflyException::class);
        new Sha3SignatureGenerator()->generate($message);
    }

    public function testSignatureDependsOnSecretAndTimestamp(): void
    {
        Carbon::setTestNow(Carbon::create(2026, 8, 28, 12, 0, 0, 'UTC'));
        $payload = ['uuid' => 'abc'];
        $a       = new Sha3SignatureGenerator()->generate($this->message('secret-a', $payload));
        $b       = new Sha3SignatureGenerator()->generate($this->message('secret-b', $payload));
        self::assertNotSame($a, $b);

        Carbon::setTestNow(Carbon::create(2026, 8, 28, 12, 0, 1, 'UTC'));
        $c = new Sha3SignatureGenerator()->generate($this->message('secret-a', $payload));
        self::assertNotSame($a, $c);
        self::assertStringStartsWith('t=', $c);
    }

    public function testSignatureIsTimestampDotJsonHmacSha3WithWebhookSecret(): void
    {
        Carbon::setTestNow(Carbon::create(2026, 8, 28, 12, 34, 56, 'UTC'));
        $payload = ['uuid' => '0d6e1c2a', 'trigger' => 'STORE_TRANSACTION', 'content' => ['id' => '42']];
        $message = $this->message('top-secret', $payload);

        $signature = new Sha3SignatureGenerator()->generate($message);

        $timestamp = Carbon::now()->getTimestamp();
        $expected  = hash_hmac('sha3-256', sprintf('%d.%s', $timestamp, json_encode($payload, JSON_THROW_ON_ERROR)), 'top-secret');
        self::assertSame(sprintf('t=%d,v1=%s', $timestamp, $expected), $signature);
        self::assertSame(1, new Sha3SignatureGenerator()->getVersion());
    }

    #[Override]
    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function message(#[\SensitiveParameter] string $secret, array $payload): WebhookMessage
    {
        $webhook          = new Webhook(['secret' => $secret]);
        $message          = new WebhookMessage();
        $message->message = $payload;
        $message->setRelation('webhook', $webhook);

        return $message;
    }
}
