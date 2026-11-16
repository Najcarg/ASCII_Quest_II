# Task 31 — Complete combat item / affix catalogue

- Base: `b40409bff86a505c95764f3031ff595d8f9d04e0`
- Branch: `feature/items-task-31-combat-affix-completion`
- Worktree: `/home/najcarg/Projects/ASCII_Quest_II/task31-combat-affix`
- **Migration010 NOT APPLIED anywhere.** No database connection, equipment grant,
  historical-instance mutation, bootstrap, main promotion, or deployment performed.

Read `AGENTS.md`, README, historical `docs/tasks/current-task.md` (Task19), item
system design, migrations005–009, generator/registry/projector, equipment stat
path, and Task25–30 fixtures/guidance before implementation. The explicit Task31
brief is authoritative; no starter, formula, schema, or unrelated-source edits.
Main checkout's untracked `.agents/` and `.pi/` remain untouched.

## Migration and safety

Files: `database/migrations/010_combat_item_affix_completion.sql` and
`010_combat_item_affix_completion_verify.sql`.
Identity: `010_combat_item_affix_completion`; prerequisite:
`009_combat_status_effects`.

The migration inserts only catalogue definitions, tiers, category/family rules,
and its migration identity. It makes **no schema changes**. It rejects missing
prerequisite, recorded rerun, and partial installs/collisions in new definition
keys, affix keys, tier/category rows, or new-family mappings. The catalogue writes
and migration record share one transaction; an exception rolls back and resignals.
No IGNORE, REPLACE, UPDATE, DELETE, backfill, historical name/roll/snapshot edit,
existing-weight edit, or grant. The conventional temporary stored procedure is
created/called/dropped only by a separately authorized future migration operation.

Verification is read-only `USE` + CTE/SELECT, returning **zero violation rows** for
success. It compares every new definition/affix value, active flags, exact tiers,
exact categories (including unexpected tiers/categories), prerequisite/identity,
missing all-loot family mappings and invalid new-family/category combinations.
Neither SQL file has been executed, including in development. SQL tests inspect
text/literal rows; MariaDB syntax, locking and execution remain unverified.

## Nine new drop definitions

All: minimum item level1, maximum rarity Rare, active default1, loot weight100,
base rate modifiers default0. Nonweapons have NULL damage type and damage0–0.

| Definition key | Name | Category / subtype / slot | Damage | Toughness |
| --- | --- | --- | --- | --- |
| `worn_sword` | Sword | weapon / sword / weapon | physical4–7 | 0 |
| `worn_dagger` | Dagger | weapon / dagger / weapon | physical3–5 | 0 |
| `worn_mace` | Mace | weapon / mace / weapon | physical5–7 | 0 |
| `ash_wand` | Wand | weapon / wand / weapon | fire3–6 | 0 |
| `hide_gloves` | Hide Gloves | armour / gloves / gloves | — | 2 |
| `hide_boots` | Hide Boots | armour / boots / boots | — | 2 |
| `hide_belt` | Hide Belt | armour / belt / belt | — | 3 |
| `copper_amulet` | Copper Amulet | jewellery / amulet / amulet | — | 0 |
| `copper_charm` | Copper Charm | jewellery / charm / charm | — | 0 |

The original `worn_axe`, `oak_staff`, `hide_helm`, `hide_chest`, `wooden_shield`,
`copper_ring` remain intact, with their original values and weights. Together,
15 loot definitions cover all ten slots (helm, gloves, chest, ring, weapon,
off-hand, amulet, belt, charm, boots), and all six weapon types (sword, axe, mace,
dagger, staff, wand). No bow, dual-wield, two-handed or extra-slot mechanics.
`basic_sword`, `basic_wand`, `basic_dagger`, `basic_mace` and starter services,
class mappings, deterministic grants and existing starter instances are unchanged.

## Sixteen new affixes

All selection weights100, active default1, affix minimum item level1.
Unless noted, operation **flat**, tier1 at ilvl1 rolls **1–3**, tier2 at ilvl10
rolls **4–7**. Values are inclusive integers. At ilvl10 both tiers are eligible;
tier2 is not forced. No changes to existing affix/tier weights or selection rules.

