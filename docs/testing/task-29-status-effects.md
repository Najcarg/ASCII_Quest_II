# Task 29 — Durable status effects

Base: `2c2ed1d83761cf820a65ca337e5d8eaed1143e7d`
Branch: `feature/combat-task-29-status-effects`
Worktree: `/tmp/ascii-quest-task29`

The explicit Task29 brief governs. `AGENTS.md` was read;
`docs/tasks/current-task.md` is historical Task19, not this task's scope.
No item seeds/catalogue, affix availability, production, database execution,
main promotion, deployment, or bootstrap changes/operations are included.

## Migration 009 — NOT APPLIED

`009_combat_status_effects.sql` requires exactly one prerequisite record
`008_combat_elemental_snapshots`. Before DDL it rejects recorded reruns, any
partial/all existing status columns, and an existing status table without the
record. It checks InnoDB combat tables, unsigned INT primary keys, and all four
nullable unsigned INT prerequisite008 snapshots. MariaDB DDL is not atomic:
a partially executed install must be inspected, never silently repaired/rerun.

Exactly five `INT UNSIGNED NULL` columns are added to `combat_actions`:

- `snapshot_poison_status_damage`
- `snapshot_bleed_status_damage`
- `snapshot_burn_status_damage`
- `snapshot_freeze_status_damage`
- `snapshot_shock_status_damage`

`combat_status_effects` stores encounter/source action, enemy target, status,
nonnegative unsigned potency, application/expiry and nullable next-tick combat
milliseconds, active/expired state, and DATETIME(6) creation/update times.
FKs reference encounters and actions; the locked repository also checks source
membership in the encounter. CHECKs constrain keys, enemy-only target, state,
4000ms duration, and damaging versus non-damaging tick shape. Schedule indexes
support encounter/state/timeline reads.

Two uniqueness rules have different purposes:

1. `(source_action_id,target_actor,status_key)` is permanent application identity.
2. `(encounter_id,target_actor,status_key,active_slot)` permits only one active
   instance. `active_slot` is generated as1 for active rows and NULL for expired
   rows, allowing retained expired identities without permitting active stacks.

Refresh expires the old row and inserts a **new identity**, never overwrites the
old source replay guard. The migration writes only its one migration record
besides schema DDL; no gameplay mutation, item modification, history backfill,
or unrelated data changes. The separate `_verify.sql` is read-only and exposes
records, table/column/default/generated shapes, indexes, FKs and CHECKs.

**SQL was inspected/tested only. Migration009 NOT APPLIED.** Actual MariaDB
execution, engine constraints and true concurrent sessions remain unverified.
Application of009 requires separate explicit authorization before this runtime
can be deployed on a schema lacking it. No database credentials were accessed.

## Immutable authority and exact probability

Locked equipped items → `EquipmentStatAggregator` → `CharacterStats.utility`
(`poison_damage`, `bleed_damage`, `burn_damage`, `freeze_damage`, `shock_damage`)
→ persistent offensive provider → immutable action-start snapshots. No runtime
affix parsing, browser authority, current-gear substitution or duplicated stats
formula. Multiple equipped items aggregate through the existing authority.

Every new normal weapon/unarmed action stores all five nonnegative integers,
including explicit0. Skills store five explicit0 and cannot apply equipment
statuses, even if malformed nonzero status snapshots are injected. New potion,
Block, enemy and system-effect rows receive neutral status values. Only legacy
**pending SQL NULL** is interpreted as0; the NULL remains unchanged. Missing or
malformed normal contact snapshots are rejected. Resolved/cancelled history is
never rerolled, reinterpreted or backfilled.

For EACH status separately, in poison/bleed/burn/freeze/shock order:

```text
potency == 0: no roll
chance = CharacterStats::statusEffectChance({selected: potency, other four: 0})
       = min(90, potency / 100) percent       # existing divisor100, cap90
success iff CombatRandomSource.integer(1,100) <= chance
```

There is exactly one independent draw for each positive-potency eligible status,
not one shared/highest-status draw or one draw per direct damage component. The
fractional chance is not rounded up: potency2000 gives20% (20 succeeds,21 fails),
potency9001 remains90% (90 succeeds,91 fails). With integer draws, potency1–99
cannot proc; potency100 first permits roll1. **Later item/affix balancing must
account for this existing probability formula**, rather than changing it here.
Small potency behavior tests seed already-applied durable effects through the
locked repository; they do not pretend that potency20 can proc on an integer
roll under the existing formula.

