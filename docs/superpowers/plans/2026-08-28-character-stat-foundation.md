# Character Stat Foundation Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Replace ASCII Quest II's legacy HP/Attack/Defense class-growth model with the approved five-main-stat Champion foundation while preserving the existing exploration, map, movement, trap, chest, door, and transition behavior.

**Architecture:** Universal balance rules live in `ascii-quest/config/character_stats.php`; `ascii-quest/lib/CharacterStats.php` is the only authority that combines those rules with stored Champion main stats. MariaDB stores permanent main stats, unspent stat points, and current HP/Mana, while PHP pages/endpoints consume calculated Maximum Life/Mana and other derived values. The migration is a coordinated development reset and is not deployed until the live schema has been inspected and backed up.

**Tech Stack:** PHP 8.4, MariaDB, PDO, vanilla JavaScript, HTML/CSS, native PHP test runner (no Composer/PHPUnit).

**Spec:** `docs/superpowers/specs/2026-08-28-character-stat-foundation-design.md`

## Global Constraints

- Keep the current PHP + MariaDB + vanilla JavaScript architecture; do not add a framework, ORM, Composer dependency tree, or service container.
- Universal starting main stats are Strength 5, Dexterity 5, Vitality 5, Energy 5, Fate 5 before class bonuses.
- Level 1 starts with `stat_points = 0`; each later level will eventually award 5 points, but the allocation workflow is outside Milestone 1.
- Base substats are pre-conversion values: Life 100, Mana 100, Melee Damage 5, Toughness 2, Dodging 2%, Accuracy 5%, Critical Damage 5, Spell Power 5, Critical Chance 5%, Loot Chance 1%, Gold Find 1%.
- Main-stat conversions and 90% caps must match the approved spec exactly.
- Percentages are represented internally as percentage points (`15.0` means 15%); Rates are decimal multipliers (`1.00`).
- `characters` stores `current_hp` and `current_mana`, but not derived Maximum Life/Mana, Attack, or Defense.
- Increasing Maximum Life/Mana does not restore current HP/Mana.
- Browser input never controls starting main stats or derived calculations.
- `game.php`, `character_select.php`, `create_character.php`, and `move_character.php` must consume `CharacterStats`; they must not reimplement formulas.
- Preserve the existing `game_controls.js` movement response contract: `character_updates.current_hp` and `character_updates.max_hp`.
- Existing development Champions and their `character_map_overrides` are intentionally reset by migration 001; users, maps, tile types, and global world data are preserved.
- Do not begin Milestone 2 stat allocation or Milestone 3 HUD work in this plan.

---

## File Map

### Create

- `ascii-quest/config/character_stats.php` — version-controlled universal stat rules and caps.
- `ascii-quest/lib/CharacterStats.php` — starting-main-stat construction, input validation, derived calculation, and status-effect chance calculation.
- `tests/CharacterStatsTest.php` — fixed calculator regression cases.
- `tests/run.php` — minimal native PHP test harness with non-zero failure exit code.
- `database/migrations/001_character_stat_system.sql` — destructive development schema/data migration after live-schema inspection.
- `database/migrations/001_character_stat_system_verify.sql` — read-only post-migration verification queries.

### Modify

- `ascii-quest/create_character.php:53-89` — load new class bonus fields and build calculated preview data.
- `ascii-quest/create_character.php:117-244` — reconstruct trusted starting main stats, calculate full starting resources, and insert the new Champion schema inside a transaction.
- `ascii-quest/create_character.php:320-560` — replace legacy HP/Attack/Defense/growth preview with approved main/derived preview.
- `ascii-quest/character_select.php:35-68` — load five main stats/current resources instead of stored maximums.
- `ascii-quest/character_select.php:107-150` — display calculator-derived resource maximums and main stats.
- `ascii-quest/game.php:55-92` — load five main stats/current resources and calculate Champion stats.
- `ascii-quest/game.php:275-305` — render derived resource maximums through existing temporary exploration UI.
- `ascii-quest/move_character.php:29-31` — load `CharacterStats`.
- `ascii-quest/move_character.php:123-169` — load five main stats and derive Maximum Life instead of reading `c.max_hp`.

### Deliberately unchanged

- `ascii-quest/js/game_controls.js` — keep its existing `current_hp`/`max_hp` response handling unless verification reveals an incompatibility.
- `ascii-quest/map_loader.php`, `interact.php`, map JSON files, and current map rendering logic — regression-test only.

---

### Task 1: Lock the stat calculator contract with failing tests

**Files:**
- Create: `tests/CharacterStatsTest.php`
- Create: `tests/run.php`
- Create: `ascii-quest/config/character_stats.php`
- Create: `ascii-quest/lib/CharacterStats.php`

**Interfaces:**
- Produces: `CharacterStats::startingMainStats(array $class): array{strength:int,dexterity:int,vitality:int,energy:int,fate:int}`.
- Produces: `CharacterStats::calculate(array $character): array` with exact groups `main`, `resources`, `combat`, `resistances`, `rates`, `fortune`, and `utility`.
- Produces: `CharacterStats::statusEffectChance(array $statusDamage): float`, consuming keys `poison`, `bleed`, `burn`, `freeze`, `shock`.
- Later tasks consume these exact public methods and array keys.

- [ ] **Step 1: Create the native test harness**

Create `tests/run.php`:

```php
<?php
declare(strict_types=1);

$tests = require __DIR__ . '/CharacterStatsTest.php';

$passed = 0;
$failed = 0;

echo "ASCII Quest Tests\n\n";

foreach ($tests as $name => $test) {
    try {
        $test();
        echo "[PASS] {$name}\n";
        $passed++;
    } catch (Throwable $e) {
        echo "[FAIL] {$name}: {$e->getMessage()}\n";
        $failed++;
    }
}

echo "\n{$passed} passed\n{$failed} failed\n";
exit($failed === 0 ? 0 : 1);
```

- [ ] **Step 2: Write assertion helpers and the first four class tests**

Create `tests/CharacterStatsTest.php` with the calculator include, strict assertion helpers, and these four cases:

