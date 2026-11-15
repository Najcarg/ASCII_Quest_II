# Task 30 — Normalize status-effect chance scale

Base: `7cbcb636e54ec25df8af07c9f67a56176dde2eec`
Branch: `feature/combat-task-30-status-chance-scale`
Worktree: `/home/najcarg/Projects/ASCII_Quest_II/task30-status-chance-scale`

`AGENTS.md`, the existing config, CharacterStats/CombatStatusEffects, Task29
fixtures and documentation were inspected before editing. The explicit Task30
brief governs; `docs/tasks/current-task.md` is historical Task19, not this scope.
Main and preexisting `.agents/` and `.pi/` are not modified.

## One production change, one chance authority

Only `ascii-quest/config/character_stats.php` changes production behavior:
`status_effect.divisor` changes from `100.0` to `0.20`. The cap remains90%.

```text
For each eligible status independently:
  potency == 0: no roll
  chance_percent = min(90, potency / 0.20) = min(90, 5 * potency)
  success iff integer(1,100) <= chance_percent
```

`CharacterStats::statusEffectChance` remains the sole calculation authority.
`CombatStatusEffects` still calls it with only the selected status populated,
in poison/bleed/burn/freeze/shock order. No combat-local `potency * 5` formula
was added. The existing character utility summary still uses the highest status
potency, not the sum; combat still uses each status's own chance separately.

The old divisor was unsuitable for low integer potencies: potency5 gave0.05%,
and potency1–99 could never succeed against integer1..100. The new divisor makes
small potencies useful without multiplying their damage or control strength.

| Potency (each of all five statuses) | Chance | Inclusive success / next-roll failure |
| --- | --- | --- |
| 0 | 0% | No roll at all |
| 1 | 5% | 5 / 6 |
| 2 | 10% | 10 / 11 |
| 3 | 15% | 15 / 16 |
| 4 | 20% | 20 / 21 |
| 5 | 25% | 25 / 26 |
| 7 | 35% | 35 / 36 |
| 10 | 50% | 50 / 51 |
| 18 | 90% | 90 / 91 |
| 100 | 90% cap | 90 / 91 |

## Potency and poison coupling are unchanged

- Poison5 keeps Task28 direct poison **+5**, independent of the status roll. On
  roll25 it also applies poison potency5, ticking5 at each of +1/+2/+3/+4 seconds
  with zero enemy poison resistance. Roll26 fails the status but keeps direct+5.
  With fixture Strength5, direct damage is10, then four5-damage ticks on success;
  failed status leaves total damage10 and creates no ticks.
- Burn5 and bleed5 each tick5, not25; they add no Task28 direct component.
- Freeze5 still slows new enemy actions by5%: a2000ms Fire Slam becomes2100ms,
  not2500ms, and its base cooldown is unchanged.
- Shock5 still amplifies subsequent direct damage by5% (100 becomes105), not25%.
  Newly applied Shock cannot amplify its own contact.

No changes to four-second duration, four ticks, refresh replacement, Freeze/Shock
50% potency caps, resistances, rounding, resolution order, snapshots, durable
identity, persistence, events, replay or the table/Migration009. No SQL, database,
item catalogue, affix, deployment or production operations were performed.

## Tests and narrow fixture updates

`tests/CombatTask30Test.php` adds **152** named tests:

- **50** authority/utility cases: ten literal expected potency/chance pairs for
  EACH of poison, bleed, burn, freeze and shock; original potency asserted.
- **95** real service application cases: EACH status has one zero/no-roll case
  and18 success/failure boundary cases through snapshot/resolver/repository flow.
  Applications assert original potency and unchanged4000ms schedule; failures
  create no status. Exactly zero or one status draw follows the three direct draws.
- **7** low-potency behavior cases: three four-tick/replay tests, failed poison
  coupling, Freeze5, Shock5 and mixed independent low-potency rolls/order.

Focused runner: `php tests/run_task30.php`. Full PHP runner includes the same
152 cases. Existing Task29 fixture changes are limited to probability-sensitive
cases: mixed20% uses potency4 instead of2000; cap boundaries use100 instead of9001;
small-potency boundaries now expect5% at potency1. No damage/tick/slow fixtures
were rescaled. Its seeded-behavior comment now explains probability isolation,
not an obsolete impossibility of low-potency procs. The CharacterStats highest
fixture uses uncapped values1/3/8/0/2 (40%, not a90% test that could hide a wrong
maximum/sum rule). Task24's poison200 potency is retained, updating only its
expected utility chance from2% to90%. Task29 docs distinguish current chance
rules from historical Task29 formulas and validation evidence.

## Real RED → GREEN

Before test edits: `php tests/run.php` **459 passed,0 failed**;
`php tests/run_task29.php` **43 passed,0 failed**.

Before touching the config, with all152 new tests and full-runner registration:

- `php tests/run_task30.php`: **56 passed,96 failed**, exit1.
- `php tests/run.php`: **515 passed,96 failed**, exit1.
- Example genuine failure: poison1 authority expected5%, got0.01%; its roll5
  application expected poison, got no active status. Equivalent failures occurred
  for every status. RED output had no PHP warnings; config diff was still empty.

After the one-line config change, focused Task30 immediately became **152/0**.
Full PHP initially reported **603/8**: only obsolete CharacterStats, Task24 utility,
Task29 mixed and five Task29 chance-boundary expectations. Updating those exact
scale-sensitive fixtures (without changing damage semantics) produced **611/0**.

Final validation, all exit0:

| Command | Exact result |
| --- | --- |
| `php tests/run.php` | **611 passed,0 failed** (459 existing +152 new) |
| `php tests/run_task29.php` | **43 passed,0 failed** |
| `php tests/run_task30.php` | **152 passed,0 failed** |
| `node tests/ItemHudTest.js` | **21 passed,0 failed** |
| `node tests/CombatHudTest.js` | **32 passed,0 failed** |
| `node tests/ExplorationHudTest.js` | **30 passed,0 failed** |
| `find . -type f -name '*.php' -print0 \| sort -z \| xargs -0 -n1 php -l` | **132 files passed** |
| `find . -type f -name '*.js' -print0 \| sort -z \| xargs -0 -n1 node --check` | **7 files passed** |
| `git diff --check` | Passed |

Task24–29 regressions remain green, including HIT/CRITICAL/BLOCK contact, MISS
no-roll, skill exclusion (including injected nonzero snapshots), immutable
snapshots, independent draws, direct poison, DoT/resistance, Freeze/Shock caps,
refresh/expiry/ordering, rollback and durable replay. The only runtime diff is
one config literal; CharacterStats and CombatStatusEffects are untouched.

## Limits and review gate

Automated combat tests use existing transaction-snapshotted fake PDO and actual
services/resolver/repository; they do not validate live MariaDB locking. Existing
migration tests inspect SQL text only, without executing SQL or accessing a DB.
Manual browser testing is explicitly deferred. Item/affix balancing and actual
production behavior have not been exercised. No main promotion, deployment,
migration, bootstrap, rebase, squash or force push is authorized/performed.
Stop after feature push for ChatGPT GitHub review.