| Key | Fragment / position | Family | Modifier | Categories |
| --- | --- | --- | --- | --- |
| `keen` | Keen / prefix | accuracy | accuracy | weapon, jewellery |
| `deadly` | Deadly / prefix | critical_chance | critical_chance | weapon, jewellery |
| `savage` | Savage / prefix | critical_damage | critical_damage | weapon, jewellery |
| `vampiric` | Vampiric / prefix | life_on_hit | life_on_hit | weapon, jewellery |
| `flaming` | Flaming / prefix | fire_damage | fire_damage | weapon, jewellery |
| `stormcharged` | Stormcharged / prefix | lightning_damage | lightning_damage | weapon, jewellery |
| `frosted` | Frosted / prefix | cold_damage | cold_damage | weapon, jewellery |
| `venomous` | Venomous / prefix | poison_damage | poison_damage | weapon, jewellery |
| `bleeding` | Bleeding / prefix | bleed_damage | bleed_damage | weapon, jewellery |
| `scorching` | Scorching / prefix | burn_damage | burn_damage | weapon, jewellery |
| `freezing` | Freezing / prefix | freeze_damage | freeze_damage | weapon, jewellery |
| `shocking` | Shocking / prefix | shock_damage | shock_damage | weapon, jewellery |
| `of_evasion` | of Evasion / suffix | dodging | dodging | armour, jewellery |
| `of_guarding` | of Guarding / suffix | block_rate | block_rate_bp | **off_hand only** |
| `of_storm_warding` | of Storm Warding / suffix | lightning_resistance | lightning_resistance | armour, off_hand, jewellery |
| `of_poison_warding` | of Poison Warding / suffix | poison_resistance | poison_resistance | armour, off_hand, jewellery |

Exceptions:
- `savage`: **flat** critical_damage, tier1 **5–10**, tier2 **11–20**; not bp.
- `of_guarding`: **additive_percent**, tier1 **1000–2500bp** (+10.00–25.00%),
  tier2 **2501–5000bp** (+25.01–50.00%). It modifies the shared base Block Rate
  factor: +2500bp makes factor1.25; existing base20% Block becomes25%, **not45%**.

Venomous uses the one `poison_damage` modifier for direct poison AND poison-status
potency. No duplicate poison-status modifier or affix was introduced. Fire/cold
resistance definitions and rules remain unchanged.

Mappings join category rules to **all existing and new loot definitions**, adding
missing pairs only. On the accepted catalogue: 32 affixes, 32 new tiers, 33 new
category pairs; 339 total definition/family pairs =80 existing +124 inherited old
families for new definitions +135 new-family pairs. Category and definition-family
allowlists are both still mandatory. Off-hand cannot roll the new offensive
prefixes or Evasion; weapons cannot roll new resistances; armour/jewellery cannot
roll Guarding. Existing Hunter, Brutal, Stout, Focused, Strong, Swift, Vital,
Energetic, Fated, Armoured, of Health/Mana/Flame Warding/Frost Warding/Haste/Channeling
remain eligible under their original category rules. No existing exclusion was
removed. Magic remains exactly one affix; Rare one prefix plus one suffix with
unique keys/families; impossible Rare combinations retain existing downgrade rules.

## Activation, projection and authoritative combat path

Runtime changes are only `ItemDefinitionRegistry::ACTIVE_MODIFIERS` and
`ItemProjector::statLine` labels. Lightning/poison resistance were already active;
the remaining14 modifiers are added, retaining the complete existing vocabulary
and unknown-modifier filtering. No generator, aggregator, CharacterStats,
provider, resolver or combat formula changes.

All16 labels are tested exactly: Accuracy, Critical Chance, Critical Damage,
Dodging, Life on Hit, Block Rate, Fire Damage, Lightning Damage, Cold Damage,
Poison Damage, Bleed, Burn, Freeze, Shock, Lightning Resistance, Poison Resistance.
Flat lines are `+N Label`, including `+10 Critical Damage`; Block Rate is
`+N.NN% Block Rate`, e.g. `+25.01% Block Rate`. No hidden proc chances, generation
weights, family keys, affix selection keys, random draws or min/max roll information
are added to the public projection. Existing public value/tier metadata is retained.

The unchanged production path is:

```text
SQL catalogue -> ItemDefinitionRegistry -> ItemGenerator(ItemRandomSource)
-> immutable character_items + character_item_affixes
-> EquipmentRepository -> EquipmentStatAggregator -> CharacterStats
-> PersistentCombatEquipmentProvider
-> immutable offense snapshots / current defense and utility at resolution
-> existing combat services and resolvers
```

The tests use actual registry/generator, production EquipmentRepository SQL reads,
aggregator, CharacterStats, provider and combat services/resolvers. A strict
connection-free PDO double holds generated immutable item/affix rows in separate
arrays; it is not an alternate production stat path and does not execute SQL.
They prove accuracy and critical boundaries/damage, Life on Hit recovery,
elemental components, all five status applications, dodge and Block boundaries.
Lightning/poison resistances are proven through actual `currentDefense`; the
existing enemy mitigation resolver only accepts physical/fire, so no unsupported
lightning/poison enemy attacks or new mitigation formulas were invented.