```php
<?php
declare(strict_types=1);

require_once __DIR__ . '/../ascii-quest/lib/CharacterStats.php';

function assertSameValue(mixed $expected, mixed $actual, string $message): void
{
    if ($expected !== $actual) {
        throw new RuntimeException(
            $message . ' Expected ' . var_export($expected, true) .
            ', got ' . var_export($actual, true)
        );
    }
}

function assertFloatValue(float $expected, float $actual, string $message): void
{
    if (abs($expected - $actual) > 0.00001) {
        throw new RuntimeException("{$message} Expected {$expected}, got {$actual}");
    }
}

function calculateCharacter(array $main): array
{
    return CharacterStats::calculate($main);
}

return [
    'Warrior starting stats' => function (): void {
        $stats = calculateCharacter([
            'strength' => 10,
            'dexterity' => 5,
            'vitality' => 10,
            'energy' => 5,
            'fate' => 5,
        ]);

        assertSameValue(200, $stats['resources']['max_life'], 'Warrior Life.');
        assertSameValue(175, $stats['resources']['max_mana'], 'Warrior Mana.');
        assertSameValue(55, $stats['combat']['melee_damage'], 'Warrior Melee Damage.');
        assertSameValue(22, $stats['combat']['toughness'], 'Warrior Toughness.');
        assertSameValue(30, $stats['combat']['spell_power'], 'Warrior Spell Power.');
        assertFloatValue(12.0, $stats['combat']['dodging'], 'Warrior Dodging.');
        assertFloatValue(15.0, $stats['combat']['accuracy'], 'Warrior Accuracy.');
        assertSameValue(20, $stats['combat']['critical_damage'], 'Warrior Critical Damage.');
        assertFloatValue(15.0, $stats['combat']['critical_chance'], 'Warrior Critical Chance.');
        assertFloatValue(10.0, $stats['resistances']['fire'], 'Warrior Fire Resistance.');
        assertFloatValue(5.0, $stats['resistances']['lightning'], 'Warrior Lightning Resistance.');
        assertFloatValue(10.0, $stats['resistances']['poison'], 'Warrior Poison Resistance.');
        assertFloatValue(5.0, $stats['resistances']['cold'], 'Warrior Cold Resistance.');
        assertFloatValue(6.0, $stats['fortune']['loot_chance'], 'Warrior Loot Chance.');
        assertFloatValue(6.0, $stats['fortune']['gold_find'], 'Warrior Gold Find.');
    },

    'Mage starting stats' => function (): void {
        $stats = calculateCharacter([
            'strength' => 5,
            'dexterity' => 5,
            'vitality' => 5,
            'energy' => 10,
            'fate' => 10,
        ]);

        assertSameValue(150, $stats['resources']['max_life'], 'Mage Life.');
        assertSameValue(250, $stats['resources']['max_mana'], 'Mage Mana.');
        assertSameValue(30, $stats['combat']['melee_damage'], 'Mage Melee Damage.');
        assertSameValue(12, $stats['combat']['toughness'], 'Mage Toughness.');
        assertSameValue(55, $stats['combat']['spell_power'], 'Mage Spell Power.');
        assertFloatValue(25.0, $stats['combat']['critical_chance'], 'Mage Critical Chance.');
        assertFloatValue(11.0, $stats['fortune']['loot_chance'], 'Mage Loot Chance.');
        assertFloatValue(11.0, $stats['fortune']['gold_find'], 'Mage Gold Find.');
    },

    'Rogue starting stats' => function (): void {
        $stats = calculateCharacter([
            'strength' => 5,
            'dexterity' => 10,
            'vitality' => 10,
            'energy' => 5,
            'fate' => 5,
        ]);

        assertSameValue(200, $stats['resources']['max_life'], 'Rogue Life.');
        assertSameValue(175, $stats['resources']['max_mana'], 'Rogue Mana.');
        assertFloatValue(22.0, $stats['combat']['dodging'], 'Rogue Dodging.');
        assertFloatValue(25.0, $stats['combat']['accuracy'], 'Rogue Accuracy.');
        assertSameValue(35, $stats['combat']['critical_damage'], 'Rogue Critical Damage.');
        assertFloatValue(10.0, $stats['resistances']['lightning'], 'Rogue Lightning Resistance.');
    },

    'Cleric starting stats' => function (): void {
        $stats = calculateCharacter([
            'strength' => 5,
            'dexterity' => 5,
            'vitality' => 10,
            'energy' => 8,
            'fate' => 7,
        ]);

        assertSameValue(200, $stats['resources']['max_life'], 'Cleric Life.');
        assertSameValue(220, $stats['resources']['max_mana'], 'Cleric Mana.');
        assertSameValue(45, $stats['combat']['spell_power'], 'Cleric Spell Power.');
        assertFloatValue(19.0, $stats['combat']['critical_chance'], 'Cleric Critical Chance.');
        assertFloatValue(8.0, $stats['fortune']['loot_chance'], 'Cleric Loot Chance.');
        assertFloatValue(8.0, $stats['fortune']['gold_find'], 'Cleric Gold Find.');
    },
];
```

- [ ] **Step 3: Run the tests and verify the calculator is missing**

Run from repository root:

```bash
php tests/run.php
```

Expected: failure because `ascii-quest/lib/CharacterStats.php` does not yet exist.

- [ ] **Step 4: Add the remaining required regression tests before implementation**

Extend the returned test array in `tests/CharacterStatsTest.php` with exact cases for:

```php
'90 percent caps' => function (): void {
    $stats = calculateCharacter([
        'strength' => 200,
        'dexterity' => 200,
        'vitality' => 200,
        'energy' => 200,
        'fate' => 200,
    ]);

    assertFloatValue(90.0, $stats['resistances']['fire'], 'Fire cap.');
    assertFloatValue(90.0, $stats['resistances']['lightning'], 'Lightning cap.');
    assertFloatValue(90.0, $stats['resistances']['poison'], 'Poison cap.');
    assertFloatValue(90.0, $stats['resistances']['cold'], 'Cold cap.');
    assertFloatValue(90.0, $stats['combat']['dodging'], 'Dodging cap.');
    assertFloatValue(90.0, $stats['combat']['accuracy'], 'Accuracy cap.');
    assertFloatValue(90.0, $stats['combat']['critical_chance'], 'Critical Chance cap.');
    assertFloatValue(90.0, $stats['fortune']['loot_chance'], 'Loot Chance cap.');
},

'Gold Find remains uncapped' => function (): void {
    $stats = calculateCharacter([
        'strength' => 5,
        'dexterity' => 5,
        'vitality' => 5,
        'energy' => 5,
        'fate' => 200,
    ]);

    assertFloatValue(201.0, $stats['fortune']['gold_find'], 'Gold Find.');
},

'Action and rates start at base values' => function (): void {
    $stats = calculateCharacter([
        'strength' => 5,
        'dexterity' => 5,
        'vitality' => 5,
        'energy' => 5,
        'fate' => 5,
    ]);

    assertSameValue(1, $stats['rates']['action'], 'Action.');
    assertFloatValue(1.00, $stats['rates']['attack_rate'], 'Attack Rate.');
    assertFloatValue(1.00, $stats['rates']['cast_rate'], 'Cast Rate.');
    assertFloatValue(1.00, $stats['rates']['block_rate'], 'Block Rate.');
},

'Utility values start at zero' => function (): void {
    $stats = calculateCharacter([
        'strength' => 5,
        'dexterity' => 5,
        'vitality' => 5,
        'energy' => 5,
        'fate' => 5,
    ]);

    foreach ([
        'life_regeneration', 'mana_regeneration', 'life_on_hit', 'mana_on_hit',
        'life_per_kill', 'mana_per_kill', 'fire_damage', 'lightning_damage',
        'cold_damage', 'poison_damage', 'bleed_damage', 'burn_damage',
        'freeze_damage', 'shock_damage'
    ] as $key) {
        assertSameValue(0, $stats['utility'][$key], "Utility {$key}.");
    }

    assertFloatValue(0.0, $stats['utility']['status_effect_chance'], 'Initial status chance.');
},

'Status effect chance uses highest status damage' => function (): void {
    $chance = CharacterStats::statusEffectChance([
        'poison' => 50,
        'bleed' => 300,
        'burn' => 800,
        'freeze' => 0,
        'shock' => 200,
    ]);

    assertFloatValue(8.0, $chance, 'Status chance from highest damage.');
},

'Status effect chance is capped at 90 percent' => function (): void {
    $chance = CharacterStats::statusEffectChance([
        'poison' => 0,
        'bleed' => 0,
        'burn' => 12000,
        'freeze' => 0,
        'shock' => 0,
    ]);

    assertFloatValue(90.0, $chance, 'Status chance cap.');
},

'Negative main stat is rejected' => function (): void {
    try {
        calculateCharacter([
            'strength' => -1,
            'dexterity' => 5,
            'vitality' => 5,
            'energy' => 5,
            'fate' => 5,
        ]);
    } catch (InvalidArgumentException) {
        return;
    }

    throw new RuntimeException('Negative Strength should throw InvalidArgumentException.');
},

'Invalid main stat is rejected' => function (): void {
    try {
        calculateCharacter([
            'strength' => 5,
            'dexterity' => 5,
            'vitality' => 5,
            'energy' => 5,
            'fate' => 'abc',
        ]);
    } catch (InvalidArgumentException) {
        return;
    }

    throw new RuntimeException('Non-numeric Fate should throw InvalidArgumentException.');
},

'Class bonuses produce Warrior main stats' => function (): void {
    $main = CharacterStats::startingMainStats([
        'start_strength_bonus' => 5,
        'start_dexterity_bonus' => 0,
        'start_vitality_bonus' => 5,
        'start_energy_bonus' => 0,
        'start_fate_bonus' => 0,
    ]);

    assertSameValue([
        'strength' => 10,
        'dexterity' => 5,
        'vitality' => 10,
        'energy' => 5,
        'fate' => 5,
    ], $main, 'Warrior main stats.');
},
```

