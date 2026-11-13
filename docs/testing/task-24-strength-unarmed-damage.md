# Task 24 — Strength and explicit unarmed damage

Base: `62e6b7bd6ef26d4d9ad7cec2922810fabd143be0`
Branch: `feature/combat-task-24-strength-unarmed-damage`

This task supersedes Task 23's balance and no-weapon behavior, not its shared
range authority. `docs/tasks/current-task.md` still describes historical Task 19;
the explicit Task 24 brief supplies this change's requirements.

## Authority, formula, and rounding

- `config/character_stats.php`: melee base **0**, Strength coefficient **1**.
  `CharacterStats::calculate()` therefore exposes `melee_damage = effective Strength`
  (including existing equipment main-stat modifiers and nonnegative clamping).
- `EffectiveWeaponDamage::calculate()` remains the sole shared attack-range
  calculation, invoked through `CharacterStats` for HUD and persistent combat.
- Physical equipped endpoint:
  `max(0, round((weapon_endpoint + effective Strength + flat_damage) * factor))`.
- Explicit unarmed endpoint:
  `max(0, round((effective Strength + flat_damage) * factor))`.
- Nonphysical equipped endpoint:
  `max(0, round((weapon_endpoint + flat_damage) * factor))` (no Strength).
- `factor = 1 + max(-10000, damage_percent_bp) / 10000`.
- Round each endpoint with PHP's default `round()` (nearest, half away from
  zero), then clamp to zero. Combat keeps the rounded midpoint of the already
  rounded endpoints; no random range roll or second modifier application.

At Strength 5: unarmed **5 Physical** / midpoint **5**; Basic Mace 5–7 becomes
**10–12 Physical** / midpoint **11**; sword 8–12 becomes **13–17 Physical**.
Equal endpoints display once, not as `5–5`. The HUD label stays **Attack Damage**;
empty equipment slots remain empty, not populated by an unarmed pseudo-item.

## Unarmed action and identity

The persistent provider interprets a null real equipped weapon as explicit
unarmed physical offense. It supplies null `snapshot_weapon_item_id` and null
`snapshot_weapon_key`, never a prototype/default/synthetic weapon. Invalid
present weapon data still fails closed. The existing normal attack intent
`prototype_weapon_attack` and `weapon` action kind are retained for compatibility
with the current action architecture; its prototype damage is not used by the
persistent provider. Timing, cooldown, Action allowance, idempotency, and pending
snapshot resolution all remain unchanged. Skills keep their separate existing
configured offense path.

The existing enemy-defense resolver now accepts nonnegative damage (still
rejecting negatives), so zero Strength or -100% damage resolves as a valid
zero-damage attack instead of failing or inventing a minimum base. Enemy Block
behavior, accuracy/critical deferral, and all other combat rules stay unchanged.
No database schema/data operations or migration are needed.

## RED / GREEN evidence

Before production edits, `php tests/run.php` reported **331 passed, 12 failed**.
Failures showed Strength 5 still producing melee 30, unarmed showing `No weapon`,
wrong equipped endpoints, and the persistent provider rejecting unarmed attacks.
After the formula/provider changes, **342 passed, 1 failed** isolated the existing
strictly-positive resolver rejecting zero-damage unarmed resolution. After the
narrow resolver fix and expanded regression coverage: **344 passed, 0 failed**.

Final verification:

- `php tests/run.php`: **344 passed, 0 failed**.
- `node tests/ItemHudTest.js`: **21 passed, 0 failed**.
- `node tests/CombatHudTest.js`: **31 passed, 0 failed**.
- `node tests/ExplorationHudTest.js`: **30 passed, 0 failed**.
- `find ascii-quest tests -name '*.php' -print0 | xargs -0 -n1 php -l`:
  all **117** PHP files passed.
- `find ascii-quest/js tests -name '*.js' -print0 | xargs -0 -n1 node --check`:
  all **7** JavaScript files passed.
- `git diff --check`: passed.

Coverage includes actual equip/unequip service round trips, mace/sword endpoints,
Strength allocation HUD refresh, equipped and unarmed modifiers and half rounding,
physical-only Strength, unarmed attack/cast rate, unchanged other stats, null
persisted weapon identities, normal action timing/allowance, idempotent replay,
immutable snapshots, zero/clamped damage resolution, and negative-damage rejection.

## Manual testing still required

- Strength 5: check **5 Physical** without a weapon, **10–12 Physical** with
  Basic Mace, and **5 Physical** immediately on unequip; empty slot stays empty.
- Allocate Strength armed/unarmed; verify immediate displayed endpoints and
  persistent stats page values after reload.
- Check flat/percent gear while unarmed; attack through the existing normal
  attack button, including cooldown/turn allowance and enemy Block behavior.
- Smoke-test login/selection, exploration/movement/transitions/stairs,
  traps/chests/map overrides, equipment persistence, and HP/Mana persistence.

No main promotion, deployment, production DB access, migration, bootstrap,
rebase, squash, or force push is part of this task. Feature is for GitHub review.
