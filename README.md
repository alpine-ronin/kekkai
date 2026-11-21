<h1 align="center">
  <picture>
    <source media="(prefers-color-scheme: dark)" srcset="https://raw.githubusercontent.com/alpine-ronin/kekkai/main/docs/brand/logo-dark.svg">
    <img src="https://raw.githubusercontent.com/alpine-ronin/kekkai/main/docs/brand/logo.svg" alt="Kekkai" width="400">
  </picture>
</h1>

<p align="center"><b>Makes an AI coding agent's diff reviewable.</b><br>
The agent declares what it will change; edits outside that are refused, and the diff is reviewed before it stops.</p>

<p align="center">
  <a href="https://github.com/alpine-ronin/kekkai/actions/workflows/ci.yml"><img src="https://github.com/alpine-ronin/kekkai/actions/workflows/ci.yml/badge.svg" alt="CI"></a>
  <a href="https://github.com/alpine-ronin/kekkai/releases/latest"><img src="https://img.shields.io/github/v/release/alpine-ronin/kekkai?color=12161C&label=release" alt="Latest release"></a>
  <img src="https://img.shields.io/badge/php-%3E%3D8.3%20(or%20none)-777bb4" alt="PHP 8.3+, or none with the binary">
  <img src="https://img.shields.io/badge/agents-Claude%20Code%20%C2%B7%20Cursor%20%C2%B7%20any%20MCP%20client-C23B22" alt="Agents: Claude Code, Cursor, any MCP client">
  <a href="LICENSE"><img src="https://img.shields.io/badge/license-MIT-12161C" alt="MIT license"></a>
</p>

<p align="center">
  <a href="#quick-start">Quick start</a> ·
  <a href="#how-it-works">How it works</a> ·
  <a href="#kekkaiyaml">Configuration</a> ·
  <a href="#commands">Commands</a> ·
  <a href="docs/SPEC.md">Specification</a> ·
  <a href="#documentation">Docs</a>
</p>

---

Ask an agent to add a price to products and it may also "tidy up" the billing module, rename a shared value object
and touch `composer.lock` on the way. Each change looks reasonable; together they make a diff nobody can review.

*Kekkai* (結界, a barrier) makes the agent say first what it is going to change. Edits outside that are refused
before they happen; a change made another way, through the shell for instance, shows up when the diff is reviewed
before the agent stops. The reviewer gets the stated intent next to the diff, and any drift from it.

![Kekkai in a terminal: a scope is declared, an edit outside it is denied, a change made through the shell is caught by the review, the scope is widened with a reason, the review passes](docs/assets/demo.gif)

<sub>A real run with Claude Code, in a demo repository with two bounded contexts: what the agent sends to the hook is shown with
`cat edit.json`. Rendered by [tools/demo](tools/demo) from Kekkai's own output.</sub>

## How it works

<picture>
  <source media="(prefers-color-scheme: dark)" srcset="docs/assets/how-it-works-dark.svg">
  <img src="docs/assets/how-it-works.svg" alt="One task under Kekkai: 1 Declare (declare_scope), 2 Guard (edit hook), 3 Widen (widen_scope, with a reason), 4 Review (stop hook: git diff and checks)">
</picture>

1. **Declare.** Before its first edit, the agent calls the MCP tool `declare_scope` with the intent and the paths,
   globs or zones of the change (`catalog`, `src/Catalog/**`, `config/services.yaml`).
2. **Guard.** The agent's edit hook asks Kekkai before every file edit. Outside the scope, the edit is refused, and
   the agent reads the scope and what to do next. On a protected file (`composer.lock`, `migrations/`, `.env*`), you
   decide. In the scope, Kekkai says nothing: the agent's own permissions apply as before.
3. **Widen, in the open.** When the task truly needs more, the agent calls `widen_scope` with a reason. The scope
   grows, and every report shows each widening and why.
4. **Review before done.** When the agent stops, Kekkai reads the diff from git, so files changed through the shell
   (`sed -i`, a generator, a formatter) are caught too, and runs your checks (`phpgraph check`, PHPUnit, PHPStan) on
   it. A failure sends the agent back to work, once.

Edit and stop hooks ship for **Claude Code** and **Cursor**. Any other agent that speaks MCP gets the scope tools, and
`kekkai check` reviews its work before you accept it or in CI: [docs/HOOKS.md](docs/HOOKS.md).

No LLM, no network, nothing leaves your machine. A hook call takes about 50 ms.

### Two agents at once

The scope lives in `.kekkai/`, one per checkout: two agents in the same directory share it. Give each its own git
worktree, and so its own scope:

```bash
git worktree add ../shop-catalog -b catalog    # the second agent works in ../shop-catalog
```

## Quick start

### 1. Install