- [ ] **Step 5: Create the version-controlled stat configuration**

Create `ascii-quest/config/character_stats.php`:

```php
<?php
declare(strict_types=1);

return [
    'main_stat_base' => 5,

    'base' => [
        'life' => 100,
        'mana' => 100,
        'melee_damage' => 5,
        'toughness' => 2,
        'dodging' => 2.0,
        'accuracy' => 5.0,
        'critical_damage' => 5,
        'spell_power' => 5,
        'critical_chance' => 5.0,
        'fire_resistance' => 0.0,
        'lightning_resistance' => 0.0,
        'poison_resistance' => 0.0,
        'cold_resistance' => 0.0,
        'loot_chance' => 1.0,
        'gold_find' => 1.0,
        'action' => 1,
        'attack_rate' => 1.00,
        'cast_rate' => 1.00,
        'block_rate' => 1.00,
    ],

    'per_point' => [
        'strength' => [
            'melee_damage' => 5,
            'toughness' => 2,
            'fire_resistance' => 1.0,
        ],
        'dexterity' => [
            'dodging' => 2.0,
            'accuracy' => 2.0,
            'critical_damage' => 3,
            'lightning_resistance' => 1.0,
        ],
        'vitality' => [
            'life' => 10,
            'poison_resistance' => 1.0,
        ],
        'energy' => [
            'mana' => 15,
            'spell_power' => 5,
            'cold_resistance' => 1.0,
        ],
        'fate' => [
            'critical_chance' => 2.0,
            'loot_chance' => 1.0,
            'gold_find' => 1.0,
        ],
    ],

    'caps' => [
        'fire_resistance' => 90.0,
        'lightning_resistance' => 90.0,
        'poison_resistance' => 90.0,
        'cold_resistance' => 90.0,
        'dodging' => 90.0,
        'accuracy' => 90.0,
        'critical_chance' => 90.0,
        'loot_chance' => 90.0,
        'status_effect_chance' => 90.0,
    ],

    'status_effect' => [
        'divisor' => 100.0,
    ],
];
```

- [ ] **Step 6: Implement the minimal `CharacterStats` class**

Create `ascii-quest/lib/CharacterStats.php` with these exact public methods and grouped output. The implementation must accept integer strings returned by PDO but reject negative, decimal, missing, null, and non-numeric main stats/bonuses.

```php
<?php
declare(strict_types=1);

final class CharacterStats
{
    private static ?array $config = null;

    public static function startingMainStats(array $class): array
    {
        $base = (int) self::config()['main_stat_base'];

        return [
            'strength' => $base + self::readNonNegativeInt($class, 'start_strength_bonus'),
            'dexterity' => $base + self::readNonNegativeInt($class, 'start_dexterity_bonus'),
            'vitality' => $base + self::readNonNegativeInt($class, 'start_vitality_bonus'),
            'energy' => $base + self::readNonNegativeInt($class, 'start_energy_bonus'),
            'fate' => $base + self::readNonNegativeInt($class, 'start_fate_bonus'),
        ];
    }

    public static function calculate(array $character): array
    {
        $config = self::config();
        $base = $config['base'];

        $strength = self::readNonNegativeInt($character, 'strength');
        $dexterity = self::readNonNegativeInt($character, 'dexterity');
        $vitality = self::readNonNegativeInt($character, 'vitality');
        $energy = self::readNonNegativeInt($character, 'energy');
        $fate = self::readNonNegativeInt($character, 'fate');

        $maxLife = $base['life'] + ($vitality * $config['per_point']['vitality']['life']);
        $maxMana = $base['mana'] + ($energy * $config['per_point']['energy']['mana']);

        $meleeDamage = $base['melee_damage'] + ($strength * $config['per_point']['strength']['melee_damage']);
        $toughness = $base['toughness'] + ($strength * $config['per_point']['strength']['toughness']);
        $dodging = self::cap('dodging', $base['dodging'] + ($dexterity * $config['per_point']['dexterity']['dodging']));
        $accuracy = self::cap('accuracy', $base['accuracy'] + ($dexterity * $config['per_point']['dexterity']['accuracy']));
        $criticalDamage = $base['critical_damage'] + ($dexterity * $config['per_point']['dexterity']['critical_damage']);
        $criticalChance = self::cap('critical_chance', $base['critical_chance'] + ($fate * $config['per_point']['fate']['critical_chance']));
        $spellPower = $base['spell_power'] + ($energy * $config['per_point']['energy']['spell_power']);

        $fireResistance = self::cap('fire_resistance', $base['fire_resistance'] + ($strength * $config['per_point']['strength']['fire_resistance']));
        $lightningResistance = self::cap('lightning_resistance', $base['lightning_resistance'] + ($dexterity * $config['per_point']['dexterity']['lightning_resistance']));
        $poisonResistance = self::cap('poison_resistance', $base['poison_resistance'] + ($vitality * $config['per_point']['vitality']['poison_resistance']));
        $coldResistance = self::cap('cold_resistance', $base['cold_resistance'] + ($energy * $config['per_point']['energy']['cold_resistance']));

        $lootChance = self::cap('loot_chance', $base['loot_chance'] + ($fate * $config['per_point']['fate']['loot_chance']));
        $goldFind = $base['gold_find'] + ($fate * $config['per_point']['fate']['gold_find']);

        $utility = [
            'life_regeneration' => 0,
            'mana_regeneration' => 0,
            'life_on_hit' => 0,
            'mana_on_hit' => 0,
            'life_per_kill' => 0,
            'mana_per_kill' => 0,
            'fire_damage' => 0,
            'lightning_damage' => 0,
            'cold_damage' => 0,
            'poison_damage' => 0,
            'bleed_damage' => 0,
            'burn_damage' => 0,
            'freeze_damage' => 0,
            'shock_damage' => 0,
        ];

        $utility['status_effect_chance'] = self::statusEffectChance([
            'poison' => $utility['poison_damage'],
            'bleed' => $utility['bleed_damage'],
            'burn' => $utility['burn_damage'],
            'freeze' => $utility['freeze_damage'],
            'shock' => $utility['shock_damage'],
        ]);

        return [
            'main' => [
                'strength' => $strength,
                'dexterity' => $dexterity,
                'vitality' => $vitality,
                'energy' => $energy,
                'fate' => $fate,
            ],
            'resources' => [
                'max_life' => $maxLife,
                'max_mana' => $maxMana,
            ],
            'combat' => [
                'melee_damage' => $meleeDamage,
                'toughness' => $toughness,
                'dodging' => $dodging,
                'accuracy' => $accuracy,
                'critical_damage' => $criticalDamage,
                'critical_chance' => $criticalChance,
                'spell_power' => $spellPower,
            ],
            'resistances' => [
                'fire' => $fireResistance,
                'lightning' => $lightningResistance,
                'poison' => $poisonResistance,
                'cold' => $coldResistance,
            ],
            'rates' => [
                'action' => (int) $base['action'],
                'attack_rate' => (float) $base['attack_rate'],
                'cast_rate' => (float) $base['cast_rate'],
                'block_rate' => (float) $base['block_rate'],
            ],
            'fortune' => [
                'loot_chance' => $lootChance,
                'gold_find' => $goldFind,
            ],
            'utility' => $utility,
        ];
    }

    public static function statusEffectChance(array $statusDamage): float
    {
        foreach (['poison', 'bleed', 'burn', 'freeze', 'shock'] as $key) {
            if (!array_key_exists($key, $statusDamage) || !is_numeric($statusDamage[$key])) {
                throw new InvalidArgumentException("Invalid status damage value: {$key}");
            }

            if ((float) $statusDamage[$key] < 0) {
                throw new InvalidArgumentException("Status damage cannot be negative: {$key}");
            }
        }

        $highest = max(array_map('floatval', [
            $statusDamage['poison'],
            $statusDamage['bleed'],
            $statusDamage['burn'],
            $statusDamage['freeze'],
            $statusDamage['shock'],
        ]));

        $chance = $highest / (float) self::config()['status_effect']['divisor'];
        return self::cap('status_effect_chance', $chance);
    }

    private static function config(): array
    {
        if (self::$config === null) {
            self::$config = require __DIR__ . '/../config/character_stats.php';
        }

        return self::$config;
    }

    private static function readNonNegativeInt(array $data, string $key): int
    {
        if (!array_key_exists($key, $data)) {
            throw new InvalidArgumentException("Missing integer value: {$key}");
        }

        $value = $data[$key];

        if (is_int($value)) {
            $integer = $value;
        } elseif (is_string($value) && ctype_digit($value)) {
            $integer = (int) $value;
        } else {
            throw new InvalidArgumentException("Invalid integer value: {$key}");
        }

        if ($integer < 0) {
            throw new InvalidArgumentException("Integer value cannot be negative: {$key}");
        }

        return $integer;
    }

    private static function cap(string $key, float $value): float
    {
        $cap = (float) self::config()['caps'][$key];
        return min($cap, $value);
    }
}
```

