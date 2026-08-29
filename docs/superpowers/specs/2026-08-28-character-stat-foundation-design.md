# ASCII Quest II — Character Stat Foundation Design

**Date:** 28 August 2026
**Status:** Approved design; awaiting written-spec review before implementation
**Milestone:** 1 — Character Stat Foundation

## 1. Purpose

Replace the legacy character HP/Attack/Defense/per-level stat model with the five-main-stat Champion model defined in the ASCII Quest Game Design Document, while preserving the existing exploration, map, movement, chest, trap, door, and transition systems.

This milestone establishes one authoritative server-side calculation path that later systems—equipment, affixes, passive skills, active effects, and combat—can extend without duplicating stat formulas across PHP pages or JavaScript.

## 2. Scope

### Included

- Replace the legacy class stat model with class starting bonuses for Strength, Dexterity, Vitality, Energy, and Fate.
- Store the Champion's permanent main-stat choices in MariaDB.
- Store unspent stat points in MariaDB.
- Store current HP and current Mana as persistent live state.
- Calculate Maximum Life, Maximum Mana, combat stats, resistances, Rates, utility stats, and status-effect chance server-side.
- Add a version-controlled stat configuration file.
- Add one central `CharacterStats` calculator.
- Adapt character creation, character selection, exploration loading, and trap handling to use calculated stats.
- Add migration tracking through `schema_migrations`.
- Add a destructive development migration that resets existing Champions and their per-character map overrides.
- Add native-PHP automated tests for the calculator.
- Preserve current exploration behaviour.

### Explicitly excluded

- Stat-allocation UI and level-up allocation workflow. This is Milestone 2.
- New exploration HUD. This is Milestone 3.
- Equipment, inventory, affixes, active skills, passive skills, and temporary combat modifiers.
- Actual attack/spell damage resolution.
- Enemy resistance application.
- Poison, Bleed, Freeze, and Shock combat resolution or rounding rules.
- Battle UI and battle engine.
- Framework, ORM, Composer dependency tree, or service-container introduction.

## 3. Source design rules

This design implements the approved rules from the ASCII Quest Game Design Document:

- Five main stats: Strength, Dexterity, Vitality, Energy, Fate.
- Universal starting main stats: 5 in each main stat.
- Class-specific starting bonuses.
- Five stat points gained for each level after Level 1.
- Base substats plus main-stat conversion.
- Action starts at 1.
- Attack Rate, Cast Rate, and Block Rate start at 1.00.
- Percentage caps defined by the design document remain 90% where specified.
- Status-effect application chance uses the highest of Poison, Bleed, Burn, Freeze, or Shock Damage divided by 100, capped at 90%.
- Current Life/Mana are persistent resources; Maximum Life/Mana are derived values.

Open combat formulas identified by the Game Design Document remain unimplemented rather than being invented during this milestone.

## 4. Approved interpretation decisions

The following decisions were made explicitly during design review.

### 4.1 Base values are pre-conversion values

Section 2.5 values such as Life 100, Mana 100, and Melee Damage 5 are treated as universal base values before main-stat conversion.

Example Warrior:

- Vitality 10 produces `100 + (10 × 10) = 200` Maximum Life.
- Strength 10 produces `5 + (10 × 5) = 55` Melee Damage.

### 4.2 Level 1 starts with zero unspent stat points

A newly created Level 1 Champion starts with:

```text
stat_points = 0
```

Each level gained after Level 1 awards:

```text
+5 stat_points
```

No automatic Strength, HP, Attack, Defense, or similar growth occurs per level.

### 4.3 Derived stats are not duplicated in the database

The database stores permanent Champion choices and live state. Derived values are calculated from game rules.

Persisted main-stat state:

- strength
- dexterity
- vitality
- energy
- fate
- stat_points

Derived values such as Maximum Life, Maximum Mana, Melee Damage, Spell Power, Toughness, resistances, and rates are not stored in `characters`.

### 4.4 Current HP/Mana remain unchanged when their maximum increases

Example:

```text
Before allocation: 187 / 200 HP
+1 Vitality
After allocation:  187 / 210 HP
```

