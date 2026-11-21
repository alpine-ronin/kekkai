# CLI

Every command takes `--root <dir>` (default: `$CLAUDE_PROJECT_DIR` when set, else the current directory).

## kekkai init

Writes a commented `kekkai.yaml`. Leaves an existing one as it is.

## kekkai install claude | cursor [--write] [--binary <command>]

Prints the agent's hooks and the MCP server; with `--write`, merges them into its files: `.claude/settings.json` and
`.mcp.json` for Claude Code, `.cursor/hooks.json` and `.cursor/mcp.json` for Cursor. Any other agent: register
`kekkai serve` yourself. See [HOOKS.md](HOOKS.md).

## kekkai doctor

Checks what Kekkai needs in this project, one line each, with the command that fixes it:

| Line | ✗ when | ! when |
|---|---|---|
| Version and installation (binary, phar, composer, source) | | |
| Git repository | not a git repository | |
| `kekkai.yaml` | invalid | missing (the defaults apply) |
| Each check's command | | not on the `PATH` |
| An agent connected (a Kekkai hook in `.claude/settings.json` or `.cursor/hooks.json`) | none | |
| For each connected agent: its edit hook | missing | |
| Its stop hook | | missing |
| The `kekkai` server in its MCP file | | missing |
| The scope | | |

Exit code 1 when a line is ✗.

## kekkai scope

```bash
kekkai scope                                              # show
kekkai scope show --format json                           # the scope as JSON
kekkai scope declare catalog config/services.yaml -m "Add a price to products"
kekkai scope widen src/Shared/Money.php -m "Prices are rounded by Money"
kekkai scope clear
```

Declaring from the CLI replaces any current scope. Widening from the CLI works whatever `allow-widen` says.

`--format json` (`-f`) prints the scope as `.kekkai/scope.json` holds it, valid against
[schemas/scope.schema.json](schemas/scope.schema.json), or `null` when no scope is declared.

## kekkai check

```bash
kekkai check                       # since HEAD, with the checks
kekkai check --base origin/main    # the whole branch, in CI
kekkai check --no-checks           # the scope only
kekkai check --format json         # docs/schemas/report.schema.json
```

`--base <ref>` reviews what changed since `<ref>`: commits after it, the working tree and untracked files.

The output lists each changed file first, with its verdict and what decided it, then the summary:

```text
✓ src/Catalog/Price.php: in scope (catalog)
✗ src/Billing/Invoice.php: out of scope
! composer.lock: protected (composer.lock)
✗ src/Catalog/Product.php: in scope (catalog); check tests failed
Kekkai: 4 file(s) changed since HEAD. FAILED
```

A failing check is shown on a file when its output names the file's path. With `--format json`, the same is in
`verdicts`, one entry per file.

Exit code 0 when the review passed, 1 when it failed, 2 on an error (not a git repository, an unknown revision, an
invalid configuration). In a terminal, the marks are coloured (`NO_COLOR` and `FORCE_COLOR` are honoured).

In CI, the scope file is not there (it is never committed): the review then reports the changes as made without a
scope, and fails only on the checks. To check a branch against a scope in CI, declare it in the job first:

```bash
vendor/bin/kekkai scope declare pricing -m "$PR_TITLE"
vendor/bin/kekkai check --base origin/main
```

## kekkai self-update [version | edge] [--check] [--no-attestation]

Alias `kekkai update`. Downloads the release's file for this installation (the binary for this system, or
`kekkai.phar`), checks it against `SHA256SUMS` and, with the GitHub CLI logged in, its attestation, then puts it in
place of the running one. `--check` only says whether a newer release exists (exit code 1 when one does).
Installed with Composer or run from a clone, it says how to update instead. See [INSTALL.md](INSTALL.md#updating).

## kekkai hook pre-tool-use | stop [--agent claude | cursor] [--no-checks]

The agent hooks: JSON on stdin, JSON on stdout, in the agent's format (`claude` by default). See
[SPEC.md, Agent hooks](SPEC.md#agent-hooks).

## kekkai serve

The MCP server on stdio. See [MCP-TOOLS.md](MCP-TOOLS.md).