Rolls occur only after normal landed HIT/CRITICAL/BLOCK contact, including zero
damage. No MISS, skill, enemy, cancelled/resolved/replayed-action status draws.
Critical damage and enemy Block do not scale status potency. Current player
Block Rate is unrelated. Task28 direct poison damage remains alongside poison
status damage, not replaced by it.

## Five behaviors and refresh

All statuses last4000 **combat timeline** milliseconds, not wall-clock time.
Catch-up uses the existing disconnected-gap cap.

| Status | Behavior |
| --- | --- |
| Poison | At +1000/+2000/+3000/+4000, `max(0,round(potency*(1-poison_resistance/100)))`. |
| Burn | Same four ticks using enemy fire resistance. |
| Bleed | Same four ticks, raw physical potency; no additional armour calculation. |
| Freeze | No ticks/damage. Enemy actions STARTED while active get `round(base_duration_ms*(1+clamp(potency,0,50)/100))`. |
| Shock | No ticks/damage. Summed direct POST-resistance damage becomes `round(sum*(1+clamp(potency,0,50)/100))`, BEFORE Block and critical. |

PHP/project nearest rounding is used everywhere; 7 at50% resistance ticks4.
100% resistance legitimately ticks0; there is no minimum-one rule. DoTs cannot
crit, Block, heal Life on Hit, produce secondary procs, or receive Shock scaling.
They emit one actual scheduled tick event even when resistance reduces it to0.

Freeze20 adds20% (Fire Slam2000→2400ms); the cap is50%. It never edits pending
actions or their cooldowns. The policy's Turn-fit checks and persisted start
both use the same slowed duration; a newly slowed action that cannot fit waits.
Shock20 adds20% to existing direct damage (including native skill direct damage),
with the same50% cap. Newly applied Shock cannot amplify its own attack. Expiry
is exclusive for Freeze/Shock: at `now == expires`, they no longer modify actions.

Refresh **replaces** potency, never adds it. Applied=now, expiry=now+4000, first
DoT tick=now+1000; Freeze/Shock keep NULL tick schedules. Old rows become expired,
with no remaining old ticks. Replaying an old source cannot retire/refresh a newer
active source. Four ticks occur only if uninterrupted by refresh or terminal state.

## Direct order, chronology, terminal behavior

1. Existing accuracy hit check; MISS stops.
2. Immutable typed direct components, combining native+bonus of the same type.
3. Per-element enemy resistance, then summed resisted direct damage.
4. Existing active Shock amplification, once on the sum.
5. Existing enemy Block roll/reduction once.
6. Existing critical roll/scaling once.
7. Apply direct damage to enemy HP.
8. Existing normal-contact Life on Hit, once at most.
9. Independent status draws, then durable create/refresh and application events.
10. Existing terminal/victory/reward handling.

`incoming_damage` remains the unresisted direct component sum. `prevented_damage`
records actual resistance prevention plus actual Block prevention before critical;
Shock bonus damage is not negative prevention and does not erase mitigation.
With no Shock this is identical to Task28's result.

The synchronizer includes the next durable tick/expiry among future candidates,
so large catch-up interleaves statuses, actions, Turns and enemy decisions rather
than resolving all actions before all ticks. **Deterministic ties:** existing
zero-HP terminal checks first; due statuses in ascending durable row id; pending
actions in existing resolves-time/id ascending order; then Turn rollover and
new enemy decision. The inclusive fourth DoT tick happens before its row expires.
If a refresh attack ties an old tick, that old tick occurs first, then refresh
resets the next tick. No duplicate old/new tick at that cursor. Freeze/Shock expiry
is processed before tied actions/starts. Same-cursor new applications affect later
actions in the existing action order, never their own direct damage.

A lethal tick immediately uses the existing victory/reward/drop path exactly
once, cancels pending later actions (including tied enemy actions), and stops
all later status work. Defeat retains the existing precedence when a Champion
is already at0. Terminal encounters return no public active statuses. Retained
active rows/schedules on a terminal encounter are inert and never resumed;
terminal handling need not rewrite historical status rows.

## Transactions, durability and public state

All mutations run under the existing owned-character → encounter aggregate row
locks and single service transaction. Status reads for mutation lock rows;
updates compare the durable expected next tick/state. Direct/tick HP, events,
schedule advance, refresh retirement/insertion, action resolution and victory
rewards either commit together or roll back together. A late persistence failure
after four ticks restores all four schedules/events and HP. A failed uncommitted
attempt may draw again on retry; committed/resolved actions never reroll.

