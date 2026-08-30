<?php

/*
 * PayeeAliasController.php
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

use FireflyIII\Api\V1\Controllers\Controller;
use FireflyIII\Enums\AccountTypeEnum;
use FireflyIII\Fork\Models\ForkPayeeAlias;
use FireflyIII\User;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Validation\Rule;
use Throwable;

use function Safe\preg_match;

/**
 * FORK: /api/v1/fork/payee-aliases — CRUD for alias rules plus a merge trigger. Plain JSON.
 */
final class PayeeAliasController extends Controller
{
    public function destroy(int $id): JsonResponse
    {
        $alias = $this->find($id);
        if (null === $alias) {
            return $this->notFound();
        }
        $alias->delete();

        return response()->json(null, 204);
    }

    public function index(): JsonResponse
    {
        $rows = [];
        foreach (ForkPayeeAlias::query()->where('user_group_id', $this->user()->user_group_id)->orderBy('order')->orderBy('id')->get() as $alias) {
            $rows[] = $this->present($alias);
        }

        return response()->json(['data' => $rows]);
    }

    public function merge(Request $request): JsonResponse
    {
        $data = $request->validate(['dry_run' => ['nullable', 'boolean']]);
        Artisan::call('firefly-iii:fork:payees:merge', ['--user' => $this->user()->email, '--dry-run' => (bool) ($data['dry_run'] ?? false)]);

        return response()->json(['data' => ['output' => trim(Artisan::output())]]);
    }

    public function store(Request $request): JsonResponse
    {
        $data  = $this->validated($request, true);
        $error = $this->patternError($data);
        if (null !== $error) {
            return response()->json(['message' => $error], 422);
        }
        $alias = ForkPayeeAlias::query()->create($data + ['user_group_id' => $this->user()->user_group_id]);
        // Read back the row: `active` and `order` come from database defaults when the request
        // omits them, and without this the response says active:false for an alias that is live.
        $alias->refresh();

        return response()->json(['data' => $this->present($alias)], 201);
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $alias = $this->find($id);
        if (null === $alias) {
            return $this->notFound();
        }
        $data  = $this->validated($request, false);
        $error = $this->patternError(array_merge($alias->only(['match_type', 'pattern']), $data));
        if (null !== $error) {
            return response()->json(['message' => $error], 422);
        }
        $alias->fill($data)->save();

        return response()->json(['data' => $this->present($alias->fresh())]);
    }

    private function find(int $id): null|ForkPayeeAlias
    {
        return ForkPayeeAlias::query()->where('user_group_id', $this->user()->user_group_id)->where('id', $id)->first();
    }

    private function notFound(): JsonResponse
    {
        return response()->json(['message' => 'Payee alias not found.'], 404);
    }

    /**
     * @param array<string, mixed> $data
     */
    private function patternError(array $data): null|string
    {
        if (ForkPayeeAlias::REGEX !== ($data['match_type'] ?? null)) {
            return null;
        }

        try {
            preg_match(ForkPayeeAlias::regex((string) $data['pattern']), '');
        } catch (Throwable) {
            return sprintf('"%s" is not a valid regular expression.', (string) $data['pattern']);
        }

        return null;
    }

    /**
     * @return array<string, mixed>
     */
    private function present(ForkPayeeAlias $alias): array
    {
        return [
            'id'                => (int) $alias->id,
            'account_type'      => (string) $alias->account_type,
            'match_type'        => (string) $alias->match_type,
            'pattern'           => (string) $alias->pattern,
            'canonical_name'    => (string) $alias->canonical_name,
            'active'            => (bool) $alias->active,
            'clean_description' => (bool) $alias->clean_description,
            'order'             => (int) $alias->order,
            'hit_count'         => (int) $alias->hit_count
        ];
    }

    private function user(): User
    {
        $user = auth()->user();
        if (!$user instanceof User) {
            throw new AuthenticationException();
        }

        return $user;
    }

    /**
     * @return array<string, mixed>
     */
    private function validated(Request $request, bool $creating): array
    {
        $required = $creating ? 'required' : 'sometimes';
        $data     = $request->validate([
            'account_type'      => [$required, Rule::in([AccountTypeEnum::EXPENSE->value, AccountTypeEnum::REVENUE->value, 'expense', 'revenue'])],
            'match_type'        => [$required, Rule::in(ForkPayeeAlias::MATCH_TYPES)],
            'pattern'           => [$required, 'string', 'min:1', 'max:255'],
            'canonical_name'    => [$required, 'string', 'min:1', 'max:255'],
            'active'            => ['sometimes', 'boolean'],
            'clean_description' => ['sometimes', 'boolean'],
            'order'             => ['sometimes', 'integer', 'min:0']
        ]);
        if (array_key_exists('account_type', $data)) {
            $data['account_type'] = match ($data['account_type']) {
                'expense' => AccountTypeEnum::EXPENSE->value,
                'revenue' => AccountTypeEnum::REVENUE->value,
                default   => $data['account_type']
            };
        }

        return $data;
    }
}