### Unchanged Task30 status chance examples

CharacterStats remains the chance authority; each status rolls independently.

| Potency | Proc chance |
| --- | --- |
| 1 | 5% |
| 2 | 10% |
| 3 | 15% |
| 4 | 20% |
| 5 | 25% |
| 7 | 35% |

Chance is not potency: Bleed/Burn/Poison3 still tick3, Freeze3 still slows3%,
Shock3 still amplifies3%. Poison keeps its direct damage even on a failed status
roll. Existing caps, four-second timing, refresh, immutable snapshots, replay,
resolution order and skill exclusion are untouched and previous suites stay green.

## Real RED → GREEN and exact validation totals

122 new named tests live in `tests/CombatTask31Test.php`, with shared SQL-row and
strict PDO fixtures in `tests/Task31Fixtures.php`, focused runner
`tests/run_task31.php`, and registration in `tests/run.php`.
Tests cover exact keys/families/operations/positions/levels/ranges/categories,
guards, verifier, no mutations, all15 mappings, every eligible definition/category,
legacy generation, registry rejection, deterministic full-catalogue weighted
selection, both inclusive tier endpoints, Magic/Rare uniqueness, all16 exact
public stat lines, and generated-row combat integration.

Migration SQL was authored first and tested statically, **not executed**. Before
runtime edits the initial118-test focused run was33 passed/85 failed; full PHP
644/85. Registry activation + labels exposed five test-harness mistakes (event
outcome belongs to projected events rather than an action `resolved_outcome`
field, and the existing defense resolver is `PrototypeBlockResolver`). Correcting
only those harness APIs and adding four coverage cases produced122/0.

To establish clean RED evidence with the final corrected122 tests, the two runtime
files were temporarily restored byte-for-byte from the accepted base:

| Phase / command | Exact result |
| --- | --- |
| Final tests + base runtime: `php tests/run_task31.php` | **34 passed,88 failed**, exit1 |
| Final tests + base runtime: `php tests/run.php` | **645 passed,88 failed**, exit1 |
| Activated registry, base projector: focused runner | **94 passed,28 failed**, exit1 |
| Restored registry + labels: focused runner | **122 passed,0 failed**, exit0 |
| Restored registry + labels: full PHP | **733 passed,0 failed**, exit0 |

Representative real RED failures: Keen/Deadly/Savage/Vampiric and elemental/status
prefixes were ineligible because registry vocabulary filtered them out; Evasion
and Guarding similarly could not generate. With activation alone, the28 projection
cases failed `Unsupported public item modifier.`; four lightning/poison resistance
projection cases already worked. Final RED/GREEN runs contain no warnings/notices.
All **611 existing PHP tests** remain green, including Tasks18–30.

Final required checks (all exit0):

| Command | Result |
| --- | --- |
| `php tests/run.php` | **733 passed,0 failed** |
| `php tests/run_task31.php` | **122 passed,0 failed** |
| `php tests/run_task27.php` | **20 passed,0 failed** |
| `php tests/run_task28.php` | **18 passed,0 failed** |
| `php tests/run_task29.php` | **43 passed,0 failed** |
| `php tests/run_task30.php` | **152 passed,0 failed** |
| `node tests/ItemHudTest.js` | **21 passed,0 failed** |
| `node tests/CombatHudTest.js` | **32 passed,0 failed** |
| `node tests/ExplorationHudTest.js` | **30 passed,0 failed** |
| `find . -type f -name '*.php' -print0 \| sort -z \| xargs -0 -n1 php -l` | **135 files passed** |
| `find . -type f -name '*.js' -print0 \| sort -z \| xargs -0 -n1 node --check` | **7 files passed** |
| `git diff --check` | Passed |

## Review gate and remaining manual testing

**Migration010 NOT APPLIED.** Browser testing is deliberately deferred until
ChatGPT GitHub review, explicit migration authorization, deployment authorization,
and separately authorized test equipment exist. Future browser checks should
exercise all ten slots/six weapon types, generated Magic/Rare names/tooltips,
all16 displayed lines, Details stats, accuracy/critical contact, LoH, dodge,
attempted Block, elemental damage, per-status chance, and refresh/relogin
persistence without historical rerolls or starter changes.

Catalogue expansion inherently changes relative drop/affix frequencies while
retaining every existing row's weight; balancing is not claimed. SQL checks are
static, PDO integration is connection-free, and live MariaDB transactions/locking,
real drop generation and browser behavior remain unverified. No architecture
change is needed for this task. The preexisting enemy damage-type limitation is
not expanded. Stop after authorized feature commit/push for ChatGPT GitHub review;
no main promotion, deployment, migration application, grants or cleanup of backups.