The binary carries its own PHP: nothing else is needed (Linux x86_64 and arm64, macOS on Apple silicon). Elsewhere,
the script installs `kekkai.phar`, which needs PHP 8.3 or later.

```bash
curl -fsSL https://raw.githubusercontent.com/alpine-ronin/kekkai/main/install.sh | sh
```

It checks the download against the release's `SHA256SUMS` and, when the [GitHub CLI](https://cli.github.com) is
logged in, its attestation (that this repository's release workflow built it), then installs it in `~/.local/bin`.
Read it first if you like: [install.sh](install.sh).

| | |
|---|---|
| A given version | `curl -fsSL https://raw.githubusercontent.com/alpine-ronin/kekkai/main/install.sh \| sh -s -- 0.1.0` |
| The last build of `main` | `curl -fsSL https://raw.githubusercontent.com/alpine-ronin/kekkai/main/install.sh \| sh -s -- edge` |
| The PHAR, even where a binary exists | `curl -fsSL https://raw.githubusercontent.com/alpine-ronin/kekkai/main/install.sh \| KEKKAI_PHAR=1 sh` |
| Another directory | `… \| KEKKAI_INSTALL_DIR=/usr/local/bin sh` |
| With Composer, in the project | see [docs/INSTALL.md](docs/INSTALL.md) (not on Packagist yet) |

### 2. Set up a project

```bash
cd your-project
kekkai init                     # a commented kekkai.yaml: zones, protected files, policy, checks
kekkai install claude --write   # or cursor: the agent's hooks and the MCP server
kekkai doctor                   # checks the setup and says how to fix what is missing
```

<p align="center"><img src="docs/assets/doctor.png" alt="kekkai doctor: every line checked" width="760"></p>

Commit `kekkai.yaml` and the agent's files (`.claude/settings.json` and `.mcp.json`, or `.cursor/hooks.json` and
`.cursor/mcp.json`) so the whole team works under the same rules. Restart the agent and approve the `kekkai` MCP
server. Another agent: [docs/HOOKS.md](docs/HOOKS.md#other-agents).

### 3. Work

Ask your agent for a task as usual. It declares its scope, works inside it, widens it with a reason when it must,
and is reviewed when it stops. Between tasks:

```bash
kekkai scope          # the current scope and its widenings
kekkai check          # the review, yourself, any time
kekkai scope clear    # the task is done: the next one declares its own scope
```

The review, when the agent rewrote an invoice through the shell and touched `composer.lock`:

<p align="center"><img src="docs/assets/check.png" alt="kekkai check: FAILED, src/Billing/Invoice.php outside the scope, composer.lock protected, check syntax passed" width="760"></p>

### 4. Update

```bash
kekkai self-update            # the latest release, checked like the install
kekkai self-update --check    # is there a newer one?
kekkai self-update 0.1.0      # a given version (or edge)
```

Running the install script again does the same. Installed with Composer: `composer update alpine-ronin/kekkai`.
Restart your MCP client afterwards, so the server runs the new version.

## kekkai.yaml

```yaml
zones:                       # named sets of paths, one per bounded context for instance
  catalog: ['src/Catalog/', 'tests/Catalog/']
  billing: ['src/Billing/', 'tests/Billing/']

protected:                   # you approve these edits, whatever the scope
  - composer.lock
  - '.env*'
  - 'migrations/'

always-allowed:              # allowed whatever the scope
  - CHANGELOG.md

without-scope: ask           # an edit before any scope: allow, ask or deny
outside-scope: deny          # an edit outside the scope: ask (you decide) or deny (the agent widens or stays in)
allow-widen: true            # false: only you widen it, with `kekkai scope widen`

checks:                      # run on the diff by `kekkai check` and when the agent stops
  - name: architecture
    run: phpgraph check
  - name: tests
    run: vendor/bin/phpunit
    timeout: 600
```

Patterns are relative to the project root: `*` and `?` within a directory, `**` across directories; a path without
wildcard is that file, or that directory with everything in it. Checks get the changed files, one per line, in
`$KEKKAI_CHANGED_FILES`. Every key, and which policy to choose: [docs/CONFIGURATION.md](docs/CONFIGURATION.md).

Whatever the configuration, the agent never edits `kekkai.yaml`, `.kekkai/` (where the scope lives) or `.git/`, and a
scope covering the whole project (`**`) is refused.

## With phpgraph and Airlock

[phpgraph](https://github.com/alpine-ronin/phpgraph) already checks the architecture: layers, dependency rules, the
bounded context of each handler. Kekkai doesn't redo it: it runs it on the diff, before the agent stops.

```yaml
checks:
  - name: architecture
    run: phpgraph check
```

phpgraph says whether the code respects the architecture; Kekkai says whether the agent stayed on its task. With
[Airlock](https://github.com/alpine-ronin/airlock) guarding its shell commands, the three ways an agent acts on a
project are covered:

| | Reading | Running | Editing |
|---|---|---|---|
| | [phpgraph](https://github.com/alpine-ronin/phpgraph): the answer from a graph of the code | [Airlock](https://github.com/alpine-ronin/airlock): every command weighed, asked, sandboxed | **Kekkai**: every edit inside the declared scope |

## Commands

| Command | What it does |
|---|---|
| `kekkai init` | Writes a commented `kekkai.yaml`. |
| `kekkai install claude \| cursor [--write]` | Prints, or merges into the agent's files, the hooks and the MCP server. |
| `kekkai doctor` | Checks git, `kekkai.yaml`, the hooks, the MCP server and the checks' commands; says how to fix each. |
| `kekkai scope [show]` | The current scope and its widenings. |
| `kekkai scope declare <paths>... -m "<intent>"` | Declares the scope yourself, before handing the task over. |
| `kekkai scope widen <paths>... -m "<reason>"` | Widens it (the only way when `allow-widen: false`). |
| `kekkai scope clear` | Ends the task. |
| `kekkai check [--base main] [--no-checks] [--format json]` | The review. Exit code 1 when it fails, for CI too. |
| `kekkai self-update [version \| edge] [--check]` | Updates the binary or the PHAR, checked like the install. |
| `kekkai hook pre-tool-use \| stop [--agent cursor]` | The agent hooks (JSON on stdin). |
| `kekkai serve` | The MCP server: `declare_scope`, `widen_scope`, `show_scope`, `check_changes`. |

`kekkai help <command>` explains each, with examples. Every command, and Kekkai in CI: [docs/CLI.md](docs/CLI.md).

<details>
<summary><code>kekkai</code></summary>

<img src="docs/assets/help.png" alt="kekkai: the start banner and the list of commands">
</details>

## Checking a download

Each release holds `kekkai.phar`, one binary per platform, `SHA256SUMS` and `VERSION`. The install script and
`kekkai self-update` check the SHA-256 themselves; by hand:

```bash
sha256sum --ignore-missing -c SHA256SUMS      # on macOS: shasum -a 256 kekkai-macos-aarch64, compared with SHA256SUMS
gh attestation verify kekkai-linux-x86_64 --repo alpine-ronin/kekkai
```

The attestation says that the release workflow of this repository built the file, from which commit and tag.

## What it does not do

- **It is not a sandbox.** The agent declares its own scope: Kekkai stops drift and makes it visible, not an agent set
  on getting around it. A shell command can still write outside the scope; the review catches it in the diff
  afterwards, it does not prevent it. To control commands before they run, use Airlock.
- **Edit hooks for Claude Code and Cursor.** Any MCP client can use the server and `kekkai check`, but only an agent
  with an edit hook has the edit refused before it happens.
- **Sent back once.** If the review still fails, the agent is let stop, so a check that can never pass does
  not loop forever: read the report yourself. A check's `retry` (at most 5) gives it more rounds.

All of them: [docs/LIMITATIONS.md](docs/LIMITATIONS.md).

## Documentation

| | |
|---|---|
| [SPEC.md](docs/SPEC.md) | The specification: the rules in order, the scope's lifecycle, the files, the review, the hook and MCP protocols, exit codes |
| [CONFIGURATION.md](docs/CONFIGURATION.md) | Every key of `kekkai.yaml`, choosing the policy, zones from bounded contexts, checks |
| [HOOKS.md](docs/HOOKS.md) | Each agent's setup (Claude Code, Cursor, any MCP client), and a task step by step |
| [MCP-TOOLS.md](docs/MCP-TOOLS.md) | The four tools, their arguments and answers |
| [CLI.md](docs/CLI.md) | Every command, and Kekkai in CI |
| [INSTALL.md](docs/INSTALL.md) | The binary, the PHAR, Composer, from source, updating |
| [ARCHITECTURE.md](docs/ARCHITECTURE.md) | The code, the flows, the design rules, the tests |
| [DECISIONS.md](docs/DECISIONS.md) | Why it works this way |
| [LIMITATIONS.md](docs/LIMITATIONS.md) | What it does not cover |
| [schemas/](docs/schemas) | JSON schemas of the scope file and of `kekkai check --format json` |
| [brand/](docs/brand) | Logo, icon, social preview |

## Development

```bash
git clone https://github.com/alpine-ronin/kekkai && cd kekkai
composer install
composer check                          # code style, PHPStan at level max, the tests
composer smoke                          # bin/kekkai through a whole task, in a new git repository
composer phar && tools/smoke.sh build/kekkai.phar
python3 tools/demo/render.py build/kekkai.phar    # the demo GIF and the screenshots
```

How to contribute: [CONTRIBUTING.md](CONTRIBUTING.md). Security issues: [SECURITY.md](SECURITY.md).

## License

MIT
