# Task 23 — Effective weapon damage

Base: `acc1cfae5ee19652d1947ec6048120aad2229001`
Branch: `feature/item-task-23-effective-weapon-damage`

## Authority and behavior

`EffectiveWeaponDamage::calculate()` is the single range formula, called by
`CharacterStats::calculate()` with the equipped weapon snapshot. Both the
EquipmentBootstrap UI/stat-allocation boundary and the persistent combat provider
supply the actual equipped weapon and aggregated modifiers.

For each physical endpoint:

`max(0, round((weapon endpoint + CharacterStats melee_damage + flat_damage) * (1 + max(-10000, damage_percent_bp) / 10000)))`

Round each endpoint to the nearest integer using PHP's existing `round()` default.
Preserve both endpoints and damage type in `combat.attack_damage`; the shared
server formatter supplies `combat.attack_damage_display`. The HUD and character
stats page label this value **Attack Damage**. Existing equipment mutation and
stat allocation responses update the HUD without client-side damage arithmetic.

Combat keeps deterministic damage resolution: the rounded midpoint of these
same effective endpoints becomes `snapshot_base_damage`. No random-range mechanic
or database schema change is introduced.

Nonphysical weapon types retain their existing flat/percent handling without
adding the physical melee contribution. Elemental/status utility values, accuracy,
critical behavior, and attack/cast rate rules are unchanged.

No weapon: null effective range, **No weapon** displayed, and weapon attacks
continue to fail explicitly. There is no prototype or default weapon fallback.

Illustrative 5–7 mace with +3 contribution: **8–10 Physical**. The real configured
Level 1 Strength 5 contribution is +30, so that Champion displays **35–37 Physical**;
this task does not alter stat balancing.

## RED / GREEN

Before implementation, the PHP suite reported **333 passed, 4 failed**:
three new tests failed because `EffectiveWeaponDamage` did not exist; the fourth
failed because equipment-aware stats did not expose the effective range.

After implementation and an additional Strength regression test:

- PHP suite (`php tests/run.php`): **338 passed, 0 failed**.
- Item HUD: **21 passed, 0 failed**.
- Combat HUD: **31 passed, 0 failed**.
- Exploration HUD: **30 passed, 0 failed**.
- PHP lint: all 116 PHP files under `ascii-quest/` and `tests/` passed.
- JS syntax: all JS files under `ascii-quest/js/` and `tests/` passed.
- `git diff --check`: passed.

New coverage includes the illustrative mace, actual configured Strength and
Strength affixes, flat and percentage modifiers separately and together,
min/max preservation, invalid range rejection, percent clamping, nonphysical
weapons, actual EquipmentService equip/unequip mutations, matching combat
snapshots, and HUD refresh after equip/unequip. Existing regression suites cover
critical/accuracy/attack rate and exploration/combat behavior.

No database operations, migrations, deployment, merge, or push performed.

## Manual verification still required

- Equip Basic Mace and verify Attack Damage matches current melee contribution.
- Unequip: verify **No weapon** immediately and weapon action rejection.
- Allocate Strength while equipped; verify both displayed endpoints increase.
- Check flat/percent equipment, reload persistence, and combat damage with the
  existing accuracy/critical/defense resolution.
- Smoke-test login, selection, movement, transitions/stairs, traps/chests, map
  overrides, and HP/Mana persistence. Automated checks do not replace browser QA.
