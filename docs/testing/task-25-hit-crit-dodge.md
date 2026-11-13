# Task 25 — Activate Accuracy, Critical Hits and Dodging

Base: `7e44e211d3e86e7ce12b497e8444d542b580a120`
Branch: `feature/combat-task-25-hit-crit-dodge`

The explicit Task 25 brief and subsequent user approval of legacy NULL handling
are authoritative. `docs/tasks/current-task.md` still describes historical Task
19. No schema change, migration, historical backfill or database operation is
part of this task. No main promotion or deployment is authorized.

## Formulas and exact resolution order

All probability rolls use the injected, server-owned
`CombatRandomSource::integer(1, 100)`. A roll succeeds when `roll <= chance`.
Percentages are not rounded before comparison; fractional chances retain that
same integer-roll convention. There are no browser rolls, `rand()` or
`mt_rand()` calls. Existing enemy prompt coordinates/token generation continue
to share this source independently of attack resolution.

### Player offensive actions (weapon, unarmed and the existing prototype skill)

1. Read only persisted immutable offense.
2. Hit chance: `clamp(50 + snapshot_accuracy, 5, 95)`.
3. Roll once for hit. A miss applies **0**, records **MISS**, and skips both
   enemy Block and critical rolls. Normal start-time Action consumption,
   timeline, duration and cooldown remain in force; no refund or new action.
4. On hit, run existing enemy Block once: the Cave Brute still has its
   separately configured 20% chance / 50% reduction. Its prevented damage is
   `round(snapshot_base_damage * reduction_percent / 100)` on success, else 0.
   `normal_resolved_damage = max(0, snapshot_base_damage - prevented_damage)`.
5. Roll once for critical **only on a hit**, including at chances 0 and 100.
   Critical chance: `clamp(snapshot_critical_chance, 0, 100)`.
6. On critical, multiplier is `1 + snapshot_critical_damage / 100`, and final
   damage is `max(0, round(normal_resolved_damage * multiplier))`. Otherwise
   final damage is `normal_resolved_damage`.
7. Persist the action result and one explicit outcome event in the existing
   transaction; normal synchronization persists enemy HP and terminal state.

PHP's default `round()` is nearest, with halves away from zero. Critical Damage
is a **bonus percentage**, not a complete multiplier: 20 means **1.20**, so an
unblocked normal 11 becomes **13**, not 2 or 132. Critical scaling occurs once,
**after** existing enemy Block, never on the input snapshot. For example: normal
11, Block prevents rounded 6, remainder 5, critical produces 6. The existing
`prevented_damage` field remains pre-critical enemy Block prevention (6 in that
example), not a subtraction from post-critical damage; critical can exceed the
original incoming damage. A miss records no Block prevention.

This deliberately preserves the defense resolver's existing Block calculation
and prevents double application of weapon/global modifiers or critical scaling.
Task 24 damage authority is unchanged: Strength 5 unarmed is **5 Physical** with
no item identity; Basic Mace is **10–12 Physical**, deterministic midpoint **11**.
No fake unarmed item or new damage-range roll is introduced.

### Enemy offensive actions

1. Read current Champion equipment/stats via `currentDefense()` at resolution.
2. Dodge chance: `clamp(current dodging, 0, 75)`; roll once, even at 0.
3. Successful dodge records **DODGE**, applies **0**, prevents the original
   incoming amount exactly once, and skips the entire passive-defense/Block
   path, including any attempted Block roll. No double prevention.
4. Failed dodge uses the unchanged existing resolver order: resolve current
   Toughness/Fire Resistance once, then roll existing player reaction Block
   once **only if attempted**; apply its existing reduction/minimum-one rule.
   A failed/unattempted Block leaves passive damage unchanged.
5. Persist Champion HP, action result and one outcome event transactionally.

Thus the full order is **dodge → existing passive defense → attempted Block**
for enemy attacks, and **hit → enemy Block → critical** for player attacks.
Enemy accuracy/critical, opposed Accuracy/Dodge and final Block formulas are not
introduced. Both existing Block configurations remain separate and unchanged.

## Snapshot/current boundary and approved legacy compatibility

New player weapon **and skill** actions persist non-NULL `snapshot_accuracy`,
`snapshot_critical_chance`, and `snapshot_critical_damage`, calculated through
shared `CharacterStats` and equipment aggregation at action start. Weapon
`snapshot_base_damage`, `snapshot_damage_type` and item identity retain Task 24
semantics. Skills retain their own configured base damage/type and null weapon
identity, while snapshotting the same equipment-aware Accuracy/critical stats.
No CharacterStats formulas or caps are duplicated or changed.

Later equipment, Strength, Dexterity or Fate changes cannot alter an in-flight
action's offense or outcome. Such changes affect future snapshots only. Current
defensive equipment can affect a pending enemy attack when it resolves. Existing
equipment-service restrictions during combat are not relaxed; tests mutate the
injected reader to exercise this architectural boundary without enabling new
live equipment commands. Rates, elemental/status projection and the existing
prototype visual effect window remain unchanged; no equipment status application
or Life on Hit is activated.

**User-approved legacy policy:** only legacy **pending** actions with existing
SQL NULL offensive snapshots use these immutable neutral values at resolution:

- `snapshot_accuracy = NULL` → 0 → **50% hit chance**;
- `snapshot_critical_chance = NULL` → 0 → **no critical**;
- `snapshot_critical_damage = NULL` → 0 → **no bonus**.

Never look up current Champion/equipment stats to fill those values. Never
backfill or rewrite their persisted NULL snapshots. Missing keys are invalid,
not SQL NULL compatibility. The fallback rejects non-pending action data;
normal resolution already accepts only locked pending actions. Existing resolved
and cancelled history is not reprocessed. New actions do not use this fallback.