- [ ] **Step 7: Run calculator tests**

Run:

```bash
php tests/run.php
```

Expected: all tests pass and the process exits 0.

- [ ] **Step 8: Run syntax checks for the new PHP files**

Run:

```bash
php -l ascii-quest/config/character_stats.php
php -l ascii-quest/lib/CharacterStats.php
php -l tests/CharacterStatsTest.php
php -l tests/run.php
```

Expected: `No syntax errors detected` for all four files.

- [ ] **Step 9: Commit Task 1 in a real Git clone**

```bash
git add ascii-quest/config/character_stats.php ascii-quest/lib/CharacterStats.php tests/CharacterStatsTest.php tests/run.php
git commit -m "test: define character stat calculator"
```

---

### Task 2: Inspect the live MariaDB schema and finalize migration 001

**Files:**
- Create: `database/migrations/001_character_stat_system.sql`
- Create: `database/migrations/001_character_stat_system_verify.sql`

**Interfaces:**
- Produces database columns consumed by Tasks 3-5: `characters.strength`, `dexterity`, `vitality`, `energy`, `fate`, `stat_points`, `current_hp`, `current_mana`; `character_classes.start_*_bonus`.
- Preserves: character identity/progression/world columns and `game_maps`, `tile_types`, `users`.
- Removes: `characters.max_hp`, `max_mana`, `attack`, `defense`, plus the confirmed legacy class-stat/growth columns.

- [ ] **Step 1: Run the read-only live-schema inspection query before writing destructive SQL**

Execute this SQL against the development `ascii_quest` database and save the result with the task notes:

```sql
SELECT
    TABLE_NAME,
    ORDINAL_POSITION,
    COLUMN_NAME,
    COLUMN_TYPE,
    IS_NULLABLE,
    COLUMN_DEFAULT,
    COLUMN_KEY,
    EXTRA
FROM information_schema.COLUMNS
WHERE TABLE_SCHEMA = DATABASE()
  AND TABLE_NAME IN (
      'characters',
      'character_classes',
      'character_map_overrides',
      'schema_migrations'
  )
ORDER BY TABLE_NAME, ORDINAL_POSITION;
```

Also run:

```sql
SHOW CREATE TABLE characters;
SHOW CREATE TABLE character_classes;
SHOW CREATE TABLE character_map_overrides;
```

Expected: confirm actual foreign keys/indexes and the complete legacy column list before any `ALTER TABLE` is executed.

- [ ] **Step 2: Verify the legacy columns currently referenced by the application exist**

Run:

```sql
SELECT TABLE_NAME, COLUMN_NAME
FROM information_schema.COLUMNS
WHERE TABLE_SCHEMA = DATABASE()
  AND (
      (TABLE_NAME = 'characters' AND COLUMN_NAME IN (
          'max_hp', 'max_mana', 'attack', 'defense',
          'current_hp', 'current_mana'
      ))
      OR
      (TABLE_NAME = 'character_classes' AND COLUMN_NAME IN (
          'base_hp', 'base_mana', 'base_attack', 'base_defense',
          'base_crit_damage', 'base_crit_chance', 'base_attack_count',
          'base_dodge', 'base_heal_per_step', 'base_life_on_hit',
          'base_mana_per_min', 'base_mana_on_hit', 'base_bonus_xp_on_kill',
          'base_gold_find', 'hp_per_level', 'mana_per_level',
          'attack_per_level', 'defense_per_level', 'dodge_per_level'
      ))
  )
ORDER BY TABLE_NAME, COLUMN_NAME;
```

Expected: the result explains every legacy column currently used by `create_character.php`, `character_select.php`, `game.php`, and `move_character.php`. If the live `SHOW CREATE TABLE` reveals additional obsolete class combat/growth columns, add them to the same migration drop list before continuing; do not guess them from names alone.

- [ ] **Step 3: Back up the development database before the migration is allowed to run**

Use the server's existing MariaDB backup method and verify that the resulting SQL dump is non-empty before proceeding. The backup must include table definitions and data for the entire `ascii_quest` database, not only the modified tables.

Expected: a restorable pre-001 dump exists before Step 4.

- [ ] **Step 4: Create migration 001 with an explicit migration guard, data reset, new columns, class bonuses, and legacy-column removal**

Create `database/migrations/001_character_stat_system.sql`. The migration must use the actual schema inspection from Steps 1-2 to finalize the legacy class-column drop list. The core statements are:

