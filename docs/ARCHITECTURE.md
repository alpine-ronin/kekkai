# Architecture

Kekkai is small on purpose: a hook runs on every edit of the agent, so it must start fast (about 50 ms) and depend on
little (Symfony Console and Yaml).

```text
bin/kekkai                 runs Kekkai\Application
src/
├── Application.php        the commands and the start banner
├── Version.php            written into the PHAR at build time, else read from Composer
├── Glob.php               path patterns: *, ?, **, directories
├── Path.php               paths relative to the project root, normalised without realpath()
├── Verdict.php            allow | ask | deny
├── Project.php            the root, its configuration and its scope store
├── Process.php            proc_open with a timeout, for git and the checks
├── Config/
│   ├── Config.php         kekkai.yaml: validated, defaults, guarded paths
│   └── CheckDefinition.php
├── Scope/
│   ├── Scope.php          intent, entries, widenings: declare(), widen(), covers()
│   ├── Widening.php
│   └── ScopeStore.php     .kekkai/scope.json and log.jsonl
├── Gate/
│   ├── Gate.php           the decision for one path, rules applied in order
│   └── Decision.php
├── Change/
│   └── ChangedFiles.php   git diff and untracked files since a revision
├── Check/
│   ├── Reviewer.php       changed files, decisions, checks: a Report
│   ├── Report.php         passed(), text and JSON
│   └── CheckResult.php
├── Hook/
│   ├── Guard.php          what every agent's hooks ask: may this file be edited, may the agent stop
│   ├── Agent.php          the agents with hooks: their files, their install, their dispatch
│   ├── ClaudeCode.php     Claude Code's PreToolUse and Stop JSON, in and out
│   └── Cursor.php         Cursor's preToolUse and stop JSON, in and out
├── Mcp/
│   └── McpServer.php      JSON-RPC over stdio, four tools
├── Update/
│   ├── Releases.php       VERSION, SHA256SUMS and files of a release (latest, edge, a version)
│   ├── Installation.php   binary, PHAR, Composer or source: what self-update can replace
│   └── SelfUpdater.php    download, checksum, attestation, then exec mv over the running file
└── Command/               init, install, doctor, scope, check, hook, serve, self-update
tools/
├── build-phar.php         build/kekkai.phar, production dependencies only
├── smoke.sh               any build through a whole task, in a new git repository
└── demo/                  the README's GIF, screenshots and diagram, from real runs
install.sh                 the curl install: platform, checksum, attestation
```

## Distribution

```text
push to main ──▶ ci.yml        tests on PHP 8.3, 8.4, 8.5; the PHAR smoke-tested; install.sh against a local release
             └─▶ edge.yml      binaries.yml, then the pre-release "edge"
tag v1.2.3   ──▶ release.yml   binaries.yml, then the release: PHAR, binaries, SHA256SUMS, VERSION, notes from CHANGELOG
binaries.yml: the PHAR (built on PHP 8.3) ─▶ static PHP 8.5 runtime + PHAR per platform ─▶ smoke.sh ─▶ attestation
```

## Flow of an edit

```text
agent ──edit hook JSON──▶ kekkai hook pre-tool-use [--agent cursor]
                            │ ClaudeCode | Cursor   the file path from the agent's JSON
                            │ Guard::edit()
                            │   Project::at()       reads kekkai.yaml, .kekkai/scope.json
                            │   Gate::decide()      rules 1 to 7
                            ▼
      ◀── nothing (allow) or the refusal in the agent's format
```

## Flow of a stop

```text
agent ──stop hook JSON──▶ kekkai hook stop [--agent cursor]
                             │ already sent back once (retry N: N+1 times)? → nothing
                             │ Guard::review() → Reviewer::review('HEAD')
                             │   ChangedFiles::since()   git diff + untracked
                             │   Gate::decide() per file
                             │   checks of kekkai.yaml   sh -c, with KEKKAI_CHANGED_FILES
                             ▼
             ◀── nothing (passed) or the report, as the agent's "keep going" answer
```

## Adding an agent

An agent whose hooks can refuse a tool call gets an adapter: a class in `src/Hook/` that takes the file path from its
JSON, asks `Guard::edit()` or `Guard::review()`, and answers in its format; then a case in `Agent` for its files,
its install and its doctor lines. The rules, the scope and the review do not change.

## Design rules

- **Pure core, thin edges.** `Glob`, `Path`, `Config`, `Scope` and `Gate` do no I/O besides reading the
  configuration: they are tested without a filesystem. Git, the scope file and the checks live in `ChangedFiles`,
  `ScopeStore` and `Reviewer`.
- **One decision function.** The edit hook, the review and the MCP server all go through `Gate::decide()`: a path is
  judged the same way before the edit and after.
- **Messages for the agent.** Every refusal says what the scope is and what to do next (`declare_scope`,
  `widen_scope`, ask the user), so the agent corrects itself in one turn instead of guessing.
- **Fail towards the user.** An error in the edit hook asks the user; an error in the stop hook lets the agent stop.
  Kekkai never silently allows an edit, and never traps the agent because of itself.

## Tests

- `tests/Unit`: patterns, paths, configuration, scope, gate, report, hooks, the MCP server over a stream, processes,
  the scope store.
- `tests/Integration`: real git repositories in temporary directories. `ChangedFilesTest`: every kind of change
  (modified, deleted, staged, untracked, renamed, ignored, file names with spaces, no commit yet, another revision).
  `WorkflowTest`: a whole task, from no scope to a passing review.
- `tests/Command`: every command through Symfony's `CommandTester`, `self-update` against a release on disk (latest,
  a version, edge, a tampered file, a missing release).
- `tools/smoke.sh`: a built executable through a whole task, as an agent drives it; run in CI on the source, the
  PHAR and each binary.

`composer check` runs the code style, PHPStan at level max and the tests, as the CI does on PHP 8.3, 8.4 and 8.5.
