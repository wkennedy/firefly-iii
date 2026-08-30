#!/usr/bin/env node
/*
 * firefly-mcp.mjs
 * Copyright (c) 2026 the fork authors.
 *
 * This file is part of a fork of Firefly III (https://github.com/firefly-iii).
 *
 * This program is free software: you can redistribute it and/or modify it under the terms of the
 * GNU Affero General Public License as published by the Free Software Foundation, either version 3
 * of the License, or (at your option) any later version. See https://www.gnu.org/licenses/.
 */

/*
 * FORK: an MCP server that exposes Firefly III's read-only chat tools to a local MCP host
 * (LM Studio on the machine that runs the model, or Claude Desktop).
 *
 * It is a proxy and nothing more: `tools/list` and `tools/call` become GET and POST against
 * /api/v1/fork/chat/tools on the Firefly instance, which is where the queries actually live. That
 * is deliberate — the tools are written once, in the application that owns the data, and this file
 * exists only because a model on another machine cannot call PHP.
 *
 * No dependencies, by design. This process holds a Firefly personal access token, and the reason
 * this fork writes its own MCP server instead of installing somebody's is precisely so that no
 * third-party code is in that position. Node 18+ (built-in fetch); JSON-RPC 2.0 over stdio,
 * one message per line.
 *
 * Configure in the host, e.g. ~/.lmstudio/mcp.json:
 *   {"mcpServers":{"firefly":{"command":"node","args":["/opt/firefly-mcp/firefly-mcp.mjs"],
 *     "env":{"FIREFLY_URL":"https://firefly.example.lan","FIREFLY_TOKEN":"..."}}}}
 *
 * Requires FORK_CHAT_TOOLS_API=true on the Firefly side. Writing is not reachable from here: the
 * endpoint refuses write-capable tools whatever the token is.
 */

const NAME = 'firefly-iii';
const VERSION = '1.0.0';
const FALLBACK_PROTOCOL = '2025-06-18';

const url = (process.env.FIREFLY_URL || '').replace(/\/+$/, '');
const token = process.env.FIREFLY_TOKEN || '';
const timeout = Number(process.env.FIREFLY_MCP_TIMEOUT || 120) * 1000;

if ('' === url || '' === token) {
  process.stderr.write('firefly-mcp: set FIREFLY_URL and FIREFLY_TOKEN in the MCP host config.\n');
  process.exit(2);
}

/** Everything that goes out on stdout is a JSON-RPC message; logging goes to stderr. */
function send(message) {
  process.stdout.write(JSON.stringify(message) + '\n');
}

function result(id, value) {
  send({jsonrpc: '2.0', id, result: value});
}

function failure(id, code, message) {
  send({jsonrpc: '2.0', id, error: {code, message}});
}

async function firefly(path, options = {}, notFoundHint = '') {
  const controller = new AbortController();
  const timer = setTimeout(() => controller.abort(), timeout);
  try {
    const response = await fetch(`${url}/api/v1/fork${path}`, {
      ...options,
      signal: controller.signal,
      headers: {
        Authorization: `Bearer ${token}`,
        Accept: 'application/json',
        'Content-Type': 'application/json',
        ...(options.headers || {})
      }
    });
    const text = await response.text();
    if (!response.ok) {
      // Firefly says what it refused and why; the hint only covers what it cannot know, which is
      // that the whole endpoint may simply be switched off.
      const detail = (() => {
        try {
          return JSON.parse(text).message || text;
        } catch {
          return text;
        }
      })();
      const hint = 404 === response.status ? notFoundHint : '';
      throw new Error(`Firefly answered ${response.status}: ${String(detail).slice(0, 300)}${hint}`);
    }
    return JSON.parse(text).data;
  } finally {
    clearTimeout(timer);
  }
}

async function listTools() {
  const tools = await firefly('/chat/tools', {}, ' — is FORK_CHAT_TOOLS_API=true on the Firefly side?');

  return {
    tools: tools.map(tool => ({
      name: tool.name,
      description: tool.description,
      inputSchema: tool.parameters
    }))
  };
}

async function callTool(params) {
  const name = String(params?.name || '');
  const args = params?.arguments && 'object' === typeof params.arguments ? params.arguments : {};
  const data = await firefly(
    `/chat/tools/${encodeURIComponent(name)}`,
    {method: 'POST', body: JSON.stringify({arguments: args})},
    ' — call tools/list for the tools that exist.'
  );

  // A tool that refused (a bad date, an unknown category) is a result the model should read and
  // retry from, not a protocol error — the same contract the in-app chat uses.
  return {
    content: [{type: 'text', text: JSON.stringify(data)}],
    isError: Boolean(data && 'object' === typeof data && 'error' in data)
  };
}

/** In-flight requests, so closing stdin cannot cut off a reply that is still being fetched. */
let pending = 0;
let closing = false;

function settled() {
  pending -= 1;
  if (closing && 0 === pending) {
    process.exit(0);
  }
}

async function handle(message) {
  const {id, method, params} = message;
  const isNotification = undefined === id || null === id;

  try {
    switch (method) {
      case 'initialize': {
        // Echo the host's protocol version rather than pinning one: this proxy has no opinion about
        // the wire version, and refusing a newer host over a date string helps nobody.
        const requested = params?.protocolVersion;
        result(id, {
          protocolVersion: 'string' === typeof requested && requested ? requested : FALLBACK_PROTOCOL,
          capabilities: {tools: {listChanged: false}},
          serverInfo: {name: NAME, version: VERSION}
        });

        return;
      }
      case 'notifications/initialized':
      case 'notifications/cancelled':
        return; // notifications get no reply
      case 'ping':
        result(id, {});

        return;
      case 'tools/list':
        result(id, await listTools());

        return;
      case 'tools/call':
        result(id, await callTool(params));

        return;
      default:
        if (!isNotification) {
          failure(id, -32601, `Method not found: ${method}`);
        }
    }
  } catch (error) {
    const text = error?.message || String(error);
    if (isNotification) {
      process.stderr.write(`firefly-mcp: ${text}\n`);

      return;
    }
    if ('tools/call' === method) {
      // Keep a failed call inside the conversation: the model can say "I could not reach Firefly"
      // instead of the host showing a protocol error and dropping the turn.
      result(id, {content: [{type: 'text', text}], isError: true});

      return;
    }
    failure(id, -32603, text);
  }
}

let buffer = '';
process.stdin.setEncoding('utf8');
process.stdin.on('data', chunk => {
  buffer += chunk;
  let newline = buffer.indexOf('\n');
  while (-1 !== newline) {
    const line = buffer.slice(0, newline).trim();
    buffer = buffer.slice(newline + 1);
    if ('' !== line) {
      let message = null;
      try {
        message = JSON.parse(line);
      } catch {
        failure(null, -32700, 'Parse error');
      }
      if (null !== message) {
        pending += 1;
        handle(message).finally(settled);
      }
    }
    newline = buffer.indexOf('\n');
  }
});
process.stdin.on('end', () => {
  // A host that closes stdin is done talking, but a call may still be waiting on Firefly; answer it
  // before leaving rather than dropping the reply on the floor.
  closing = true;
  if (0 === pending) {
    process.exit(0);
  }
});
