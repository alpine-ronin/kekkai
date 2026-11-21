# Changelog

Kekkai follows [semantic versioning](https://semver.org). Before 1.0.0, a minor version (0.2.0) may change the
configuration, the CLI or the MCP tools; each change is listed here.

## 0.1.0 (2026-11-21)

The first release.

### Scope
- A task's scope: an intent and paths, globs or zones of `kekkai.yaml`, declared by the agent (MCP `declare_scope`)
  or the user (`kekkai scope declare`), widened with a reason kept for the reviewer (`widen_scope`,
  `kekkai scope widen`), cleared by the user (`kekkai scope clear`).
- A declared scope cannot be replaced by the agent, only widened; a scope covering the whole project is refused.
- `kekkai scope show --format json` prints the scope as JSON (`docs/schemas/scope.schema.json`), `null` without one.

### Guard
- Edit hooks for Claude Code (`PreToolUse` on `Edit`, `Write`, `MultiEdit`, `NotebookEdit`) and Cursor: refuse an
  edit outside the scope with the scope and what to do, ask the user for protected files and paths outside the
  project, never let the agent edit `kekkai.yaml`, `.kekkai/` or `.git/`, and never answer `allow`. Cursor does not
  enforce *ask*: such an edit is refused there and the agent is told to ask the user.
- `without-scope` (`allow`, `ask`, `deny`), `outside-scope` (`ask`, `deny`), `allow-widen`, `protected`,
  `always-allowed` and `zones` in `kekkai.yaml`, validated: an unknown key is an error.
- The rules and the review are shared by every agent (`Guard`); each agent's hooks only translate its JSON.

### Review
- `kekkai check`, the MCP tool `check_changes` and the stop hooks of Claude Code and Cursor review the files changed
  since a git revision (shell writes included) and run the checks of `kekkai.yaml` with `$KEKKAI_CHANGED_FILES`.
  The stop hook sends the agent back once when the review fails.
- Each changed file is listed first: in scope, out of scope, protected, what decided it (the scope entry or zone, the
  pattern), and the failing checks whose output names it. The summary follows.
- `--format json` (`docs/schemas/report.schema.json`), with `verdicts`, one entry per file; coloured marks in a
  terminal.

### Checks
- `retry: N` on a check (0 to 5, default 0): a failure still there sends the agent back up to N more times in a row.
- `on: [stop, ci]` on a check (the default): `[ci]` runs it in `kekkai check` only, `[stop]` at the agent's stop
  (stop hook, `check_changes`) only.

### Setup and distribution
- `kekkai init`, `kekkai install claude | cursor [--write]` (`.cursor/hooks.json`, `.cursor/mcp.json`, `loop_limit: 6`),
  `kekkai doctor`, which checks every connected agent and, with none, says how to connect one.
- Binaries needing no PHP (Linux x86_64 and arm64, macOS arm64) and `kekkai.phar` (PHP 8.3+), with `SHA256SUMS` and
  build attestations; `install.sh` for `curl | sh`; `kekkai self-update` with the same checks; the `edge`
  pre-release for every push to main.
