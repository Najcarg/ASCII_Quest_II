# Task 28 — Direct elemental bonus damage

Base: `d85f1beffce37b3734dd33bbaa0eaa59c20947fc`
Branch: `feature/combat-task-28-elemental-damage`
Worktree: `/home/najcarg/Projects/ASCII_Quest_II/task28-elemental-damage`

The explicit Task 28 brief governs this change. `docs/tasks/current-task.md`
contains historical Task 19. No item seed/catalogue or generated-affix availability
changes are included.

## Migration 008 — NOT APPLIED

`database/migrations/008_combat_elemental_snapshots.sql` requires the single
`007_character_equipment` migration record. It rejects recorded reruns and any
existing subset (or all) of its four columns without its record, before DDL.
It also checks the existing nullable unsigned native base-damage column shape.
One ALTER adds exactly four `INT UNSIGNED NULL` columns to `combat_actions`:

- `snapshot_fire_damage`
- `snapshot_lightning_damage`
- `snapshot_cold_damage`
- `snapshot_poison_damage`

It records `008_combat_elemental_snapshots` once after DDL. No gameplay data,
resource, equipment, item, or historical snapshot writes/backfills are included.
The separate `_verify.sql` is READ-ONLY: inspect migration identity and all four
column types, nullability, and defaults. MariaDB DDL is not transactionally
rolled back; partial installs must be inspected, not automatically repaired.

**SQL inspection/tests only. Migration NOT APPLIED.** No database, production,
bootstrap, promotion, or deployment operations were performed. Migration 008
requires a separate explicitly authorized application before this runtime can
be deployed against an older schema. Actual MariaDB execution is unverified.

## Snapshot authority and native damage

Locked equipped items → `EquipmentStatAggregator` → `CharacterStats.utility` →
`PersistentCombatEquipmentProvider.offensiveSnapshot()` at action start. No
combat affix parsing, duplicated aggregation, or JavaScript calculation.

Every new normal weapon/unarmed attack stores all four explicit nonnegative
integer bonuses, including zeros. New nonoffensive player potion/Block commands
also store four zero bonuses; their other native/offensive fields stay NULL. Native `snapshot_base_damage` and
`snapshot_damage_type` remain separate. Physical range/Strength rules are
unchanged: Strength5 unarmed5; Mace5–7 becomes10–12 (midpoint11). Native
nonphysical weapons receive no Strength contribution. Flat elemental bonuses
are not folded into the range, percentage damage, or native snapshot.

Skills, including Flame Strike, store all four bonus snapshots as zero and
retain their own native base/type (Flame Strike24 Fire). The resolver also
excludes bonuses on skill actions defensively. Native elemental damage still
uses the corresponding enemy resistance. The existing Flame Strike effect
presentation/lifecycle is unchanged; this task introduces no effect ticks.

Snapshots remain unchanged after start, even when test fixtures replace gear
or stats. `CombatRepository` binds all four INSERT columns; resolution only
updates lifecycle/results. Legacy **pending** SQL NULL bonuses mean zero;
missing fields or NULL outside pending compatibility are invalid on resolution.
Never substitute current equipment, backfill NULLs, or revisit resolved history.
Current utility reads for Task26 Life on Hit remain its existing separate policy.

## Server model, formula, and exact order

Cave Brute explicitly declares `server_only.resistances` with fire, lightning,
cold, and poison all zero. Each entry is an integer from 0 to 100. No new physical
resistance or change to player defense/Task27 active Block Rate.

Internally the resolver builds named physical/fire/lightning/cold/poison
components. Add native damage to its type and bonus snapshots to their types;
combine native+bonus of the same type **before** rounding. Physical passes
through unchanged. For each elemental component:

```text
resisted_component = max(0, round(component * (1 - resistance_percent / 100)))
```

Use PHP/project nearest rounding (`10 Fire @25% =8`), with no minimum-one rule.

