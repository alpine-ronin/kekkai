# Changelog

Kekkai follows [semantic versioning](https://semver.org). Before 1.0.0, a minor version (0.2.0) may change the
configuration, the CLI or the MCP tools; each change is listed here.

## Unreleased

### Agents
- Cursor: `kekkai install cursor [--write]` writes the hooks to `.cursor/hooks.json` and the MCP server to
  `.cursor/mcp.json`; `kekkai hook pre-tool-use | stop --agent cursor` answers in Cursor's format. An edit Kekkai
  would ask about is refused in Cursor, which does not enforce *ask*, and the agent is told to ask the user.
- `kekkai doctor` checks every connected agent; with none, it says how to connect one.
- The rules and the review are shared by every agent (`Guard`); each agent's hooks only translate its JSON.
- The docs describe Kekkai for any coding agent: hooks for Claude Code and Cursor, the MCP server and `kekkai check`
  for the others.

### Checks
- `retry: N` on a check (0 to 5, default 0): a failure still there sends the agent back up to N more times in a row.
  Without it, the stop hook still sends the agent back once. `kekkai install cursor` writes `loop_limit: 6`.
- `on: [stop, ci]` on a check (the default): `[ci]` runs it in `kekkai check` only, `[stop]` at the agent's stop
  (stop hook, `check_changes`) only.

### Review
- `kekkai check`, `check_changes` and the stop hook list each changed file first: in scope, out of scope, protected,
  what decided it (the scope entry or zone, the pattern), and the failing checks whose output names it. The summary
  follows. `--format json` gains `verdicts`, one entry per file.

### Scope
- `kekkai scope show --format json` prints the scope as JSON (`docs/schemas/scope.schema.json`), `null` without one.

### Docs
- The README leads with what Kekkai gives the reviewer: the declared intent, edits outside it refused, the diff
  reviewed before the agent stops. It no longer claims to keep the agent inside its scope.
- The README explains running two agents at once (one git worktree each) under "How it works", no longer as a
  limitation.

## 0.1.0 (2026-11-16)

The first release.

### Scope
- A task's scope: an intent and paths, globs or zones of `kekkai.yaml`, declared by the agent (MCP `declare_scope`)
  or the user (`kekkai scope declare`), widened with a reason kept for the reviewer (`widen_scope`,
  `kekkai scope widen`), cleared by the user (`kekkai scope clear`).
- A declared scope cannot be replaced by the agent, only widened; a scope covering the whole project is refused.

### Guard
- Claude Code `PreToolUse` hook on `Edit`, `Write`, `MultiEdit`, `NotebookEdit`: refuses an edit outside the scope
  with the scope and what to do, asks the user for protected files and paths outside the project, never lets the agent
  edit `kekkai.yaml`, `.kekkai/` or `.git/`, and never answers `allow`.
- `without-scope` (`allow`, `ask`, `deny`), `outside-scope` (`ask`, `deny`), `allow-widen`, `protected`,
  `always-allowed` and `zones` in `kekkai.yaml`, validated: an unknown key is an error.

### Review
- `kekkai check`, the MCP tool `check_changes` and the Claude Code `Stop` hook review the files changed since a git
  revision (shell writes included) and run the checks of `kekkai.yaml` with `$KEKKAI_CHANGED_FILES`. The stop hook
  sends the agent back once when the review fails.
- `--format json` (docs/schemas/report.schema.json); coloured marks in a terminal.

### Setup and distribution
- `kekkai init`, `kekkai install claude [--write]`, `kekkai doctor`.
- Binaries needing no PHP (Linux x86_64 and arm64, macOS arm64) and `kekkai.phar` (PHP 8.3+), with `SHA256SUMS` and
  build attestations; `install.sh` for `curl | sh`; `kekkai self-update` with the same checks; the `edge`
  pre-release for every push to main.
