# Agents

Kekkai works with any coding agent in three parts:

- **An MCP server** (`kekkai serve`): the agent declares and widens its scope. Any MCP client can use it.
- **An edit hook**: edits are checked before they happen. It needs the agent to let a command refuse an edit.
- **A stop hook**, or `kekkai check`: the diff is reviewed before the work is accepted.

| Agent | MCP server | Edit hook | Stop hook | Setup |
|---|---|---|---|---|
| Claude Code | ✓ | ✓ `Edit`, `Write`, `MultiEdit`, `NotebookEdit` | ✓ | `kekkai install claude` |
| Cursor | ✓ | ✓ `Write` (every creation and change) | ✓ (IDE agent) | `kekkai install cursor` |
| Any other MCP client | ✓ | none: run `kekkai check` | none: run `kekkai check` | register `kekkai serve` |

`kekkai install <agent>` prints the configuration; `--write` merges it into the agent's files without removing
anything, and running it twice changes nothing. Commit those files: the team then works under the same rules.
`kekkai doctor` checks every agent connected in the project.

Installed with Composer in the project, the hooks call `vendor/bin/kekkai`; elsewhere, `kekkai` when it is on the
`PATH`, else its absolute path. `--binary` sets it.

## Claude Code

```bash
vendor/bin/kekkai install claude --write   # .claude/settings.json and .mcp.json
```

Restart Claude Code, then approve the `kekkai` server (`/mcp` shows it). What it writes:

```json
{
    "hooks": {
        "PreToolUse": [
            {
                "matcher": "Edit|Write|MultiEdit|NotebookEdit",
                "hooks": [{"type": "command", "command": "\"$CLAUDE_PROJECT_DIR\"/vendor/bin/kekkai hook pre-tool-use"}]
            }
        ],
        "Stop": [
            {
                "hooks": [{"type": "command", "command": "\"$CLAUDE_PROJECT_DIR\"/vendor/bin/kekkai hook stop", "timeout": 900}]
            }
        ]
    }
}
```

```json
{"mcpServers": {"kekkai": {"command": "sh", "args": ["-c", "\"$CLAUDE_PROJECT_DIR\"/vendor/bin/kekkai serve"]}}}
```

## Cursor

```bash
vendor/bin/kekkai install cursor --write   # .cursor/hooks.json and .cursor/mcp.json
```

Trust the workspace (project hooks only run in a trusted one), then enable the `kekkai` MCP server in Cursor's
settings. What it writes:

```json
{
    "version": 1,
    "hooks": {
        "preToolUse": [{"command": "vendor/bin/kekkai hook pre-tool-use --agent cursor", "matcher": "Write"}],
        "stop": [{"command": "vendor/bin/kekkai hook stop --agent cursor", "timeout": 900, "loop_limit": 6}]
    }
}
```

```json
{"mcpServers": {"kekkai": {"command": "sh", "args": ["-c", "vendor/bin/kekkai serve"]}}}
```

Cursor runs project hooks from the project root, hence the relative path. Two differences with Claude Code:

- Cursor does not enforce *ask* before a tool runs, so an edit Kekkai would ask about (a protected file, a path
  outside the project, no scope with `without-scope: ask`) is refused, and the agent is told to ask you.
- The stop hook sends the agent back with a follow-up message, once (more for a check with `retry`; `loop_limit: 6`
  allows the most). The `cursor-agent` CLI did not
  run the stop hook when this was written: there, run `kekkai check` before accepting the work.

## Other agents

Register `kekkai serve` as a stdio MCP server in the agent's configuration; it guards the directory it starts in, or
`--root`. Without an edit hook, nothing stops an edit before it happens: run `kekkai check` before accepting the work,
or in CI with `--base origin/main`.

An agent with command hooks can get an adapter: a class in `src/Hook/` that reads its hook JSON, asks `Guard`, and
writes its answer, plus a case in `Agent` for its files. See [ARCHITECTURE.md](ARCHITECTURE.md).

## A task, step by step

1. You ask: *"add a price to products"*. No scope yet.
2. The agent tries to edit `src/Catalog/Product.php`. With `without-scope: ask`, you are asked, and the prompt says
   the agent should call `declare_scope` first. With `deny`, the edit is refused and the agent reads that reason
   itself.
3. The agent calls `declare_scope` with `["catalog"]`. Edits in `src/Catalog/` and `tests/Catalog/` go through
   the agent's normal permissions.
4. It tries `src/Billing/Invoice.php`. Refused: the agent reads the scope and that it may call `widen_scope` with a
   reason, or keep to the scope.
5. It runs `sed -i` on `src/Shared/Money.php` through Bash. The edit hook doesn't see it.
6. It stops. The stop hook reviews the diff: `src/Shared/Money.php` is outside the scope, the review fails, and the
   agent is sent back with the report: revert, or widen with a reason.
7. It widens, and the checks pass: it stops. `kekkai check` in your terminal or in CI shows the same report, with the
   widening and its reason.
8. You review and commit, then `kekkai scope clear` before the next task.

## Several agents

The scope is one file per checkout. Two agents in the same directory share it: give each its own git worktree
(`git worktree add ../shop-pricing`), which is also what keeps their diffs apart.