## Durable outcomes and HUD

Existing `combat_events.event_type` stores the server decision without adding
columns. Player event types are `player_attack_hit`, `player_attack_miss`,
`player_attack_critical`, `player_attack_block`, and
`player_attack_critical_block`; enemy types are `enemy_attack_hit`,
`enemy_attack_dodge`, and `enemy_attack_block`.

`CombatStateProjector` maps only these allowlisted types to explicit uppercase
`outcome` and boolean `blocked` fields in Battle Info events. In particular,
critical plus enemy Block projects `outcome: CRITICAL, blocked: true`. Neither
client nor projector guesses outcomes from zero damage or message text. A
zero-damage HIT remains HIT, distinct from MISS, BLOCK and DODGE. No raw rolls,
chances, defensive stats or offensive snapshots are added to public state.

Messages include `You miss Cave Brute.`, `CRITICAL! You hit Cave Brute for 13
damage.`, and `DODGE! You dodge Cave Brute's attack.` Critical+Block text states
both. The HUD renders server text with existing safe `textContent`, preserves
critical/blocked emphasis styles, and sets an allowlisted `data-outcome`.

Action completion, HP and outcome events share the existing transaction. Event
failure rolls back the entire result. Once committed, same-time polls, token
replays and fresh service instances read the existing outcome without new rolls,
damage or events. An aborted transaction has no committed result; its retry may
consume a fresh sequence, as before (the random source itself is not transactional).

## RED / GREEN evidence

Before production edits:

- Baseline `php tests/run.php`: **344 passed, 0 failed**.
- Added six Task 25 formula/boundary tests, then `php tests/run.php`:
  **344 passed, 6 failed**. Failures demonstrated absent HIT/MISS/CRITICAL/DODGE
  outcomes, unused Accuracy/critical snapshots, and no dodge prevention.
- Added server-outcome HUD test, then `node tests/CombatHudTest.js`:
  **31 passed, 1 failed** (expected outcome attributes were undefined).

After implementation and deliberate deterministic fixture updates:

- Initial formula GREEN: PHP **350 passed, 0 failed**.
- Expanded snapshot/equipment/event/rollback/replay GREEN: PHP **356 passed,
  0 failed**; CombatHUD **32 passed, 0 failed**.
- After explicit user approval, added pending-only NULL compatibility tests:
  `php tests/run.php` → **357 passed, 1 failed**. The provisional fallback tried
  rolling on non-pending NULL data instead of rejecting it. Narrowed the fallback
  to explicit SQL NULL on pending actions and supplied complete numeric snapshots
  in the two old pure resolver fixtures: **358 passed, 0 failed**.

Final commands/results:

```sh
php tests/run.php
# 358 passed, 0 failed
node tests/ItemHudTest.js
# 21 passed, 0 failed
node tests/CombatHudTest.js
# 32 passed, 0 failed
node tests/ExplorationHudTest.js
# 30 passed, 0 failed
find . -name '*.php' -not -path './.git/*' -print0 | xargs -0 -n1 php -l
# all 120 PHP files passed
find . -name '*.js' -not -path './.git/*' -print0 | xargs -0 -n1 node --check
# all 7 JavaScript files passed
git diff --check
# passed
```

Coverage includes Accuracy 15 → 65 inclusive/66 miss, minimum 5/maximum 95,
0/1/65/100 boundaries, miss zero/no Block or critical roll, critical 0 never and
100 always, critical rounding/20 bonus → 1.20, post-Block scaling, unarmed/mace
normal and critical damage, current dodge 0/12/75-cap boundaries, dodge skips
Block and passive-defense validation, failed dodge preserves defense, no duplicate
Block/prevention, all five immutable offensive values after stat/equipment changes,
real equip/unequip future weapon/skill snapshots, current defensive equipment,
explicit zero HIT, legacy pending NULLs versus high current stats, no backfills,
safe HUD messages, transaction rollback and replay without rerolls. Existing full
suites continue to cover rates, elemental/status projection, ownership, resources,
action allowance/cooldowns, capped timeline, victory/rewards and permanent death.
Old deterministic fixtures now deliberately supply hit/Block/critical/dodge rolls
rather than loosening damage, chronology, lifecycle or idempotency assertions.
Reward/death failure fixtures target their original event types so the added
outcome event does not accidentally weaken those rollback tests.

## Deferred mechanics

Life on Hit; status application; final Block redesign/Block Rate activation;
parry; armour penetration; enemy critical (not previously supported); opposed
Accuracy/Dodge; PvP balance. No new status damage, elemental scaling, healing,
equipment commands, migration, bootstrap or database work is included.

## Manual testing still required

- With Strength 5, verify unarmed **5 Physical**, Basic Mace **10–12 Physical**,
  no fake item, and normal/critical/MISS Battle Info with real pointer attacks.
- Check visible DODGE and BLOCK outcomes, including an accepted Block prompt
  followed by dodge; verify zero-damage HIT does not display as a miss.
- Verify cooldown/turn consumption on misses and replay/refresh without duplicate
  messages, HP changes, critical rolls, rewards or permanent-death processing.
- Outside combat, equip Accuracy/critical/dodge gear, then check new combat
  snapshots and incoming damage; confirm attack/cast rates and other HUD stats.
- Smoke-test login/selection, exploration/movement/transitions/stairs,
  traps/chests/map overrides, equipment persistence, HP/Mana persistence,
  victory/loot and permanent-death screens.

Automated tests use deterministic/in-memory fixtures; no browser, production,
MariaDB, migration, main promotion or deployment was used. Stop for ChatGPT
GitHub review after the normal feature-branch push.
