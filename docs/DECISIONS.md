# Decisions

Why Kekkai is the way it is. Each entry: the choice, what was weighed, and what would make us revisit it.

## The scope, not the architecture

phpgraph already checks the architecture (`phpgraph check`: layers, dependency rules, structure, handlers by bounded
context, with a baseline). Kekkai does not redo it. What no tool did was hold an agent to *its task*: a change can
respect every architecture rule and still touch three bounded contexts nobody asked about. Kekkai checks the scope and
runs phpgraph, or any other tool, as a check.

*Revisit if* phpgraph gets a notion of task or diff of its own.

## The agent declares its own scope

The alternatives were a scope set by the user before every task, or one inferred from the request. Asking the user
every time is friction nobody keeps up; inferring is a guess. Having the agent declare it costs one tool call, makes
it state its plan before acting, and gives the reviewer the intent next to the diff. The user can still set it
(`kekkai scope declare`) and forbid widening (`allow-widen: false`).

The price: Kekkai guards against drift, not against an agent set on getting around it. That is stated in the README.

## Widening is allowed, and visible

Refusing every widening would make the agent stop at the first legitimate need (the shared value object nobody
foresaw). Allowing it silently would make the scope meaningless. A widening needs a reason, is kept with who made it,
and shows in every report. A redeclaration by the agent is refused, so it cannot swap a narrow scope for a wide one
without leaving a trace.

## One core, one adapter per agent

The rules, the scope and the review know nothing about any agent: `Guard` asks them. Each agent with hooks gets a
small adapter that only translates its JSON. Kekkai started with Claude Code alone; supporting Cursor took one
adapter and no change to the rules. Where an agent cannot express a decision (Cursor does not enforce *ask* before a
tool runs), the adapter takes the safe side: it refuses and tells the agent to ask.

*Revisit if* agents agree on one hook format.

## The hook never answers `allow`

In Claude Code, a `PreToolUse` hook answering `allow` skips the permission prompt, and other agents behave alike. Kekkai only restricts: for an edit
in the scope it answers nothing, and the user's own permissions apply as before. Installing Kekkai can never give the
agent more rights than it had.

## Review from git, not from the hook's log

The edit hook only sees the edit tools. The agent can also write through the shell, a code generator or a formatter.
Reading the diff from git at stop time catches all of them, at the cost of catching them after the fact. Preventing
shell writes is Airlock's job.

## The stop hook sends the agent back once

A failing check sends the agent back with the report. If it fails again, the agent's own flag (`stop_hook_active` in
Claude Code, `loop_count` in Cursor) tells Kekkai the agent is already continuing because of a stop hook, and Kekkai
lets it stop. A check that can never pass
(a broken test suite, a flaky check) would otherwise keep the agent in an endless, costly loop.

A check opts in to more rounds with `retry` (at most 5): this stays the default rule.

## Kekkai's own files are out of reach

`kekkai.yaml`, `.kekkai/` and `.git/` are denied to the agent whatever the configuration: an agent able to edit its
scope file or its rules has no scope. The user changes them with the CLI or an editor.

## PHP 8.3, two dependencies

A hook runs on every edit, in projects with any PHP version. PHP 8.3 (phpgraph and Airlock need 8.5) and only Symfony
Console and Yaml keep it installable as a Composer dev dependency almost anywhere, and fast to start. The binary
carries its own PHP 8.5; the PHAR is built against PHP 8.3, so that it runs on any supported PHP.

## A binary, a PHAR, and no Docker image

A hook runs on every edit, on the developer's machine, in their project: a container would have to mount the project
and start on each call. The binary (a static PHP runtime with the PHAR appended, as Airlock and phpgraph ship) needs
nothing installed; the PHAR serves systems without a binary. Both come with `SHA256SUMS` and an attestation, and
`kekkai self-update` checks both like the install script.

`self-update` replaces the running file by becoming `mv` (`pcntl_exec`): PHP reads the PHAR's code from its file as
it needs it, so no more of Kekkai's code may run once the file is replaced.

## Short tool descriptions

Every MCP tool listed is read again by the agent on every turn (phpgraph measured about 1,000 tokens per turn for
unused tools). Kekkai lists four tools with one-sentence descriptions; the details are in the server's instructions,
read once.
