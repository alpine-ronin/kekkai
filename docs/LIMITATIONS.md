# Limitations

- **Not a sandbox.** The agent declares its own scope, and can widen it with a reason. Kekkai stops drift and makes
  it visible; it does not stop an agent determined to get around it.
- **Shell writes are caught after the fact.** `sed -i`, a generator or a formatter writing outside the scope is only
  seen by the stop review, from the git diff. To control commands before they run, use
  [Airlock](https://github.com/alpine-ronin/airlock).
- **Edit hooks for Claude Code and Cursor only.** Other agents get the MCP server and `kekkai check`, with no refusal
  before the edit.
- **Cursor cannot be asked.** It does not enforce *ask* before a tool runs, so an edit Kekkai would ask about is
  refused there, and the agent is told to ask you. The `cursor-agent` CLI does not run the stop hook: run
  `kekkai check` before accepting its work.
- **One scope per checkout.** Two agents in the same directory share it; use one git worktree per agent.
- **Git only.** The review reads the changes from git; without a repository, `kekkai check` fails and the stop hook
  does nothing.
- **Files ignored by git are not reviewed.** A change in an ignored file (a local `.env`, `var/`) is guarded by the
  edit hook but invisible to the review.
- **The review compares with `HEAD`.** Commits the agent makes during the task leave the diff: the stop review then
  misses them. Use `kekkai check --base <revision>` to review a series of commits.
- **Renames count as a deletion and an addition**, both judged against the scope.
- **Sent back once.** If the stop review fails twice, the agent is let stop: read the report yourself. A check
  with `retry` (at most 5) gets more rounds.
- **Binaries for Linux (x86_64, arm64) and macOS on Apple silicon.** Elsewhere (an Intel Mac, Windows), the PHAR
  with PHP 8.3+. The hooks run through `sh`, so on Windows the agent needs Git Bash or WSL.
- **Not on Packagist yet.** Composer installs it from the GitHub repository (see [INSTALL.md](INSTALL.md)).
- **Configuration read at start by the MCP server.** After editing `kekkai.yaml`, reconnect the server (`/mcp`); the
  hooks read it on every call.