```sql
CREATE TABLE IF NOT EXISTS schema_migrations (
    migration_id VARCHAR(100) NOT NULL PRIMARY KEY,
    applied_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

SET @already_applied = (
    SELECT COUNT(*)
    FROM schema_migrations
    WHERE migration_id = '001_character_stat_system'
);

DROP PROCEDURE IF EXISTS run_001_character_stat_system;
DELIMITER $$
CREATE PROCEDURE run_001_character_stat_system()
BEGIN
    IF @already_applied > 0 THEN
        SIGNAL SQLSTATE '45000'
            SET MESSAGE_TEXT = 'Migration 001_character_stat_system is already applied';
    END IF;

    DELETE cmo
    FROM character_map_overrides cmo
    INNER JOIN characters c ON c.id = cmo.character_id;

    DELETE FROM characters;

    ALTER TABLE character_classes
        ADD COLUMN start_strength_bonus INT UNSIGNED NOT NULL DEFAULT 0,
        ADD COLUMN start_dexterity_bonus INT UNSIGNED NOT NULL DEFAULT 0,
        ADD COLUMN start_vitality_bonus INT UNSIGNED NOT NULL DEFAULT 0,
        ADD COLUMN start_energy_bonus INT UNSIGNED NOT NULL DEFAULT 0,
        ADD COLUMN start_fate_bonus INT UNSIGNED NOT NULL DEFAULT 0;

    UPDATE character_classes
    SET
        start_strength_bonus = CASE class_name
            WHEN 'Warrior' THEN 5
            ELSE 0
        END,
        start_dexterity_bonus = CASE class_name
            WHEN 'Rogue' THEN 5
            ELSE 0
        END,
        start_vitality_bonus = CASE class_name
            WHEN 'Warrior' THEN 5
            WHEN 'Rogue' THEN 5
            WHEN 'Cleric' THEN 5
            ELSE 0
        END,
        start_energy_bonus = CASE class_name
            WHEN 'Mage' THEN 5
            WHEN 'Cleric' THEN 3
            ELSE 0
        END,
        start_fate_bonus = CASE class_name
            WHEN 'Mage' THEN 5
            WHEN 'Cleric' THEN 2
            ELSE 0
        END;

    ALTER TABLE characters
        ADD COLUMN stat_points INT UNSIGNED NOT NULL DEFAULT 0 AFTER experience,
        ADD COLUMN strength INT UNSIGNED NOT NULL DEFAULT 5 AFTER stat_points,
        ADD COLUMN dexterity INT UNSIGNED NOT NULL DEFAULT 5 AFTER strength,
        ADD COLUMN vitality INT UNSIGNED NOT NULL DEFAULT 5 AFTER dexterity,
        ADD COLUMN energy INT UNSIGNED NOT NULL DEFAULT 5 AFTER vitality,
        ADD COLUMN fate INT UNSIGNED NOT NULL DEFAULT 5 AFTER energy;

    ALTER TABLE characters
        DROP COLUMN max_hp,
        DROP COLUMN max_mana,
        DROP COLUMN attack,
        DROP COLUMN defense;

    ALTER TABLE character_classes
        DROP COLUMN base_hp,
        DROP COLUMN base_mana,
        DROP COLUMN base_attack,
        DROP COLUMN base_defense,
        DROP COLUMN base_crit_damage,
        DROP COLUMN base_crit_chance,
        DROP COLUMN base_attack_count,
        DROP COLUMN base_dodge,
        DROP COLUMN base_heal_per_step,
        DROP COLUMN base_life_on_hit,
        DROP COLUMN base_mana_per_min,
        DROP COLUMN base_mana_on_hit,
        DROP COLUMN base_bonus_xp_on_kill,
        DROP COLUMN base_gold_find,
        DROP COLUMN hp_per_level,
        DROP COLUMN mana_per_level,
        DROP COLUMN attack_per_level,
        DROP COLUMN defense_per_level,
        DROP COLUMN dodge_per_level;

    INSERT INTO schema_migrations (migration_id)
    VALUES ('001_character_stat_system');
END$$
DELIMITER ;

CALL run_001_character_stat_system();
DROP PROCEDURE run_001_character_stat_system;
```

Before execution, replace only the `ALTER TABLE character_classes ... DROP COLUMN ...` list if the live schema inspection proves that list differs. Do not change the approved new column names or class bonus values.

- [ ] **Step 5: Add read-only verification queries**

Create `database/migrations/001_character_stat_system_verify.sql`:

```sql
SELECT migration_id, applied_at
FROM schema_migrations
WHERE migration_id = '001_character_stat_system';

SELECT class_name,
       start_strength_bonus,
       start_dexterity_bonus,
       start_vitality_bonus,
       start_energy_bonus,
       start_fate_bonus
FROM character_classes
WHERE class_name IN ('Warrior', 'Mage', 'Rogue', 'Cleric')
ORDER BY class_name;

SELECT COLUMN_NAME, COLUMN_TYPE, IS_NULLABLE, COLUMN_DEFAULT
FROM information_schema.COLUMNS
WHERE TABLE_SCHEMA = DATABASE()
  AND TABLE_NAME = 'characters'
  AND COLUMN_NAME IN (
      'stat_points', 'strength', 'dexterity', 'vitality', 'energy', 'fate',
      'current_hp', 'current_mana', 'max_hp', 'max_mana', 'attack', 'defense'
  )
ORDER BY ORDINAL_POSITION;

SELECT COLUMN_NAME
FROM information_schema.COLUMNS
WHERE TABLE_SCHEMA = DATABASE()
  AND TABLE_NAME = 'character_classes'
  AND COLUMN_NAME IN (
      'start_strength_bonus', 'start_dexterity_bonus', 'start_vitality_bonus',
      'start_energy_bonus', 'start_fate_bonus',
      'base_hp', 'base_mana', 'base_attack', 'base_defense',
      'hp_per_level', 'mana_per_level', 'attack_per_level',
      'defense_per_level', 'dodge_per_level'
  )
ORDER BY ORDINAL_POSITION;

SELECT COUNT(*) AS remaining_characters FROM characters;
SELECT COUNT(*) AS remaining_character_overrides FROM character_map_overrides;
```

Expected after migration: one migration record; exact class bonuses; `characters` shows the eight required new/persistent resource columns and none of `max_hp/max_mana/attack/defense`; only the five `start_*_bonus` fields from the targeted class-stat list remain; both reset counts are zero.

- [ ] **Step 6: Review the migration against the live `SHOW CREATE TABLE` output before execution**

Verify all of the following manually from the saved schema output:

```text
- current_hp and current_mana already exist and are retained
- user_id/class_id/current_map_id foreign keys are untouched
- character_map_overrides references characters.id and is emptied before character reset
- no global map/tile/user table is modified
- no additional obsolete class combat/growth column remains outside the finalized drop list
```

- [ ] **Step 7: Commit the migration files in a real Git clone**

```bash
git add database/migrations/001_character_stat_system.sql database/migrations/001_character_stat_system_verify.sql
git commit -m "db: add character stat migration"
```

Do not execute the destructive migration on the server yet; application code must be ready first so deployment can be coordinated.

---

### Task 3: Convert character creation and preview to the five-stat model

**Files:**
- Modify: `ascii-quest/create_character.php:24-26`
- Modify: `ascii-quest/create_character.php:53-89`
- Modify: `ascii-quest/create_character.php:117-270`
- Modify: `ascii-quest/create_character.php:320-560`
- Test: `tests/CharacterStatsTest.php`

**Interfaces:**
- Consumes: `CharacterStats::startingMainStats()` and `CharacterStats::calculate()` from Task 1.
- Consumes after deployment: `character_classes.start_*_bonus` and `characters.stat_points/strength/dexterity/vitality/energy/fate` from Task 2.
- Produces: new Level 1 Champion rows with main stats persisted, `stat_points = 0`, and full current HP/Mana.

