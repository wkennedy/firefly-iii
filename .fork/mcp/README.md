# firefly-mcp — Firefly III as MCP tools, on the machine that runs the model

`firefly-mcp.mjs` is a stdio [MCP](https://modelcontextprotocol.io) server that gives a local MCP
host (LM Studio, Claude Desktop) the fork's **read-only** finance tools: categories, accounts,
budgets, balances, per-category totals, transaction search, income vs expense, budget suggestions
and exact arithmetic. Same tools the in-app chat uses, because it is the same registry — this file
only forwards.

- **Read-only, and not by configuration.** Every call goes to `/api/v1/fork/chat/tools`, which
  refuses any tool that can change data whatever `FORK_CHAT_WRITES` says. Changing the ledger stays
  where phase 4d put it: a person clicking a confirmation card in Firefly.
- **No dependencies.** Node 18+, built-in `fetch`, ~190 lines. This process holds a Firefly personal
  access token; the point of writing our own MCP server rather than installing one is that no
  third-party code is ever in that position.

## Install (on the machine that runs the model, beside LM Studio)

1. **Turn the endpoint on** in Firefly: `FORK_CHAT_TOOLS_API=true`. It is independent of
   `FORK_CHAT`, so the API can be on without the in-app widget, or the reverse.
2. **Mint a dedicated personal access token** in Firefly (Options → Profile → OAuth → Personal
   access tokens), named so you can tell it apart, e.g. `mcp`. This is the third token in the
   system, after the importer's and the categorizer's — one process, one token, revocable on its
   own. It does not need to be listed in `FORK_AUTOMATION_TOKEN_NAMES`: nothing here writes, so
   nothing here can teach a learned rule.
3. **Copy the file** somewhere stable, e.g. `/opt/firefly-mcp/firefly-mcp.mjs`.
4. **Point the host at it.** LM Studio reads `~/.lmstudio/mcp.json`:

   ```json
   {
     "mcpServers": {
       "firefly": {
         "command": "node",
         "args": ["/opt/firefly-mcp/firefly-mcp.mjs"],
         "env": {
           "FIREFLY_URL": "https://firefly.example.lan",
           "FIREFLY_TOKEN": "<the token from step 2>"
         }
       }
     }
   }
   ```

   Claude Desktop takes the same block in its own config file.

`FIREFLY_URL` is whatever LAN hostname your Firefly is reachable on (ingress plus a certificate your
machines trust), **not** the in-cluster service name — this process runs outside the cluster.
`FIREFLY_MCP_TIMEOUT` (seconds, default 120) bounds one call.

The real hostname and token for a given deployment belong in that deployment's own repo, which is
private; this file stays generic on purpose.

## Check it without a host

The server speaks JSON-RPC over stdin, one message per line, so a pipe is a complete test:

```sh
{ echo '{"jsonrpc":"2.0","id":1,"method":"initialize","params":{"protocolVersion":"2025-06-18"}}'
  echo '{"jsonrpc":"2.0","id":2,"method":"tools/list"}'
  echo '{"jsonrpc":"2.0","id":3,"method":"tools/call","params":{"name":"account_balances","arguments":{}}}'
  sleep 5
} | FIREFLY_URL=https://firefly.example.lan FIREFLY_TOKEN=... node firefly-mcp.mjs
```

Expect eleven tools from `tools/list` and your real balances from the call. Errors arrive as
readable text: a 404 on `tools/list` means `FORK_CHAT_TOOLS_API` is off, and a 404 on `tools/call`
means that tool is not exposed (every write tool answers this way, by design).

## What it does not do

No prompts, no resources, no sampling — tools only, which is all a finance question needs. If a
future phase adds gated writes to MCP clients, it will need its own confirmation path, because the
one in the widget depends on a browser and a person looking at it.