1. Task25 Accuracy hit roll. MISS returns 0 immediately; no elemental processing,
   enemy Block, or critical roll.
2. Build immutable typed components and apply per-element resistance.
3. Sum resisted components.
4. ONE existing enemy Block roll/reduction on that sum (nearest rounding).
5. ONE Task25 critical roll multiplying the remaining total once.
6. Apply final `applied_damage` to enemy HP.
7. Task26 Life on Hit uses explicit contact outcome, once at most, never per
   component; skills remain excluded. Zero damage HIT/BLOCK/CRITICAL is contact,
   not a MISS. Victory/death and replay boundaries remain unchanged.

`incoming_damage` on contact is the unresisted component sum; `prevented_damage`
is resistance plus Block prevention before critical scaling. Existing result
shape, event text, and safe HUD outcome projection are retained without clutter.

Exact focused examples (successful hit, no Block/crit unless specified):

| Components/resistance | Final total |
| --- | ---: |
| Physical10 only | 10 |
| Physical10 + Fire5 @0% | 15 |
| Physical10 + Fire10 @50% | 15 |
| Physical10 + Fire10 + Lightning8 + Cold6 + Poison4 @0% | 38 |
| Above elements @25%/50%/100%/75% | 23 |
| Native Fire1 + bonus Fire1 @50% (combine first) | 1 |
| Native Fire10 @25% | 8 |
| All Fire damage @100% | 0 HIT |
| Physical10 + Fire10 @50%, enemy Block50% | 7 (15 minus8) |
| Same without Block, critical bonus100% | 30 |
| Same with Block then critical bonus100% | 14 |
| Flame Strike24 Fire @25%, no equipment bonuses | 18 |

## Automated RED → GREEN

Before runtime/migration edits, `php tests/run_task28.php`: **1 passed,12 failed**.
Missing migrations/config/snapshots and inactive bonus/resistance calculations
failed; existing legacy-zero behavior already passed. Four additional edge-case
cases were subsequently added (MISS short circuit, malformed snapshots,
SQL bind/immutable UPDATE contract, native Fire skill resistance). A fifth test
for explicit zeros on nonoffensive player commands produced a second RED:
**17 passed,1 failed**, then GREEN after adding four zeros to each command.

Final commands/results:

- `php tests/run_task28.php`: **18 passed,0 failed**.
- `php tests/run.php`: **416 passed,0 failed**. Includes Tasks24–27, attack/cast
  timing, HP/Mana, victory/death, ownership, equipment locks, loot, replay,
  and transactional failure regressions.
- `node tests/ItemHudTest.js`: **21 passed,0 failed**.
- `node tests/CombatHudTest.js`: **32 passed,0 failed**.
- `node tests/ExplorationHudTest.js`: **30 passed,0 failed**.
- `find . -type f -name '*.php' -print0 | sort -z | xargs -0 -n1 php -l`:
  **125 files passed**.
- `find . -type f -name '*.js' -print0 | sort -z | xargs -0 -n1 node --check`:
  **7 files passed**.
- `git diff --check`: passed.

Existing neutral fixtures now include explicit zero bonuses or legacy NULLs.
One Task25 fixture intentionally equips Fire999: its normal critical expectation
is now `(unarmed5 +999) *2 =2008`, while its skill remains48. No prior test was
removed, and the unrelated stat/rate/defense assertions remain intact.

## Intentionally deferred

Manual browser testing for Tasks25–28 is intentionally deferred until the later
generated affix/item availability and balance pass. No browser claim is made.
Also deferred: status effects, burn, poison-over-time, bleed, freeze, shock,
DOT/ticks/durations/application chances, penetration, element-specific critical,
conversion, skill elemental scaling, and item seed/affix availability/balancing.
The existing reserved status utility/chance display is not activated by combat.

Stop after feature push for ChatGPT GitHub review; no main promotion/deployment.