- [ ] **Step 1: Add a test for all four class-bonus rows before changing page logic**

Extend `tests/CharacterStatsTest.php` with:

```php
'All class starting bonuses' => function (): void {
    $cases = [
        'Warrior' => [[5, 0, 5, 0, 0], [10, 5, 10, 5, 5]],
        'Mage' => [[0, 0, 0, 5, 5], [5, 5, 5, 10, 10]],
        'Rogue' => [[0, 5, 5, 0, 0], [5, 10, 10, 5, 5]],
        'Cleric' => [[0, 0, 5, 3, 2], [5, 5, 10, 8, 7]],
    ];

    foreach ($cases as $className => [$bonuses, $expected]) {
        [$str, $dex, $vit, $ene, $fate] = $bonuses;

        $actual = CharacterStats::startingMainStats([
            'start_strength_bonus' => $str,
            'start_dexterity_bonus' => $dex,
            'start_vitality_bonus' => $vit,
            'start_energy_bonus' => $ene,
            'start_fate_bonus' => $fate,
        ]);

        assertSameValue([
            'strength' => $expected[0],
            'dexterity' => $expected[1],
            'vitality' => $expected[2],
            'energy' => $expected[3],
            'fate' => $expected[4],
        ], $actual, "{$className} main stats.");
    }
},
```

- [ ] **Step 2: Run tests to confirm the existing calculator still passes the new class cases**

Run:

```bash
php tests/run.php
```

Expected: all tests pass. If not, fix only the calculator contract before touching page code.

- [ ] **Step 3: Load `CharacterStats` and replace the class SELECT**

Add after `db.php`:

```php
require_once __DIR__ . '/lib/CharacterStats.php';
```

Replace the legacy class SELECT with:

```sql
SELECT
    id,
    class_name,
    glyph,
    ascii_fallback,
    description,
    start_strength_bonus,
    start_dexterity_bonus,
    start_vitality_bonus,
    start_energy_bonus,
    start_fate_bonus
FROM character_classes
ORDER BY id
```

Immediately after `$classes = $stmt->fetchAll();`, build server-authoritative preview data:

```php
$classPreviews = [];

foreach ($classes as $class) {
    $mainStats = CharacterStats::startingMainStats($class);
    $calculatedStats = CharacterStats::calculate($mainStats);

    $classPreviews[(int) $class['id']] = [
        'main' => $mainStats,
        'stats' => $calculatedStats,
    ];
}
```

- [ ] **Step 4: Replace the create flow with trusted main-stat construction and a transaction**

After the selected class and starting map are successfully loaded, calculate:

```php
$mainStats = CharacterStats::startingMainStats($selectedClass);
$calculatedStats = CharacterStats::calculate($mainStats);
$maxLife = $calculatedStats['resources']['max_life'];
$maxMana = $calculatedStats['resources']['max_mana'];
```

Then use a transaction and this INSERT shape:

```php
$pdo->beginTransaction();

try {
    $insertStmt = $pdo->prepare("
        INSERT INTO characters (
            user_id,
            class_id,
            character_name,
            level,
            experience,
            stat_points,
            strength,
            dexterity,
            vitality,
            energy,
            fate,
            current_hp,
            current_mana,
            gold,
            current_map_id,
            pos_x,
            pos_y
        )
        VALUES (
            :user_id,
            :class_id,
            :character_name,
            1,
            0,
            0,
            :strength,
            :dexterity,
            :vitality,
            :energy,
            :fate,
            :current_hp,
            :current_mana,
            0,
            :current_map_id,
            :pos_x,
            :pos_y
        )
    ");

    $insertStmt->execute([
        'user_id' => $_SESSION['user_id'],
        'class_id' => $selectedClass['id'],
        'character_name' => $characterName,
        'strength' => $mainStats['strength'],
        'dexterity' => $mainStats['dexterity'],
        'vitality' => $mainStats['vitality'],
        'energy' => $mainStats['energy'],
        'fate' => $mainStats['fate'],
        'current_hp' => $maxLife,
        'current_mana' => $maxMana,
        'current_map_id' => $startingMap['id'],
        'pos_x' => $startingMap['start_x'],
        'pos_y' => $startingMap['start_y'],
    ]);

    $pdo->commit();

    header('Location: character_select.php');
    exit();
} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }

    if ($e instanceof PDOException && $e->getCode() === '23000') {
        $message = 'You already have a character with this name.';
        $messageType = 'error';
    } else {
        error_log('Create character error: ' . $e->getMessage());
        $message = 'Something went wrong. Please try again.';
        $messageType = 'error';
    }
}
```

No browser field supplies `strength`, `dexterity`, `vitality`, `energy`, `fate`, `current_hp`, or `current_mana`.

- [ ] **Step 5: Replace legacy preview option data with calculated preview data**

For each class option/card, render data attributes from `$classPreviews[(int) $class['id']]`, including:

```php
<?php $preview = $classPreviews[(int) $class['id']]; ?>

data-strength="<?= e($preview['main']['strength']) ?>"
data-dexterity="<?= e($preview['main']['dexterity']) ?>"
data-vitality="<?= e($preview['main']['vitality']) ?>"
data-energy="<?= e($preview['main']['energy']) ?>"
data-fate="<?= e($preview['main']['fate']) ?>"
data-life="<?= e($preview['stats']['resources']['max_life']) ?>"
data-mana="<?= e($preview['stats']['resources']['max_mana']) ?>"
data-melee-damage="<?= e($preview['stats']['combat']['melee_damage']) ?>"
data-toughness="<?= e($preview['stats']['combat']['toughness']) ?>"
data-spell-power="<?= e($preview['stats']['combat']['spell_power']) ?>"
```

Remove all legacy `data-hp`, `data-attack`, `data-defense`, `data-crit-*`, `data-dodge`, and `data-*-level` attributes that derive from obsolete class columns.

- [ ] **Step 6: Replace the visible legacy stat/growth preview**

Keep the existing page layout but change the preview labels to exactly:

```text
Strength
Dexterity
Vitality
Energy
Fate
Life
Mana
Melee Damage
Toughness
Spell Power
```

Replace the legacy per-level growth section with one informational line:

```text
Each level after Level 1 grants 5 stat points.
```

Update the existing preview JavaScript so it only reads the ten new `data-*` attributes and writes them to the corresponding preview fields. Do not calculate any stat in JavaScript.

- [ ] **Step 7: Add safe calculator error handling around preview/creation**

Wrap calculator calls that depend on database class data in `try/catch (InvalidArgumentException $e)`. Log the detailed error:

```php
error_log('Character class stat error: ' . $e->getMessage());
```

and expose only:

```text
Unable to load Champion statistics.
```

Do not print the invalid database value or stack trace in HTML.

- [ ] **Step 8: Run tests and page syntax check**

```bash
php tests/run.php
php -l ascii-quest/create_character.php
```

Expected: all tests pass; no syntax errors.

- [ ] **Step 9: Commit Task 3 in a real Git clone**

```bash
git add ascii-quest/create_character.php tests/CharacterStatsTest.php
git commit -m "feat: create champions from main stats"
```

---

### Task 4: Convert character selection and exploration display to calculated resources

**Files:**
- Modify: `ascii-quest/character_select.php:1-150`
- Modify: `ascii-quest/game.php:1-305`