Increasing capacity does not heal or restore Mana.

The behaviour for later Maximum-Life reductions is intentionally outside this milestone because no respec, equipment removal, or temporary Maximum-Life modifier is being implemented here.

### 4.5 Universal game-balance rules live in PHP configuration

Stat base values, conversion values, and caps are version-controlled PHP configuration, not database configuration.

MariaDB stores player state, class definitions, world state, and migration state—not universal stat formulas.

## 5. Architecture

### 5.1 File layout

Milestone 1 introduces the following structure:

```text
ASCII_Quest_II/
├── ascii-quest/
│   ├── config/
│   │   └── character_stats.php
│   ├── lib/
│   │   └── CharacterStats.php
│   ├── css/
│   │   └── style.css
│   ├── js/
│   │   └── game_controls.js
│   ├── create_character.php
│   ├── character_select.php
│   ├── game.php
│   ├── move_character.php
│   └── ...
├── database/
│   └── migrations/
│       └── 001_character_stat_system.sql
├── tests/
│   ├── CharacterStatsTest.php
│   └── run.php
└── docs/
    └── superpowers/
        └── specs/
            └── 2026-08-28-character-stat-foundation-design.md
```

### 5.2 Dependency direction

```text
character_stats.php
        ↓
defines universal rules
        ↓
CharacterStats.php
        ↓
calculates final Champion stats
        ↓
PHP pages/endpoints
        ↓
render or return calculated values
        ↓
JavaScript/browser
```

The browser is never authoritative for Champion stat calculations.

### 5.3 Single stat authority

No page or endpoint may reproduce formulas such as:

```php
$maxLife = 100 + ($character['vitality'] * 10);
```

Callers must use the calculator:

```php
$stats = CharacterStats::calculate($character);
$maxLife = $stats['resources']['max_life'];
```

This rule is required so future equipment, affixes, passive effects, and temporary effects can extend one calculation pipeline rather than creating competing implementations.

### 5.4 Deliberately small architecture

Milestone 1 does not add speculative layers such as repositories, factories, provider interfaces, service containers, or an ORM. New abstractions should be introduced only when a real subsystem requires them.

## 6. Stat configuration

`ascii-quest/config/character_stats.php` contains universal game values only. It contains no SQL, session handling, HTML, browser logic, or combat execution.

### 6.1 Universal base values

| Stat | Base value |
|---|---:|
| Life | 100 |
| Mana | 100 |
| Melee Damage | 5 |
| Toughness | 2 |
| Dodging | 2% |
| Accuracy | 5% |
| Critical Damage | 5 |
| Spell Power | 5 |
| Critical Chance | 5% |
| Fire Resistance | 0% |
| Lightning Resistance | 0% |
| Poison Resistance | 0% |
| Cold Resistance | 0% |
| Loot Chance | 1% |
| Gold Find | 1% |
| Action | 1 |
| Attack Rate | 1.00 |
| Cast Rate | 1.00 |
| Block Rate | 1.00 |

These utility values start at zero:

- Life Regeneration
- Mana Regeneration
- Life on Hit
- Mana on Hit
- Life per Kill
- Mana per Kill
- Fire Damage
- Lightning Damage
- Cold Damage
- Poison Damage
- Bleed Damage
- Burn Damage
- Freeze Damage
- Shock Damage

### 6.2 Main-stat conversion

#### Strength

- Melee Damage += Strength × 5
- Toughness += Strength × 2
- Fire Resistance += Strength × 1 percentage point

#### Dexterity

- Dodging += Dexterity × 2 percentage points
- Accuracy += Dexterity × 2 percentage points
- Critical Damage += Dexterity × 3
- Lightning Resistance += Dexterity × 1 percentage point

#### Vitality

- Life += Vitality × 10
- Poison Resistance += Vitality × 1 percentage point

#### Energy

- Mana += Energy × 15
- Spell Power += Energy × 5
- Cold Resistance += Energy × 1 percentage point

#### Fate

- Critical Chance += Fate × 2 percentage points
- Loot Chance += Fate × 1 percentage point
- Gold Find += Fate × 1 percentage point

