# Task 32.1 — Secure CLI database bootstrap

## Scope and read-only production findings

Base: `455549caa200202fa4e294a426f60642ee13237c`.
Branch: `hotfix/task32-cli-db-bootstrap`.
Worktree: `/home/najcarg/Projects/ASCII_Quest_II/task32-1-cli-db-bootstrap`.
Read `AGENTS.md` and existing Task32 implementation/testing guidance. Original
checkout's untracked `.agents/` and `.pi/` are preserved.

Only server-local structural token parsing and filesystem/process metadata were
emitted during the authorized read-only inspection. No source, credentials,
connection strings, config values, or secret database usernames were displayed.
No live bootstrap was included/executed, and no production connection was opened.

Findings:

- Checkout `ascii-quest/db.php` is intentionally absent/ignored. The old maintenance
  script required only that absent checkout file, preventing normal checkout use.
- Live `/var/www/html/ascii-quest/db.php` exists and is readable to the SSH user;
  UID/GID `1000:1000`, mode `0664` (observed, **not changed**).
- Its line 6 `require` uses the **absolute** `/etc/ascii-quest/config.php` path.
  It declares global `getDb`; structural parsing found no `global` imports or
  `$GLOBALS` access. No connection implementation was copied or rewritten.
- `/etc/ascii-quest` is `root:www-data`, mode `0750`; `/etc` is `root:root`,
  mode `0755`. SSH principal `najcarg` is not in `www-data`. Apache workers run
  as `www-data`. The SSH principal cannot traverse the config's parent directory.
- `file_exists`/`is_readable` on the protected config returned false and `stat`
  was denied. This is **not evidence that the config file is missing**. Its own
  owner/group/mode/content could not be inspected under this principal. Supervisor
  approved recording this explicit limit rather than interactive sudo inspection.

**Root cause has two layers:** the checkout-only source selection fails first;
including the live bootstrap directly as the SSH principal then fails on protected
config traversal. The live dependency is already absolute, so cwd is irrelevant
and an environment/include-path override is not the remedy. Apache's filesystem
principal can traverse the protected directory; this explains the CLI/Apache
access difference, not an Apache cwd workaround. No Apache connection or protected
file content verification was attempted.

**No protected `db.php` path correction is indicated.** Do not replace its
connection code or weaken config permissions, change groups/grants, copy secrets,
or add a credential fallback. After review and separately authorized deployment,
any live preview needs explicit authorization to run under a principal already
able to read the protected config (for example `www-data` using narrowly approved
sudo). The helper does not elevate privileges or solve OS permission denial.
If later evidence actually requires a protected path-only correction, obtain new
approval, back up first, preserve credentials/owner/group/modes/security, and change
only the dependency path. **No such correction is authorized or performed here.**

## Design and failure boundaries

`DatabaseBootstrap::connect()` is reusable and CLI-only. Its public API accepts
no source arguments. It uses paths anchored to its own directory, independent of
cwd, and selects:

1. Repository `ascii-quest/db.php`, when a local entry exists.
2. Only if absent, exactly `/var/www/html/ascii-quest/db.php`.

There are no environment/request/CLI source overrides. A local unreadable file,
unsearchable parent, directory, dangling link, parse error, missing/invalid
`getDb`, thrown exception or non-PDO return fails closed, never silently trying
production instead. Missing/unloadable approved production source also fails.
Existing trusted `getDb()` is invoked with no arguments and must return `PDO`;
no connection credentials, query, DSN or duplicate PDO constructor is introduced.

One attempt per process prevents retrying a partially executed bootstrap whose
global `getDb` function survived a failure. Preexisting global `getDb` is rejected;
a successful connection is cached. Subprocess fixtures isolate global functions.
The source-path seam is private and only invoked reflectively with bounded temp
fixtures in tests, never via argv/environment or a browser API.

During bootstrap inclusion **and** `getDb` execution, a discard output buffer
suppresses accidental inline text, echo and diagnostic dumps. PHP display/log
errors are disabled temporarily and warnings become generic exceptions. Throwable
messages and previous exceptions are not propagated. Buffer, ini settings and
error handler are restored. Malformed fixtures with raw content or parse errors
cannot print that content in the tested loading paths. The CLI reports only
`maintenance_failed` on bootstrap failure, never paths, PDO details or traces.
Strict argument parsing remains before helper loading. Both helper and entrypoint
reject non-CLI SAPI with 404 before any bootstrap loading.

This is a **trusted PHP bootstrap loader, not a sandbox**. It cannot promise to
contain deliberately hostile PHP that changes error handlers/ini settings,
removes the buffer, writes directly to STDERR/files, registers shutdown handlers,
or terminates the process. Noncatchable engine termination cannot be normalized
into a successful service invocation. The approved/bootstrap filesystem remains
an operational trust boundary; existing observed permissions were not changed.
Code using global variables imported into `getDb` is outside the inspected
bootstrap's contract: inclusion is in an isolated closure, while function
symbols/constants remain process-global. No such global imports were observed.

## Tests and RED/GREEN evidence

Initial `php tests/run_task32_1.php`: **0 passed, 9 failed** before implementation
(missing helper/source selection and unsuppressed fixture output).
Final expanded suite: **15 passed, 0 failed**. All fixtures are temporary,
credential-free and use fresh PHP subprocesses running from `/`:

- local selection despite available production fixture; absent-local approved
  selection; neither present; local directory/dangling link/unreadable file and
  parent; malformed/inline content, parse errors, wrong PDO type, required args,
  warnings, missing or unreadable protected dependency;
- thrown PDO exception and echoed/dumped synthetic private markers suppressed;
- preexisting `getDb`, failed-load function pollution and retry rejection;
- successful PDO caching and restoration of output/error/ini state;
- invalid argv rejected before any include; unsupported source override rejected;
- normal valid-argument CLI failure emits only generic failure;
- normal CLI preview uses real service/repository with existing in-memory
  Task32Pdo fixture and emits only intended JSON (14 would-create, zero created),
  not bootstrap marker/path/PDO diagnostics;
- real loopback PHP development-server requests to entrypoint/helper return empty
  404 responses without loading a marker bootstrap; no browser route registration.

Final regression totals (all zero failures):

| Command | Passed |
| --- | ---: |
| `php tests/run.php` | 803 |
| `php tests/run_task32_1.php` | 15 |
| `php tests/run_task32.php` | 55 |
| `node tests/ItemHudTest.js` | 21 |
| `node tests/CombatHudTest.js` | 32 |
| `node tests/ExplorationHudTest.js` | 30 |
| `php -l` on all PHP files | 145 |
| `node --check` on all JS files | 7 |
| `git diff --check` | clean |

Permission fixtures were run as an unprivileged local user, not root. The server
used by HTTP tests is temporary, local, credential-free and always terminated.
No automated test depends on production files, credentials, database or network.

## Explicit limits / future operation

No main promotion, deployment, live-file change, production preview, SQL access,
migration, grant, cleanup, autoequip, catalogue/combat change or inventory write
was performed. Existing bootstrap/config permissions and contents are untouched.
The helper resolves the correct live source but **does not make SSH-principal
production preview operational** without appropriately authorized config access.

No production service connection, schema prerequisites or real MariaDB behavior
was tested. Historical Task32 notes are not evidence of current migration state;
this task did not inspect or alter it. After GitHub review and separately approved
deployment/access authorization, the only contemplated production service check
is **user 2 / character 16, preview only, no `--apply`, no cleanup**. Do not run it
as part of this task. Any schema work remains separately authorized.

Stop after feature push for ChatGPT GitHub review. No deployment is included.