**Interfaces:**
- Consumes: `CharacterStats::calculate(array $character): array`.
- Produces: truthful current/maximum resource display without `characters.max_hp` or `characters.max_mana`.
- Preserves: character selection action, map loading, map viewport, and existing temporary exploration page structure.

- [ ] **Step 1: Add `CharacterStats` to `character_select.php` and change the character query**

Add:

```php
require_once __DIR__ . '/lib/CharacterStats.php';
```

Remove selected `c.max_hp` and `c.max_mana`. Select instead:

```sql
c.strength,
c.dexterity,
c.vitality,
c.energy,
c.fate,
c.current_hp,
c.current_mana
```

Keep the existing identity, class, XP, Gold, and other fields the page already needs.

- [ ] **Step 2: Calculate stats for each selection card on the server**

Before rendering a card:

```php
try {
    $stats = CharacterStats::calculate($character);
} catch (InvalidArgumentException $e) {
    error_log(
        'CharacterStats error for character ' .
        (int) $character['id'] . ': ' . $e->getMessage()
    );

    $stats = null;
}
```

If `$stats === null`, display only the safe message:

```text
Unable to load Champion statistics.
```

and do not render fabricated fallback numbers.

- [ ] **Step 3: Replace stored max resource output and add the five main stats**

Render:

```php
HP: <?= e($character['current_hp']) ?>/<?= e($stats['resources']['max_life']) ?>
Mana: <?= e($character['current_mana']) ?>/<?= e($stats['resources']['max_mana']) ?>
STR: <?= e($stats['main']['strength']) ?>
DEX: <?= e($stats['main']['dexterity']) ?>
VIT: <?= e($stats['main']['vitality']) ?>
ENE: <?= e($stats['main']['energy']) ?>
FATE: <?= e($stats['main']['fate']) ?>
```

Keep existing XP and Gold display. Do not redesign the whole page.

- [ ] **Step 4: Add `CharacterStats` to `game.php` and change its selected-character query**

Add:

```php
require_once __DIR__ . '/lib/CharacterStats.php';
```

Remove `c.max_hp` and `c.max_mana` from the SELECT. Add the five main-stat fields. Keep `current_hp`, `current_mana`, position, class identity, map fields, XP, Gold, and all fields used by current exploration rendering.

- [ ] **Step 5: Calculate the selected Champion once after loading the database row**

Immediately after the `if (!$character)` guard:

```php
try {
    $characterStats = CharacterStats::calculate($character);
} catch (InvalidArgumentException $e) {
    error_log(
        'CharacterStats error for character ' .
        (int) $character['id'] . ': ' . $e->getMessage()
    );

    exit('Unable to load Champion statistics.');
}
```

No later `game.php` block may recalculate main-stat formulas.

- [ ] **Step 6: Replace the temporary exploration HP/Mana maximum display**

Use:

```php
<?= e($character['current_hp']) ?>/<?= e($characterStats['resources']['max_life']) ?>
```

and:

```php
<?= e($character['current_mana']) ?>/<?= e($characterStats['resources']['max_mana']) ?>
```

Leave the rest of the current exploration UI alone; the approved HUD redesign is Milestone 3.

- [ ] **Step 7: Run syntax and calculator regression tests**

```bash
php tests/run.php
php -l ascii-quest/character_select.php
php -l ascii-quest/game.php
```

Expected: calculator tests pass and both pages have no syntax errors.

- [ ] **Step 8: Commit Task 4 in a real Git clone**

```bash
git add ascii-quest/character_select.php ascii-quest/game.php
git commit -m "feat: display calculated champion resources"
```

---

### Task 5: Convert movement/traps to derived Maximum Life without changing JavaScript

**Files:**
- Modify: `ascii-quest/move_character.php:29-31`
- Modify: `ascii-quest/move_character.php:123-169`
- Verify unchanged: `ascii-quest/js/game_controls.js:255-280`

**Interfaces:**
- Consumes: `CharacterStats::calculate()`.
- Produces existing JSON contract: `character_updates.current_hp` and `character_updates.max_hp`.
- Persists only: `characters.current_hp` when a trap deals damage.

- [ ] **Step 1: Load the calculator in the movement endpoint**

Add after existing includes:

```php
require_once __DIR__ . '/lib/CharacterStats.php';
```

- [ ] **Step 2: Replace `c.max_hp` with the five main-stat inputs**

Change the character SELECT to include:

```sql
c.current_hp,
c.strength,
c.dexterity,
c.vitality,
c.energy,
c.fate,
```

and remove:

```sql
c.max_hp,
```

- [ ] **Step 3: Derive Maximum Life immediately after loading the character**

Replace:

```php
$maxHp = (int) $character['max_hp'];
```

with:

```php
try {
    $characterStats = CharacterStats::calculate($character);
} catch (InvalidArgumentException $e) {
    error_log(
        'CharacterStats movement error for character ' .
        (int) $character['id'] . ': ' . $e->getMessage()
    );

    sendJson([
        'success' => false,
        'message' => 'Unable to load Champion statistics.',
        'messages' => ['Unable to load Champion statistics.'],
    ]);
}

$maxHp = $characterStats['resources']['max_life'];
```

Keep:

```php
$currentHp = (int) $character['current_hp'];
```

and keep the existing trap persistence update that writes only `current_hp`.

- [ ] **Step 4: Verify the response contract remains unchanged**

Keep the current response:

```php
$characterUpdates = [
    'current_hp' => $newHp,
    'max_hp' => $maxHp,
];
```

Do not rename `max_hp` in JSON during Milestone 1 because `game_controls.js` already consumes that key.

- [ ] **Step 5: Verify `game_controls.js` requires no change**

Inspect the existing update logic around its current `character_updates.current_hp` and `character_updates.max_hp` handling. If it still only writes the values supplied by the server, leave the file unchanged.

Run:

```bash
node --check ascii-quest/js/game_controls.js
```

Expected: no syntax errors.

- [ ] **Step 6: Run PHP tests and syntax check**

```bash
php tests/run.php
php -l ascii-quest/move_character.php
```

Expected: all tests pass; no PHP syntax errors.

- [ ] **Step 7: Commit Task 5 in a real Git clone**

```bash
git add ascii-quest/move_character.php
git commit -m "feat: derive max life during movement"
```

---

### Task 6: Run the full local pre-deployment verification

**Files:**
- Verify: all Milestone 1 PHP files
- Verify: `ascii-quest/js/game_controls.js`
- Verify: migration SQL files

**Interfaces:**
- Consumes all Task 1-5 outputs.
- Produces a known-good application revision ready to deploy together with migration 001.

- [ ] **Step 1: Run all calculator tests**

```bash
php tests/run.php
```

Expected: all tests pass, 0 failed, exit code 0.

- [ ] **Step 2: Lint every PHP source file**

```bash
find ascii-quest -name "*.php" -print0 | xargs -0 -n1 php -l
```

Expected: every file reports `No syntax errors detected`.

- [ ] **Step 3: Check JavaScript syntax**

```bash
node --check ascii-quest/js/game_controls.js
```

Expected: exit code 0.

- [ ] **Step 4: Search for forbidden legacy runtime dependencies**

Run:

```bash
rg -n "c\.max_hp|c\.max_mana|\[.?max_hp.?\]|\[.?max_mana.?\]|base_hp|base_mana|base_attack|base_defense|attack_per_level|defense_per_level|hp_per_level|mana_per_level" ascii-quest --glob '*.php'
```