## 7. Percentage representation and caps

### 7.1 Internal percentage convention

Percentage statistics are represented internally as percentage points:

```text
15% Critical Chance → 15.0
10% Fire Resistance → 10.0
```

Rates are decimal multipliers:

```text
Attack Rate → 1.00
```

The UI adds `%` only when formatting a percentage value for display.

### 7.2 90% capped values

The calculator applies a 90% maximum to:

- Fire Resistance
- Lightning Resistance
- Poison Resistance
- Cold Resistance
- Dodging
- Accuracy
- Critical Chance
- Loot Chance
- Chance to Apply Status Effect

### 7.3 Uncapped values in this milestone

No maximum is applied by this foundation to:

- Life
- Mana
- Melee Damage
- Toughness
- Critical Damage
- Spell Power
- Gold Find
- Life/Mana regeneration
- Life/Mana on Hit
- Life/Mana per Kill
- elemental damage values
- status-damage values

## 8. Status-effect chance

The base calculator exposes the design-document status chance without executing a status effect.

```text
highest(
    Poison Damage,
    Bleed Damage,
    Burn Damage,
    Freeze Damage,
    Shock Damage
) / 100
```

Maximum: 90%.

Example:

```text
Poison Damage = 50
Bleed Damage  = 300
Burn Damage   = 800
Freeze Damage = 0
Shock Damage  = 200

Highest = 800
800 / 100 = 8%
```

All five status-damage values start at zero, so a new Champion starts with 0% status-effect chance.

The calculator does not apply Burn, Poison, Bleed, Freeze, or Shock during Milestone 1.

## 9. CharacterStats calculator contract

The calculator accepts trusted server-side Champion data and returns grouped calculated values.

Conceptual call:

```php
$stats = CharacterStats::calculate($character);
```

Conceptual output:

```php
[
    'main' => [
        'strength' => 10,
        'dexterity' => 5,
        'vitality' => 10,
        'energy' => 5,
        'fate' => 5,
    ],
    'resources' => [
        'max_life' => 200,
        'max_mana' => 175,
    ],
    'combat' => [
        'melee_damage' => 55,
        'toughness' => 22,
        'dodging' => 12.0,
        'accuracy' => 15.0,
        'critical_damage' => 20,
        'critical_chance' => 15.0,
        'spell_power' => 30,
    ],
    'resistances' => [
        'fire' => 10.0,
        'lightning' => 5.0,
        'poison' => 10.0,
        'cold' => 5.0,
    ],
    'rates' => [
        'action' => 1,
        'attack_rate' => 1.00,
        'cast_rate' => 1.00,
        'block_rate' => 1.00,
    ],
    'utility' => [
        // zero-value foundation fields in this milestone
    ],
];
```

The implementation may refine array keys for clarity, but all callers must consume the calculator rather than repeat formulas.

## 10. Validation and error handling

### 10.1 Calculator input

Permanent main-stat values must be valid non-negative integers.

Examples of invalid data:

```text
strength = -4
vitality = NULL
fate = "abc"
```

Invalid permanent data causes an `InvalidArgumentException` or equivalent explicit failure. The calculator does not silently convert corrupt data to zero.

### 10.2 Browser trust boundary

The browser never submits starting main-stat values during character creation. It submits the class selection and Champion name only.

Server flow:

```text
browser class selection
    ↓
PHP validates class identifier
    ↓
PHP reloads class definition from MariaDB
    ↓
PHP constructs starting main stats
    ↓
CharacterStats validates and calculates
    ↓
PHP writes Champion
```

### 10.3 Player-facing errors

Detailed calculation/database errors are logged server-side. The browser receives a safe generic message such as:

```text
Unable to load Champion statistics.
```

SQL details and stack traces are not exposed to players.

## 11. Database design

### 11.1 `characters`

Retain existing identity, progression, world-position, and persistent resource columns needed by the current project.

The permanent stat model must contain:

```text
stat_points
strength
dexterity
vitality
energy
fate
current_hp
current_mana
```

Derived legacy columns are removed:

