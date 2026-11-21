# Specification

What Kekkai does, precisely: the words it uses, the order in which it decides, the files it reads and writes, and
what it answers to each agent's hooks. Everything here is covered by the tests in `tests/`.

## Words

| Word | Meaning |
|---|---|
| **Project** | The directory Kekkai guards: `--root`, else the project the agent reports (`$CLAUDE_PROJECT_DIR` for Claude Code; `$CURSOR_PROJECT_DIR`, else the first of `workspace_roots`, for Cursor), else the `cwd` of the hook, else the current directory. Resolved with `realpath()`. |
| **Path** | A path relative to the project root, with `/` separators. Absolute paths given by the agent are made relative; a path that leads outside the project has none. |
| **Pattern** | A glob on paths (see [Patterns](#patterns)). |
| **Zone** | A named list of patterns in `kekkai.yaml`, usually one per bounded context. |
| **Scope** | What the current task may change: an intent, a list of entries (patterns or zone names), who declared it, and its widenings. One per project. |
| **Widening** | Entries added to a scope after its declaration, with a reason, who added them and when. Never removed until the scope is cleared. |
| **Decision** | What Kekkai answers for one path: a verdict (`allow`, `ask`, `deny`), the rule that gave it and a reason written for the agent. |
| **Review** | The decisions for every file changed since a git revision, plus the result of the checks. |
| **Check** | A shell command of `kekkai.yaml` run on the review. |

## Patterns

Patterns are matched against the whole path, from the project root.

| Pattern | Matches | Doesn't match |
|---|---|---|
| `src/Catalog` | `src/Catalog`, `src/Catalog/Product.php` | `src/CatalogBis/Product.php` |
| `src/Catalog/` | everything under `src/Catalog` | |
| `src/*/Domain/**` | `src/Catalog/Domain/Model/Product.php` | `src/Catalog/Application/Handler.php` |
| `src/**/Domain/*.php` | `src/Domain/A.php`, `src/A/B/Domain/A.php` | `src/A/Domain/Model/A.php` |
| `.env*` | `.env`, `.env.local` | `config/.env` |
| `src/Foo?.php` | `src/Foo1.php` | `src/Foo12.php` |

- `*` matches within one directory, `?` one character within one directory, `**` any number of directories
  (`**/` also matches none).
- A pattern without `*` or `?` is that file, or that directory with everything in it.
- A pattern ending with `/` is a directory: everything under it.
- A leading `/` or `./` is ignored. Backslashes are read as `/`.

## Deciding on one path

`Gate::decide()` applies these rules in order; the first that applies gives the decision.

| # | Rule | When | Verdict |
|---|---|---|---|
| 1 | `outside-project` | The path leads outside the project, or is the project root itself | `ask` |
| 2 | `guarded` | `.kekkai/**`, `kekkai.yaml`, `kekkai.yaml.dist`, `.git/**` | `deny` |
| 3 | `protected` | A pattern of `protected` matches | `ask` |
| 4 | `always-allowed` | A pattern of `always-allowed` matches | `allow` |
| 5 | `no-scope` | No scope is declared | `without-scope` (default `ask`) |
| 6 | `in-scope` | An entry of the scope or of a widening matches | `allow` |
| 7 | `outside-scope` | Anything else | `outside-scope` (default `deny`) |

Consequences worth knowing:

- `protected` wins over the scope: a scope naming `composer.lock` still asks the user.
- `guarded` wins over everything: the agent never edits its own guard, nor git. The user changes the scope through
  `kekkai scope`, never through the agent's edits.
- A zone name in the scope is replaced by the zone's patterns when deciding. A scope entry that is not a zone name is
  a pattern. Renaming a zone in `kekkai.yaml` while a scope uses it turns the entry into a pattern of that name.

## The scope

### Declaring

- By the agent: the MCP tool `declare_scope` (`by: agent`). Refused when a scope is already declared: the agent must
  widen it or ask the user to clear it, so it cannot silently swap a narrow scope for a wide one.
- By the user: `kekkai scope declare <entries>... -m "<intent>"` (`by: user`). Replaces any current scope.

Both refuse:

- an empty intent;
- no entry;
- an absolute entry, or one that leads outside the project (`../other/`);
- an entry covering the whole project (`**`, `**/*`).

Entries are trimmed, deduplicated, and kept in the order given.

### Widening

- By the agent: `widen_scope`, only when `allow-widen: true`. Refused without a declared scope.
- By the user: `kekkai scope widen <entries>... -m "<reason>"`, whatever `allow-widen` says.

Both need a non-empty reason and at least one entry not already in the scope. The same validation as declaring
applies. A widening keeps who added it and when; `kekkai check`, the stop hook and `show_scope` show every widening
with its reason.

### Clearing

`kekkai scope clear` deletes the scope, and the stop hook's send-back counts (`.kekkai/stop.json`). Only the user can: the agent has no tool for it, and `.kekkai/` is guarded.
Clear it when a task is done; the next task declares its own.

## Files

| File | Written by | Content |
|---|---|---|
| `kekkai.yaml` (or `kekkai.yaml.dist`) | The user (`kekkai init`) | The configuration: [CONFIGURATION.md](CONFIGURATION.md). The first that exists is read. |
| `.kekkai/scope.json` | Kekkai | The scope: [schemas/scope.schema.json](schemas/scope.schema.json). Written atomically (temporary file, then rename). |
| `.kekkai/log.jsonl` | Kekkai | One JSON object per line: every `ask` and `deny` of the edit hook, every declaration, widening and clearing, every stop that sent the agent back. |
| `.kekkai/.gitignore` | Kekkai | `*`: the scope is local to a checkout, never committed. |

`log.jsonl` events:

```json
{"at":"2026-11-16T21:40:12+01:00","event":"pre-tool-use","tool":"Edit","path":"src/Billing/Invoice.php","rule":"outside-scope","verdict":"deny"}
{"at":"2026-11-16T21:40:20+01:00","event":"widen","by":"agent","entries":["src/Billing/Invoice.php"]}
{"at":"2026-11-16T21:42:03+01:00","event":"stop","blocking":["src/Shared/Money.php"],"passed":false}
```

## The review

`Reviewer::review($base, $runChecks)` builds the report used by `kekkai check`, `check_changes` and the stop hook.

1. **Changed files**: `git diff --name-only --no-renames <base>` (the working tree, staged or not, against the
   revision) and the untracked files not ignored (`git ls-files --others --exclude-standard`), sorted and
   deduplicated. Deleted files count. In a repository without commits, the staged files. Outside a git repository,
   or with a revision git does not know, an error.
2. **Findings**: the decision for each changed file, except those allowed. The report also keeps every decision,
   allowed ones included, with what decided it (the scope entry, or the protected or always-allowed pattern): it
   lists them one line per file before the summary, and as `verdicts` in JSON. `no-scope` decisions are left out when
   `without-scope: allow`.
3. **Checks**: when `$runChecks` and at least one file changed, every check of `kekkai.yaml`, in order, from the
   project root, through the shell, with `KEKKAI_CHANGED_FILES` (the changed files, one per line) and `KEKKAI_BASE`
   in the environment. A check passes on exit code 0. A check that runs longer than its `timeout` is killed and
   fails. The report keeps the last 30 lines of its output.

The review **fails** when a check fails, or when a file is outside the scope and `outside-scope: deny` (it got there
another way than the edit tools: the shell, a generator, a formatter). Everything else is shown but does not fail:

| Finding | Shown as | Fails |
|---|---|---|
| `outside-scope`, with `outside-scope: deny` | `✗ Outside the scope` | yes |
| `outside-scope`, with `outside-scope: ask` | `! Outside the scope` (the user approved each edit) | no |
| `protected` | `! Protected files touched` | no |
| `guarded` | `! Kekkai files changed` (the user may have edited `kekkai.yaml`) | no |
| `no-scope` | `! Changed without a declared scope` | no |
| widenings | `! Scope widened N time(s)` | no |

`--format json` gives the report as [schemas/report.schema.json](schemas/report.schema.json).

## Agent hooks

Every hook reads the agent's JSON on stdin, always exits with code 0, and prints nothing when it has nothing to say.
`--agent` gives the format: `claude` (the default) or `cursor`. The decisions are the same for every agent; only the
JSON differs.

### Claude Code: `kekkai hook pre-tool-use`

Registered on `PreToolUse`, `Edit|Write|MultiEdit|NotebookEdit`. Reads `tool_name` and `tool_input.file_path`
(`tool_input.notebook_path` for `NotebookEdit`); ignores any other tool.

| Decision | Output |
|---|---|
| `allow` | Nothing: the agent's own permissions then apply. Kekkai never answers `allow`, which would bypass them. |
| `ask` | `{"hookSpecificOutput": {"hookEventName": "PreToolUse", "permissionDecision": "ask", "permissionDecisionReason": "Kekkai: …"}}` |
| `deny` | The same with `"permissionDecision": "deny"`: the edit is not made, and the reason goes to the agent. |

When Kekkai cannot decide (invalid `kekkai.yaml`, missing project), it answers `ask` with the error, so the user
decides: it never lets an edit through unchecked, and never blocks the agent without a word.

### Claude Code: `kekkai hook stop [--no-checks]`

Registered on `Stop`. Runs the review against `HEAD`.

- Passed: nothing.
- Failed: `{"decision": "block", "reason": "<the report>\n\nFix what failed before finishing: …"}`. Claude Code
  then continues the conversation with the reason.
- Input with `"stop_hook_active": true` (the agent is already continuing because of a stop hook): nothing. The agent
  is sent back **once**; a check that can never pass does not keep it in a loop. Exception: a check with `retry: N`
  that still fails sends it back up to N more times in a row, counted per `session_id` in `.kekkai/stop.json`
  (written only when a check has `retry`; without `session_id`, nothing).
- Only the checks with `stop` in `on` run.
- Error (not a git repository, invalid configuration): nothing, so the stop hook never prevents the agent from
  stopping because of Kekkai itself.

### Cursor: `kekkai hook pre-tool-use --agent cursor`

Registered on `preToolUse`, matcher `Write`: Cursor sends every file creation and change as `Write`. Reads
`tool_name` and `tool_input.file_path`; ignores any other tool.

| Decision | Output |
|---|---|
| `allow` | Nothing. |
| `ask` | `{"permission": "deny", "agent_message": "Kekkai: …\nThis edit needs the user's approval: stop and ask them.", "user_message": "…"}`: Cursor does not enforce `ask` before a tool runs, so the edit is refused and the agent told to ask. |
| `deny` | `{"permission": "deny", "agent_message": "Kekkai: …", "user_message": "Kekkai: …"}` |

When Kekkai cannot decide, the answer is the `ask` one, with the error.

### Cursor: `kekkai hook stop --agent cursor [--no-checks]`

Registered on `stop` with `loop_limit: 6` (1 + the highest `retry` allowed, 5). Runs the review against `HEAD`.

- Passed: nothing.
- Failed: `{"followup_message": "<the report>\n\nFix what failed before finishing: …"}`, which Cursor sends to the
  agent as the next message.
- Input with a `status` other than `completed` (aborted, error): nothing.
- Input with a `loop_count` other than 0: nothing. The agent is sent back **once**, except for a check with
  `retry: N` still failing, which sends it back while `loop_count` is at most N.
- Only the checks with `stop` in `on` run.
- Error: nothing, as for Claude Code.

## MCP server

`kekkai serve`: JSON-RPC 2.0 over stdio, one message per line. Protocol versions `2024-11-05`, `2025-03-26`,
`2025-06-18` and `2025-11-25`; it answers with the client's version when it knows it, else the latest. Tools:
[MCP-TOOLS.md](MCP-TOOLS.md). A tool error is a result with `"isError": true` and the message as text, so the agent
reads it.

The configuration is read when the server starts; the scope on every call.

## Exit codes

| Command | 0 | 1 | 2 |
|---|---|---|---|
| `kekkai check` | Review passed | Review failed | Invalid configuration, not a git repository |
| `kekkai scope …` | Done | Refused (validation) | Unknown action |
| `kekkai hook …` | Always, unless the event is unknown | | Unknown event |
| `kekkai install` | Done | | Unknown agent |
| `kekkai doctor` | Nothing is ✗ | Something is ✗ | |
| `kekkai self-update` | Updated, or nothing to do | Failed (download, checksum, attestation); with `--check`, a newer release exists | |
