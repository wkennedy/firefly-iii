<?php

/*
 * ChatToolController.php
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
use FireflyIII\Fork\Chat\ToolRegistry;
use FireflyIII\User;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * FORK: /api/v1/fork/chat/tools — the chat's read-only tools, over the API (config
 * fork.chat_tools_api, off by default).
 *
 * This exists for the stdio MCP shim (.fork/mcp/firefly-mcp.mjs), which runs beside LM Studio on
 * another machine and cannot call PHP. It holds a dedicated personal access token, so everything it
 * can reach is everything that token's holder can do — which is why the write tool is excluded
 * here by construction rather than by configuration: `ToolRegistry::isWrite()` decides, and adding
 * a write tool later cannot quietly widen this endpoint.
 *
 * Changing data through an MCP client would mean a language model on another machine writing to the
 * ledger with no person in the loop, which is exactly what phase 4d was built to prevent.
 */
final class ChatToolController extends Controller
{
    public function execute(Request $request, ToolRegistry $registry, string $tool): JsonResponse
    {
        $this->guard($registry);
        // Unknown and write-capable tools answer identically: from out here, they do not exist. The
        // body says so in words, because "Resource not found" sends whoever is reading it looking
        // for a routing problem that is not there.
        if (!in_array($tool, $registry->names(), true) || $registry->isWrite($tool)) {
            return response()->json([
                'message' => sprintf('There is no read-only tool called "%s". This endpoint never exposes tools that can change data.', $tool),
            ], 404);
        }
        $data      = $request->validate(['arguments' => ['nullable', 'array']]);
        $arguments = (string) json_encode($data['arguments'] ?? [], JSON_THROW_ON_ERROR);

        return response()->json(['data' => $registry->execute($this->user(), $tool, $arguments)]);
    }

    public function index(ToolRegistry $registry): JsonResponse
    {
        $this->guard($registry);
        $tools = array_map(
            static fn(array $definition): array => $definition['function'],
            $registry->definitions(false)
        );

        return response()->json(['data' => $tools]);
    }

    private function guard(ToolRegistry $registry): void
    {
        if (true !== config('fork.chat_tools_api')) {
            abort(404);
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