```text
max_hp
max_mana
attack
defense
```

Existing unrelated fields such as `user_id`, `class_id`, `character_name`, `level`, `experience`, `gold`, `current_map_id`, `pos_x`, `pos_y`, timestamps, keys, and indexes are preserved unless inspection of the live schema proves a specific adjustment is necessary.

### 11.2 `character_classes`

Keep class identity fields:

```text
id
class_name
glyph
ascii_fallback
description
```

Add:

```text
start_strength_bonus
start_dexterity_bonus
start_vitality_bonus
start_energy_bonus
start_fate_bonus
```

Remove the obsolete class combat and automatic per-level-growth columns, including legacy fields such as `base_hp`, `base_mana`, `base_attack`, `base_defense`, and old per-level stat-growth fields.

The migration must inspect the actual live schema before finalizing the full legacy-column drop list because the GitHub ZIP does not contain an authoritative database schema.

### 11.3 Starting class bonuses

Class rows are updated by class name rather than assumed numeric IDs.

| Class | STR bonus | DEX bonus | VIT bonus | ENE bonus | FATE bonus |
|---|---:|---:|---:|---:|---:|
| Warrior | +5 | +0 | +5 | +0 | +0 |
| Mage | +0 | +0 | +0 | +5 | +5 |
| Rogue | +0 | +5 | +5 | +0 | +0 |
| Cleric | +0 | +0 | +5 | +3 | +2 |

Universal main-stat base = 5 in every category.

Therefore final creation values are:

| Class | STR | DEX | VIT | ENE | FATE |
|---|---:|---:|---:|---:|---:|
| Warrior | 10 | 5 | 10 | 5 | 5 |
| Mage | 5 | 5 | 5 | 10 | 10 |
| Rogue | 5 | 10 | 10 | 5 | 5 |
| Cleric | 5 | 5 | 10 | 8 | 7 |

### 11.4 `schema_migrations`

Migration 001 introduces a small migration-history table if one does not already exist.

Successful installation records:

```text
001_character_stat_system
```

The migration must not be considered installed until post-migration verification passes.

## 12. Destructive development migration

The current development Champions will be reset by design.

Migration sequence:

1. Run preflight checks against the live MariaDB schema.
2. Require a database backup before destructive execution.
3. Clear character-specific map state in `character_map_overrides` for the Champions being removed.
4. Delete/reset existing development Champion records.
5. Replace obsolete `character_classes` stat/growth columns with the five starting-bonus columns.
6. Populate the four approved class bonuses by class name.
7. Replace obsolete `characters` derived-stat columns with the five main stats and `stat_points` while retaining current HP/Mana.
8. Create/verify `schema_migrations`.
9. Record migration 001 only after required schema changes succeed.
10. Run verification queries.

The migration must not delete:

- users
- game maps
- tile types
- global world/map definitions

### 12.1 Migration rollback strategy

MariaDB DDL changes are not treated as safely reversible through a single transaction.

Rollback strategy:

```text
database backup + previous application revision
```

not an assumed SQL `ROLLBACK` after arbitrary `ALTER TABLE` statements.

## 13. Character creation flow

Old flow:

```text
class base HP/Mana/Attack/Defense
    ↓
copied directly to character
```

New flow:

```text
class selected
    ↓
class reloaded from MariaDB
    ↓
universal main stats 5/5/5/5/5
    +
class starting bonuses
    ↓
permanent Champion main stats
    ↓
CharacterStats::calculate()
    ↓
calculated Maximum Life + Maximum Mana
    ↓
INSERT Champion
current_hp = max_life
current_mana = max_mana
stat_points = 0
```

The Champion starts at full HP and Mana.

The Champion creation INSERT should use a normal database transaction so failure does not leave a partially created Champion.

## 14. Character creation preview

The current preview of legacy HP/Attack/Defense/per-level values will be replaced with truthful data from the new model.

Example Warrior preview:

```text
Ω Warrior

Strength       10
Dexterity       5
Vitality       10
Energy          5
Fate            5

Life          200
Mana          175
Melee Damage   55
Toughness      22
Spell Power    30
```

