# ASCII Quest II - Agent Development Rules

## Project

ASCII Quest is a browser RPG built with:

- PHP
- MariaDB
- Vanilla JavaScript
- CSS
- JSON map files

Do not introduce a framework, ORM, Composer dependency, npm framework,
or other major dependency unless explicitly approved.

## Working Method

Work in small, reviewable changes.

For every task:

1. Read this AGENTS.md file.
2. Read the task file specified by the user.
3. Inspect the existing implementation before changing code.
4. Change only what is required for the current task.
5. Preserve working existing behaviour.
6. Run required tests and syntax checks.
7. Report exactly what changed.
8. Stop and wait for user approval.

Do not automatically continue to another feature.

## Approval Rules

The user reviews and tests every development step.

Do not:

- commit without explicit approval
- push without explicit approval
- change unrelated code
- perform destructive database changes without explicit approval
- invent game mechanics that are not defined in the design/task
- silently refactor unrelated files

When requirements are unclear, stop and report the ambiguity.

## PHP

Keep game rules out of page/controller files where a shared system exists.

Character stat calculations must use:

- `ascii-quest/config/character_stats.php`
- `ascii-quest/lib/CharacterStats.php`

Do not duplicate character-stat formulas inside page files or endpoints.

## Database

MariaDB is the database.

Permanent player state belongs in the database.

Derived values should normally be calculated from their source values
rather than duplicated into database columns.

Database schema changes must use version-controlled migrations under:

`database/migrations/`

Never put database passwords, SSH credentials, API keys, private keys,
tokens, or other secrets inside the repository.

The application database configuration is stored outside the repository at:

`/etc/ascii-quest/config.php`

Do not print or expose its password.

## Character System

Main Champion stats are:

- Strength
- Dexterity
- Vitality
- Energy
- Fate

Level 1 starts with 0 unspent stat points.

Every level gained after Level 1 grants 5 stat points.

Increasing Maximum Life or Maximum Mana must not automatically restore
current HP or Mana.

Example:

`180 / 200 HP`

after +1 Vitality increases Maximum Life by 10:

`180 / 210 HP`

not:

`190 / 210 HP`

## Existing Behaviour To Protect

Do not break:

- login
- character creation
- character selection
- map loading
- character movement
- map transitions
- stairs
- traps
- chests
- character map overrides
- HP persistence
- Mana persistence

## Combat

Exploration and combat are separate systems.

Combat uses a dedicated battle scene.

Combat commands are pointer/mouse-first.

Do not introduce Q/W/E/R or number-key combat shortcuts unless explicitly
approved.

## Testing

For changed PHP files run:

`php -l <file>`

When CharacterStats is affected run:

`php tests/run.php`

For changed JavaScript run:

`node --check <file>`

Also run:

`git diff --check`

Do not report completion if required checks fail.

## Git

Do not commit or push unless explicitly instructed by the user.

Before reporting a task ready for review, show:

`git status`

and summarize the changed files.

## Reporting

After implementation report:

- files created
- files modified
- database changes, if any
- tests run
- test results
- manual testing required

Finish with:

`READY FOR TESTING`

Then stop.
