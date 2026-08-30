<?php

/*
 * StreamedEventResponse.php
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

namespace FireflyIII\Fork\Http;

use Closure;
use Illuminate\Http\ResponseTrait;
use Override;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * FORK: a streamed response that behaves like an Illuminate one.
 *
 * `response()->stream()` hands back Symfony's StreamedResponse, which has no `withCookie()`. The
 * MFA middleware in the `user-full-auth` group calls exactly that on whatever a route returns
 * (jc5/google2fa-laravel Middleware::handle), so a streaming route inside that group is a 500 for
 * any user with 2FA enabled and a cookie due for renewal — the one path least likely to be noticed
 * in casual testing, and the reason this class exists rather than a cast somewhere.
 *
 * Laravel has no Illuminate\Http\StreamedResponse to extend, so this adds the response half of the
 * Illuminate contract (cookies, headers) to Symfony's streaming response.
 */
final class StreamedEventResponse extends StreamedResponse
{
    use ResponseTrait;

    /**
     * ResponseTrait::getCallback() is untyped and would be a fatal incompatibility with Symfony's
     * `getCallback(): ?Closure`. A method on the class itself takes precedence over the one the
     * trait brings in, so declaring it here is what keeps the trait usable at all.
     */
    #[Override]
    public function getCallback(): ?Closure
    {
        return parent::getCallback();
    }
}