The preview also explains:

```text
Each level after Level 1 grants 5 stat points.
```

The full visual redesign of this page is not part of Milestone 1.

## 15. Character selection integration

`character_select.php` stops reading stored Maximum Life/Mana and instead loads the main stats plus current resources, calls `CharacterStats`, and displays calculated Maximum Life/Mana.

Initial truthful selection information may include:

- current / maximum Life
- current / maximum Mana
- Strength
- Dexterity
- Vitality
- Energy
- Fate
- XP
- Gold

No full page redesign is required in this milestone.

## 16. Exploration integration

`game.php` stops depending on stored `max_hp`, `max_mana`, `attack`, and `defense` values.

It loads the five permanent main stats and current resources, calls `CharacterStats`, and renders calculated values.

This is a compatibility adaptation, not the new exploration HUD implementation.

## 17. Movement and trap integration

The existing movement system continues returning current and maximum HP to JavaScript.

Old server source:

```text
max_hp read from characters table
```

New server source:

```text
max_life returned by CharacterStats
```

The response contract can therefore remain compatible with the current `game_controls.js`:

```json
{
  "current_hp": 190,
  "max_hp": 200
}
```

The response key may remain `max_hp` for compatibility even though the value is derived from the internal `max_life` statistic.

Trap damage persists by updating only `characters.current_hp`.

## 18. Starting-class acceptance values

These values are fixed test cases for Milestone 1.

### 18.1 Warrior

Main stats:

```text
STR 10
DEX 5
VIT 10
ENE 5
FATE 5
```

Expected key derived values:

```text
Life                 200
Mana                 175
Melee Damage          55
Toughness              22
Spell Power            30
Dodging               12%
Accuracy              15%
Critical Damage        20
Critical Chance       15%
Fire Resistance       10%
Lightning Resistance  5%
Poison Resistance     10%
Cold Resistance        5%
Loot Chance            6%
Gold Find              6%
```

### 18.2 Mage

Main stats:

```text
STR 5
DEX 5
VIT 5
ENE 10
FATE 10
```

Expected key derived values:

```text
Life            150
Mana            250
Melee Damage     30
Toughness        12
Spell Power      55
Critical Chance 25%
Loot Chance      11%
Gold Find        11%
```

### 18.3 Rogue

Main stats:

```text
STR 5
DEX 10
VIT 10
ENE 5
FATE 5
```

Expected key derived values:

```text
Life                  200
Mana                  175
Dodging               22%
Accuracy              25%
Critical Damage        35
Lightning Resistance  10%
```

### 18.4 Cleric

Main stats:

```text
STR 5
DEX 5
VIT 10
ENE 8
FATE 7
```

Expected key derived values:

```text
Life            200
Mana            220
Spell Power      45
Critical Chance 19%
Loot Chance       8%
Gold Find         8%
```

## 19. Automated testing

No PHPUnit or Composer dependency is added for Milestone 1.

A small native-PHP runner is introduced:

```bash
php tests/run.php
```

Required tests:

- Warrior starting calculation
- Mage starting calculation
- Rogue starting calculation
- Cleric starting calculation
- resistance 90% cap
- Dodging 90% cap
- Accuracy 90% cap
- Critical Chance 90% cap
- Loot Chance 90% cap
- Gold Find remains uncapped
- Action starts at 1
- Attack Rate starts at 1.00
- Cast Rate starts at 1.00
- Block Rate starts at 1.00
- utility fields start at zero
- status-effect chance starts at zero
- status-effect chance uses the highest status-damage value
- status-effect chance cannot exceed 90%
- negative main stat is rejected
- non-numeric/invalid permanent main stat is rejected

The runner returns a non-zero process exit code if any test fails.

## 20. Syntax verification

PHP syntax check:

```bash
find ascii-quest -name "*.php" -print0 | xargs -0 -n1 php -l
```

If `game_controls.js` changes:

```bash
node --check ascii-quest/js/game_controls.js
```

## 21. Database verification

After migration, verify `characters` contains:

