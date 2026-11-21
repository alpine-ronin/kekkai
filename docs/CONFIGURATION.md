# kekkai.yaml

Kekkai reads `kekkai.yaml` at the project root, else `kekkai.yaml.dist`. Without either, it uses the defaults below.
`kekkai init` writes a commented one to start from. An unknown key is an error, so a typo (`protect:`) never
silently disables a rule.

```yaml
zones:
  catalog: ['src/Catalog/', 'tests/Catalog/']
  billing: ['src/Billing/', 'tests/Billing/']
  shared: 'src/Shared/'

protected:
  - composer.json
  - composer.lock
  - '.env*'
  - 'migrations/'
  - '.github/'

always-allowed:
  - CHANGELOG.md

without-scope: ask
outside-scope: deny
allow-widen: true

checks:
  - name: architecture
    run: phpgraph check
  - name: static analysis
    run: echo "$KEKKAI_CHANGED_FILES" | grep '\.php$' | xargs -r vendor/bin/phpstan analyse --no-progress
  - name: tests
    run: vendor/bin/phpunit
    timeout: 600
    retry: 1
  - name: mutation tests
    run: vendor/bin/infection
    on: [ci]
```

## Keys

| Key | Type | Default | Meaning |
|---|---|---|---|
| `zones` | map of name to pattern or list of patterns | none | Named sets of paths the scope can use by name. Names: letters, digits, `.`, `_`, `-`. |
| `protected` | pattern or list | none | Edits the user approves, whatever the scope. |
| `always-allowed` | pattern or list | none | Edits allowed whatever the scope (and without one). |
| `without-scope` | `allow`, `ask`, `deny` | `ask` | An edit before any scope is declared. |
| `outside-scope` | `ask`, `deny` | `deny` | An edit outside the scope. |
| `allow-widen` | boolean | `true` | Whether the agent may widen its own scope. With `false`, only `kekkai scope widen` does. |
| `checks` | list of `{name, run, timeout, retry, on}` | none | Commands run on the review. |

Patterns: [SPEC.md, Patterns](SPEC.md#patterns). The order of the rules: [SPEC.md, Deciding on one path](SPEC.md#deciding-on-one-path).

## Choosing the policy

| You want | Set |
|---|---|
| The agent always says what it will change, and stays there | `without-scope: deny`, `outside-scope: deny` (the strictest) |
| The agent declares a scope, and you approve each step outside it | `outside-scope: ask` |
| The scope comes from you, never from the agent | `allow-widen: false`, and `kekkai scope declare` before handing the task over |
| Only the stop check, no prompt during the task | `without-scope: allow`, `outside-scope: ask`, and rely on `kekkai check` |

With `outside-scope: ask`, edits you approve stay in the report as `! Outside the scope` but do not fail it: you saw
them. With `deny`, a file outside the scope in the diff can only have come from the shell or a generator, so it fails
the review.

## Zones from bounded contexts

In a DDD project, one zone per bounded context, with its tests, keeps scopes short and readable:

```yaml
zones:
  catalog: ['src/Catalog/', 'tests/Catalog/']
  pricing: ['src/Pricing/', 'tests/Pricing/']
  shared-kernel: ['src/Shared/']
```

The agent then declares `["pricing"]`, and a change that needs the shared kernel shows up as a widening with its
reason, which is exactly what a reviewer wants to see.

## Checks

- Run from the project root, through `sh -c` (`proc_open` with a string), in order, all of them even when one fails.
- Environment: `KEKKAI_CHANGED_FILES` (the changed files, one per line, deletions included), `KEKKAI_BASE` (the git
  revision of the review).
- Pass on exit code 0. `timeout` in seconds (default 300); a check running longer is killed and fails.
- Only the last 30 lines of the output are kept in the report: the agent reads the end of a failing check, not its
  whole log.
- Skipped when no file changed, and with `--no-checks`.
- `on`: when the check runs, a list of `stop` (the stop hook and the MCP tool `check_changes`) and `ci`
  (`kekkai check`). Default `[stop, ci]`. A slow check with `on: [ci]` runs in `kekkai check` but not each time the
  agent stops.
- `retry`: how many more times a failure of this check sends the agent back after the first, from 0 to 5. Default 0:
  the agent is sent back once, then let stop. With `retry: 2`, a check still failing sends it back up to three times
  in a row. The other failures (files outside the scope, checks without `retry`) send it back only the first time.
  For Claude Code, Kekkai counts the send-backs per session in `.kekkai/stop.json`, only when a check has `retry`.
  In Cursor, the hook's `loop_limit` (6, written by `kekkai install cursor`) must cover them.

The stop hook's own timeout in the agent's hook file (900 seconds, written by `kekkai install`) must cover all the
checks together.