There is no in-memory tick cursor or status cache. A new service, page refresh,
second-tab request or request-token replay sees the same durable state. Existing
no-op encounter version increments remain unchanged; idempotency asserts HP,
actions, statuses, rewards and events, not suppression of existing metadata writes.

Application text: `Cave Brute is Poisoned/Burning/Bleeding/Frozen/Shocked.`
Tick text: `Poison/Burn/Bleed deals N damage.` No expiry/no-op spam is added.
`enemy.status_effects` is allowlisted to `[{key,potency,remaining_ms}]`; hidden
rolls, source ids and internal schedules are never exposed. Existing Battle Info
renders server-owned events. No new HUD calculation, animation or client status
simulation was needed.

## Real RED → GREEN and final validation

Before runtime edits, the baseline `php tests/run.php` was **416 passed,0 failed**.
Initial `php tests/run_task29.php` was **0 passed,20 failed**: absent migration,
snapshots, durable table operations and runtime behavior. Later behavioral/edge
coverage increased the focused suite to43 tests (with additional matrix cases
inside tests). A second genuine RED exposed mitigation bookkeeping under Shock:
`php tests/run_task29.php` was **42 passed,1 failed** (expected resistance5,
got2). Keeping resistance and Block prevention independent of amplification fixed
that failure without changing non-Shock behavior.

Final results:

- `php tests/run_task29.php`: **43 passed,0 failed**.
- `php tests/run.php`: **459 passed,0 failed** (416 previous +43 new).
- `node tests/ItemHudTest.js`: **21 passed,0 failed**.
- `node tests/CombatHudTest.js`: **32 passed,0 failed**.
- `node tests/ExplorationHudTest.js`: **30 passed,0 failed**.
- `find . -type f -name '*.php' -print0 | sort -z | xargs -0 -n1 php -l`:
  **130 files passed**.
- `find . -type f -name '*.js' -print0 | sort -z | xargs -0 -n1 node --check`:
  **7 files passed**.
- `git diff --check`: passed.

Runtime tests use the real repository/resolver/synchronizer/service with durable,
transaction-snapshotted fake PDO state, FK/uniqueness simulation and injected
failures, not source-string assertions. Only migration SQL checks are inspection
based. Tests cover every status's zero/chance boundary/independent contact/replay
rules; immutable aggregate snapshots/legacyNULL; four ticks/resistance/zero;
refresh replacement/reset/tied tick; fractional and large catch-up; terminal
stops/rewards; action/application/tick/late-persistence rollback; Freeze pending,
cap, fit, expiry and refresh; Shock resistance/Block/critical ordering, cap,
expiry, replacement and no self/DoT amplification. Existing Task24 Strength,
unarmed/native weapons,25 hit/crit/dodge,26 LoH,27 Block Rate,28 typed direct,
Attack/Cast timing, HP/Mana, permadeath, victory/loot, equipment/inventory locks
and request replay regressions remain green. Earlier fixtures now include the
new SQL-shaped fields; two Task28 tests using positive poison utility now expect
its required extra status draw, with their original damage/LoH assertions intact.

## Architecture limits and intentionally deferred work

One small `CombatStatusEffects` helper owns the status rules/schedule/projection;
the existing repository owns SQL and aggregate locks. The existing synchronizer
gets status candidates and the existing action resolver gets contact application.
No generic effect framework, new dependency or unrelated combat refactor.
Active sets are bounded to five rows; expired application identities intentionally
accumulate for durable replay safety. Repository source membership currently uses
the existing locked action-history read; future profiling can motivate a targeted
lookup, but no unrelated query/refactor is introduced here.

Manual browser testing is **intentionally deferred until the item/affix pass**;
no live encounter or database operation was used to validate this development.
True MariaDB migration/locking/concurrent-session validation also remains pending
separate authorization. Stop for ChatGPT GitHub review; no main promotion/deploy.

Explicitly deferred: status immunity, status resistance, tenacity, multiple
same-status stacks, cleanse, dispel, player-target/enemy-applied statuses,
status criticals, DoT Life on Hit, penetration, and CC diminishing returns.
Existing elemental damage resistance on poison/burn ticks is the only resistance
used here; no new status immunity/resistance mechanic is introduced.