```text
strength
dexterity
vitality
energy
fate
stat_points
current_hp
current_mana
```

and no longer contains the legacy derived columns targeted by this milestone:

```text
max_hp
max_mana
attack
defense
```

Verify `character_classes` contains the five starting-bonus columns and that obsolete base-combat/per-level-growth fields targeted by the migration are absent.

Verify `schema_migrations` contains:

```text
001_character_stat_system
```

## 22. Manual server acceptance test

### 22.1 Creation

Create one Champion of each class and verify:

| Class | Expected HP | Expected Mana |
|---|---:|---:|
| Warrior | 200 | 175 |
| Mage | 150 | 250 |
| Rogue | 200 | 175 |
| Cleric | 200 | 220 |

Verify all five main stats match the approved class values.

### 22.2 Character selection

Each Champion shows calculated Maximum Life/Mana and correct current resources.

### 22.3 Exploration

Enter the map with a Warrior and verify:

```text
HP   200 / 200
Mana 175 / 175
```

Movement continues to work.

### 22.4 Trap persistence

Trigger a 10-damage trap:

```text
200 / 200 → 190 / 200
```

Refresh/re-enter the game and verify:

```text
190 / 200
```

This proves current HP is persistent while Maximum Life is calculated.

### 22.5 Existing exploration regression checks

- Chest reward still works.
- Door/interactions still work.
- Map transition still works.
- Character position persists.
- Logout/login/select-character flow still works.

## 23. Deployment sequence

Milestone 1 is deployed as one coordinated application/database change.

Sequence:

1. Back up the development database.
2. Run migration preflight against the actual server schema.
3. Place the new application files on the server in the agreed deployment workflow.
4. Run migration `001_character_stat_system.sql`.
5. Run migration verification queries.
6. Run `php tests/run.php`.
7. Run PHP syntax checks.
8. Run JavaScript syntax check if applicable.
9. Create test Champions.
10. Perform the manual server acceptance test.

Do not intentionally leave the new PHP code running against the old database schema or the old PHP code running against the completed new schema.

## 24. Git discipline

Milestone 1 contains only the character-stat foundation and compatibility changes required to keep current exploration working.

Suggested implementation commit intent:

```text
feat: replace legacy character stats
```

Do not mix new HUD styling, inventory, equipment, or combat work into this milestone.

The supplied GitHub ZIP does not contain `.git` history. Therefore this local working copy can contain the spec and future changes, but commits must ultimately be made in a real clone of the repository or applied on the user's development environment with Git history available.

## 25. Milestone 1 acceptance gate

Milestone 1 is complete only when all of the following are true:

- New five-main-stat schema installed.
- Correct class starting bonuses installed.
- All four classes create correctly.
- Derived values match fixed tests.
- Current HP/Mana persist while maximums are derived.
- Character selection uses derived stats.
- Exploration loads successfully.
- Movement works.
- Trap damage persists after reload.
- Chests still work.
- Doors/map transitions still work.
- Automated tests pass.
- PHP syntax checks pass.
- JavaScript syntax check passes if changed.
- Migration 001 is recorded.

Only after this gate passes should development move to Milestone 2.

## 26. Planned follow-on milestones

### Milestone 2 — Stat Allocation

Implement spending the five stat points awarded per level, with all updates performed server-side and recalculated through `CharacterStats`.

### Milestone 3 — Exploration HUD

Implement the approved exploration HUD against real calculated Champion data:

- Left: Main / Detailed Information / Warp
- Centre: exploration map
- Right: Items / Active Skill Tree / Passive Skill Tree
- Bottom: Server Info / Chat / Information

The exploration HUD remains free of combat-specific messages; those belong to the later dedicated battle scene.

## 27. Final architecture summary

```text
MariaDB
stores permanent Champion state
        │
        ▼
CharacterStats
uses version-controlled game rules
        │
        ▼
Derived Champion state
        │
        ├── Character Creation
        ├── Character Selection
        ├── Exploration
        ├── Movement / Traps
        └── later Combat
```

Future equipment, affixes, passives, and temporary effects must extend this same calculation authority rather than creating separate formula implementations.