Expected: no runtime references to legacy database stat columns remain. The only `max_hp` occurrence permitted in the application is the JSON compatibility key in `move_character.php` and the corresponding client-side handling/comment in `game_controls.js`.

- [ ] **Step 5: Review the change boundary**

Verify `git diff --stat`/equivalent contains only:

```text
ascii-quest/config/character_stats.php
ascii-quest/lib/CharacterStats.php
tests/CharacterStatsTest.php
tests/run.php
database/migrations/001_character_stat_system.sql
database/migrations/001_character_stat_system_verify.sql
ascii-quest/create_character.php
ascii-quest/character_select.php
ascii-quest/game.php
ascii-quest/move_character.php
```

plus the already-approved spec/plan documents. No HUD, inventory, equipment, passive skill, active skill, or battle implementation belongs in this milestone.

- [ ] **Step 6: Commit any verification-only corrections**

If verification required corrections, commit them separately with a focused message such as:

```bash
git add \
  ascii-quest/config/character_stats.php \
  ascii-quest/lib/CharacterStats.php \
  ascii-quest/create_character.php \
  ascii-quest/character_select.php \
  ascii-quest/game.php \
  ascii-quest/move_character.php \
  tests/CharacterStatsTest.php \
  tests/run.php \
  database/migrations/001_character_stat_system.sql \
  database/migrations/001_character_stat_system_verify.sql
git commit -m "fix: complete character stat migration compatibility"
```

If no correction was required, make no empty commit.

---

### Task 7: Deploy application + migration as one coordinated server change

**Files:**
- Deploy: Milestone 1 application revision
- Execute: `database/migrations/001_character_stat_system.sql`
- Execute read-only: `database/migrations/001_character_stat_system_verify.sql`

**Interfaces:**
- Consumes the live-schema review/backup from Task 2 and locally verified application from Task 6.
- Produces a server where PHP and MariaDB use the same five-stat schema.

- [ ] **Step 1: Confirm the pre-001 database backup is still available and non-empty**

Do not proceed if the backup from Task 2 cannot be located or has zero size.

- [ ] **Step 2: Put the application into a coordinated deployment window**

Ensure users are not creating or moving development Champions while the application/schema switch happens. Because this is a development server, a brief maintenance window is sufficient; do not run the new PHP against the old schema or old PHP against the completed new schema intentionally.

- [ ] **Step 3: Deploy the Milestone 1 application files**

Deploy exactly the files verified in Task 6, keeping the server's existing untracked/secret `ascii-quest/db.php` intact.

- [ ] **Step 4: Execute migration 001 against the development database**

Run the contents of:

```text
database/migrations/001_character_stat_system.sql
```

Expected: procedure completes once and inserts `001_character_stat_system` into `schema_migrations`. If any DDL statement fails, stop immediately; do not mark the migration successful manually.

- [ ] **Step 5: Run all post-migration verification queries**

Execute:

```text
database/migrations/001_character_stat_system_verify.sql
```

Expected:

```text
schema_migrations: 001_character_stat_system present
characters: new main-stat fields + current_hp/current_mana; no max_hp/max_mana/attack/defense
character_classes: exact five start_*_bonus fields and correct class values
characters count: 0
character_map_overrides count: 0
```

- [ ] **Step 6: Run server-side automated tests**

From the deployed repository root:

```bash
php tests/run.php
```

Expected: all tests pass, 0 failed.

- [ ] **Step 7: Run server-side PHP and JavaScript syntax checks**

```bash
find ascii-quest -name "*.php" -print0 | xargs -0 -n1 php -l
node --check ascii-quest/js/game_controls.js
```

Expected: all PHP files clean; JavaScript check exits 0.

---

### Task 8: Perform the Milestone 1 manual server acceptance test

**Files:**
- No code changes expected.
- If a defect is found, return to the task responsible for that behavior, write/extend a regression test when practical, fix, rerun Task 6, and redeploy the corrected files.

**Interfaces:**
- Validates the complete deployed system end-to-end.
- Produces the Milestone 1 acceptance decision.

- [ ] **Step 1: Create a Warrior and verify exact values**

Expected:

```text
STR 10
DEX 5
VIT 10
ENE 5
FATE 5
HP 200 / 200
Mana 175 / 175
```

- [ ] **Step 2: Create a Mage and verify exact resources/main stats**

Expected:

```text
STR 5
DEX 5
VIT 5
ENE 10
FATE 10
HP 150 / 150
Mana 250 / 250
```

- [ ] **Step 3: Create a Rogue and verify exact resources/main stats**

Expected:

```text
STR 5
DEX 10
VIT 10
ENE 5
FATE 5
HP 200 / 200
Mana 175 / 175
```

- [ ] **Step 4: Create a Cleric and verify exact resources/main stats**

Expected:

```text
STR 5
DEX 5
VIT 10
ENE 8
FATE 7
HP 200 / 200
Mana 220 / 220
```

- [ ] **Step 5: Verify character selection uses calculated maximums**

Open character selection and confirm every Champion shows the same current/maximum resource values and five main stats verified in Steps 1-4.

- [ ] **Step 6: Enter exploration with the Warrior**

Expected initial display:

```text
HP 200 / 200
Mana 175 / 175
```

Confirm the map renders and normal movement still works.

- [ ] **Step 7: Trigger a known 10-damage trap and verify persistence**

Expected immediately after trigger:

```text
HP 190 / 200
```

Refresh/re-enter exploration. Expected:

```text
HP 190 / 200
```

This proves only current HP is persisted while Maximum Life is derived.

- [ ] **Step 8: Regression-test current exploration interactions**

Verify each existing behavior once:

```text
- chest reward still updates Gold
- door opens and persists for the Champion
- map transition works
- current map position persists
- logout -> login -> select Champion works
```

- [ ] **Step 9: Mark Milestone 1 complete only if every acceptance item passes**

Required completion statement:

```text
MILESTONE 1 COMPLETE — READY TO CONTINUE
```

If any item fails, do not proceed to Milestone 2 until it is fixed and the entire affected acceptance path is retested.

---

## Plan Self-Review

### Spec coverage

- Five main stats and class starting bonuses: Tasks 1-3.
- Approved base values/conversions/caps/status chance: Task 1.
- Current HP/Mana persistent, maximums derived: Tasks 3-5 and Task 8.
- Browser trust boundary and safe errors: Tasks 3-5.
- Destructive Champion reset + migration tracking: Task 2 and Task 7.
- Character creation preview: Task 3.
- Character selection and exploration compatibility: Task 4.
- Trap/movement compatibility: Task 5.
- Native tests + syntax verification: Tasks 1 and 6.
- Coordinated deployment and rollback prerequisite: Tasks 2 and 7.
- Manual regression acceptance: Task 8.
- Milestone 2/3 excluded: Global Constraints and Task 6 boundary review.

### Placeholder scan

No `TBD`, `TODO`, or unspecified implementation step remains. The only intentionally environment-dependent action is executing the read-only schema inspection and existing server backup workflow; the exact SQL inspection is provided, and destructive migration finalization is explicitly gated on its actual output because the repository does not contain the live schema.

### Type/interface consistency

- `CharacterStats::startingMainStats(array): array` is used consistently by Task 3.
- `CharacterStats::calculate(array): array` uses the same grouped keys in Tasks 3-5.
- Internal `max_life` is consistently mapped to the existing JSON compatibility key `max_hp` only at the movement response boundary.
- Database field names match the approved spec across migration and PHP integration tasks.
