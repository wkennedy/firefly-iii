<?php

/*
 * TransferPairController.php
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

namespace FireflyIII\Fork\Http\Controllers;

use Carbon\Carbon;
use FireflyIII\Api\V1\Controllers\Controller;
use FireflyIII\Fork\Models\ForkTransferPair;
use FireflyIII\Fork\Transfers\PairingSettings;
use FireflyIII\Fork\Transfers\TransferPairer;
use FireflyIII\Models\Account;
use FireflyIII\User;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use InvalidArgumentException;

/**
 * FORK: /api/v1/fork/transfer-pairs — inspect pairs, run the sweep, manage settings.
 * Plain JSON (not JSON:API); scoped to the authenticated user's user group.
 */
final class TransferPairController extends Controller
{
    public function index(): JsonResponse
    {
        $rows = [];
        foreach (ForkTransferPair::query()->where('user_group_id', $this->user()->user_group_id)->orderByDesc('id')->limit(500)->get() as $pair) {
            $rows[] = [
                'id'                 => (int) $pair->id,
                'funding_journal_id' => (int) $pair->funding_journal_id,
                'mirror_journal_id'  => (int) $pair->mirror_journal_id,
                'mirror_description' => (string) $pair->mirror_description,
                'mirror_account'     => (string) $pair->mirror_account,
                'amount'             => (string) $pair->amount,
                'matched_on'         => Carbon::parse((string) $pair->matched_on)->format('Y-m-d'),
                'strategy'           => (string) $pair->strategy,
                'created_at'         => $pair->created_at?->toAtomString()
            ];
        }

        return response()->json(['data' => $rows]);
    }

    public function run(Request $request, TransferPairer $pairer): JsonResponse
    {
        $data  = $request->validate(['since' => ['nullable', 'date_format:Y-m-d'], 'dry_run' => ['nullable', 'boolean']]);
        $since = null !== ($data['since'] ?? null)
            ? Carbon::createFromFormat('Y-m-d', (string) $data['since'], config('app.timezone'))
            : Carbon::now(config('app.timezone'))->subDays(90);
        if (!$since instanceof Carbon) {
            return response()->json(['message' => 'Invalid date.'], 422);
        }
        $dry = array_key_exists('dry_run', $data) ? (bool) $data['dry_run'] : null;

        return response()->json(['data' => $pairer->sweep($this->user(), $since, $dry)]);
    }

    public function settings(PairingSettings $settings): JsonResponse
    {
        return response()->json(['data' => $settings->forUser($this->user())]);
    }

    public function updateSettings(Request $request, PairingSettings $settings): JsonResponse
    {
        $data = $request->validate([
            'enabled'     => ['sometimes', 'boolean'],
            'window_days' => ['sometimes', 'integer', 'min:0', 'max:31'],
            'patterns'    => ['sometimes', 'array'],
            'patterns.*'  => ['string', 'max:255'],
            'accounts'    => ['sometimes', 'array'],
            'accounts.*'  => ['integer'],
            'dry_run'     => ['sometimes', 'boolean']
        ]);
        $user = $this->user();
        if (array_key_exists('accounts', $data)) {
            $ids   = array_values(array_unique(array_map('intval', (array) $data['accounts'])));
            $owned = Account::query()->where('user_group_id', $user->user_group_id)->whereIn('id', $ids)->count();
            if ($owned !== count($ids)) {
                return response()->json(['message' => 'One or more accounts do not belong to you.'], 422);
            }
        }

        try {
            return response()->json(['data' => $settings->save($user, $data)]);
        } catch (InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
    }

    private function user(): User
    {
        $user = auth()->user();
        if (!$user instanceof User) {
            throw new AuthenticationException();
        }

        return $user;
    }
}
