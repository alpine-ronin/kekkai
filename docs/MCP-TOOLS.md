# MCP tools

`kekkai serve` exposes four tools. Their descriptions are short on purpose: every listed tool is read again by the
agent on every turn. The server's instructions tell the agent to declare a scope before its first edit and to call
`check_changes` before saying it is done.

| Tool | When |
|---|---|
| `declare_scope` | Before the first edit of a task |
| `widen_scope` | When the task truly needs a path outside the scope |
| `show_scope` | To read the scope and the zones |
| `check_changes` | Before saying the task is done |

## declare_scope

| Argument | Type | Required |
|---|---|---|
| `intent` | string: one sentence, what the change is for | yes |
| `paths` | list of strings: paths, globs or zone names | yes |

```json
{"name": "declare_scope", "arguments": {"intent": "Add a price to products", "paths": ["catalog", "config/services.yaml"]}}
```

```text
Scope declared.
Scope: Add a price to products
  catalog = src/Catalog/, tests/Catalog/
  config/services.yaml
```

Errors: a scope is already declared (the message shows it and says to widen it or ask the user to clear it), empty
intent, no path, a path outside the project, a pattern covering the whole project.

## widen_scope

| Argument | Type | Required |
|---|---|---|
| `paths` | list of strings | yes |
| `reason` | string: why the task needs them | yes |

```json
{"name": "widen_scope", "arguments": {"paths": ["src/Shared/Money.php"], "reason": "Prices are rounded by Money"}}
```

```text
Scope widened.
Scope: Add a price to products
  catalog = src/Catalog/, tests/Catalog/
  config/services.yaml
  src/Shared/Money.php (widened by agent: Prices are rounded by Money)
```

Errors: `allow-widen: false` (the message tells the agent to stop and ask the user), no scope, empty reason, nothing
new.

## show_scope

No argument. The scope with its widenings, or `No scope declared.`, then the zones of `kekkai.yaml`.

## check_changes

| Argument | Type | Default |
|---|---|---|
| `base` | git revision | `HEAD` |
| `run_checks` | boolean | `true` |

The review as text, the same as `kekkai check`, with the checks that run at stop (`on` holding `stop`):

```text
Kekkai: 4 file(s) changed since HEAD. FAILED
Scope: Add a price to products
  catalog = src/Catalog/, tests/Catalog/
✗ Outside the scope:
  src/Billing/Invoice.php
! Protected files touched (check the user approved them):
  composer.lock
✓ check architecture
✗ check tests
    [... 112 lines cut]
    1) Catalog\Tests\ProductTest::testPrice
    ...
```
