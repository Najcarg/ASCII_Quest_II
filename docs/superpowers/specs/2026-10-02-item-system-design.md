# ASCII Quest II — Item System Design

**Date:** 2 October 2026

**Status:** Ready for review

**Roadmap:** Tasks 18–20 — Persistent Inventory, Item Drops, and Equipment

## 1. Purpose

This specification defines the first authoritative item loop:

```text
fight
-> victory
-> physical item drop
-> Champion inventory
-> equipped item
-> later combat uses equipped values
```

Task 17 changes no production code or database state. It fixes the rules and
boundaries that Tasks 18–20 must implement without independently inventing
ownership, generation, rarity, affix, claim, equipment, or combat semantics.

The existing PHP/MariaDB/vanilla JavaScript architecture remains. Items and
all random results are server-owned. The browser submits intent and renders an
allowlisted projection.

## 2. Repository findings and inherited contracts

- Migrations `003_combat_foundation` and
  `004_combat_enemy_ai_initialization` are the current highest migrations.
  Therefore the next migration is `005`, subject to reinspection immediately
  before Task 18 begins.
- Each migration preflights prerequisites and partial installation, rejects a
  recorded rerun, records one `schema_migrations` identity, and has separate
  read-only verification SQL. Development does not apply production SQL.
- `combat_encounters.status = victory_loot` persists until an explicit close.
  Gold and EXP already issue exactly once.
- `CombatStateProjector` already projects `loot_phase.physical_drops` as an
  empty list. This becomes the physical-drop projection in Task 19.
- `CombatEquipmentProvider::offensiveSnapshot()` is called when a player
  weapon action starts. The resulting action snapshot is immutable.
- `CombatEquipmentProvider::currentDefense()` is called when enemy damage
  resolves. Task 20 replaces `PrototypeCombatEquipmentProvider` in production
  with equipped-item-derived values without changing this timing boundary.
- `CharacterStats` is the only authority for Champion derived statistics.
  Item modifiers must feed that authority rather than duplicate its formulas.
- The right HUD already contains Equipment, Loadout, and a 25-cell Inventory
  shell. The paper doll exposes `helm`, `gloves`, `chest`, `ring`, `weapon`,
  `off-hand`, `amulet`, `belt`, `charm`, and `boots`.
- Unresolved combat currently means `active` or unclosed `victory_loot`.
  Existing Champion-first then Account-mutex then Encounter lock ordering is
  preserved whenever inventory work participates in a combat transaction.

## 3. Authority and invariants

An item is a persistent database instance. It survives refresh, logout/login,
victory close, application-process replacement, and server restart.

Only the server chooses or validates:

- whether a physical item drops;
- source and item level;
- base definition, rarity, affix keys, affix tiers, and rolled values;
- immutable display name;
- pre-claim encounter association and post-claim Champion ownership;
- inventory and equipment state;
- all derived combat effects.

The client never submits stats, rarity, item level, names, rolls, ownership,
drop chances, random thresholds, or equipment effects. It may submit only an
item ID, an allowed destination slot when needed, CSRF, and a UUIDv4 request
token.

Durable unique constraints and transactions—not browser state—prevent a
second generation, claim, inventory insertion, or equipment assignment.

## 4. Item taxonomy

### 4.1 Initial categories and base types

The initial catalogue contains only types with a current gameplay role:

| Category | Initial base types | Equipment slot |
|---|---|---|
| Weapon | sword, axe, mace, dagger, staff, wand | `weapon` |
| Armour | helm, gloves, chest, boots, belt | matching named slot |
| Off-hand | shield | `off-hand` |
| Jewellery | ring, amulet, charm | matching named slot |

Bows are deferred until ranged weapon behavior exists. Two-handed weapons and
dual wield are deferred. A shield is the only initial off-hand type. Every
initial weapon is treated as one-handed and may coexist with a shield.

### 4.2 Authoritative slot model

The existing HUD model becomes authoritative without changes:

```text
helm, gloves, chest, ring, weapon,
off-hand, amulet, belt, charm, boots
```

There is exactly one item per slot. There is one ring slot, not two. Charm is
an equipped jewellery slot, not an inventory-only passive. An item definition
names exactly one compatible slot. No item may occupy multiple slots in this
milestone.

## 5. Base definitions and immutable instances

### 5.1 Base item definition

A base definition is catalogue data, not player state. It has:

- stable ASCII `definition_key`;
- display name, category, subtype, and one equipment slot;
- damage type and base minimum/maximum damage for weapons;
- base toughness/armour and rate modifiers where applicable;
- minimum item level and maximum eligible rarity;
- visual glyph;
- active flag for future retirement without invalidating existing items;
- allowed affix families through relational rules.

Definitions are stored relationally and seeded by reviewed migration SQL.
Changing a definition later must not silently reroll an existing item. Values
that determine an instance's combat identity are copied to immutable instance
snapshot columns when it is generated.

### 5.2 Item instance

An instance has:

- unique unsigned database ID;
- nullable owner Champion ID before claim, non-null after claim;
- base definition key;
- immutable item level and rarity;
- immutable base damage/defense/rate snapshot used by that instance;
- zero or more immutable affix-roll rows;
- immutable server-built display name;
- generated timestamp;
- immutable provenance type and source key;
- source encounter and reward-slot identity for combat drops;
- optional claim timestamp;
- no equipment slot column; equipment is a separate relation.

The rendered name is not the identity. The instance ID, definition key, and
rolled relational rows are authoritative. Core stats are never stored only in
JSON or only in the browser.

### 5.3 Starter weapon lifecycle

The real equipment system must never depend on the production prototype
weapon. Four stable item definitions are reserved for deterministic starter
grants:

| Champion class key | Starter definition key | Base type |
|---|---|---|
| `warrior` | `basic_sword` | sword |
| `mage` | `basic_wand` | wand |
| `rogue` | `basic_dagger` | dagger |
| `cleric` | `basic_mace` | mace |

Each starter weapon is a real `character_items` row owned only by its Champion,
with Normal rarity, item level 1, no affix rows, and immutable provenance:

```text
source_type = starter
source_key = initial_weapon
```

It is created directly from the mapped stable base definition. It uses no
random source, no rarity or affix roll, and no combat drop row.

After Migration 007 and the equipment services exist, new Champion creation
must insert the Champion, create the starter instance, and assign it to the
`weapon` slot in the existing character-creation database transaction. Any
starter or equipment failure rolls back the entire transaction, including the
Champion row. Refresh, request retry, or two tabs cannot issue another starter:
the database uniquely enforces `(character_id, source_type, source_key)`.

Migration 007 does not create gameplay items or equipment rows for existing
Champions. Task 20 provides a separate reviewed bootstrap operation. For each
locked existing Champion it applies this policy:

1. DEAD Champion: record a skipped result and create nothing.
2. Unresolved `active` or `victory_loot` encounter: record a deferred result,
   create nothing, and allow a later idempotent rerun after closure.
3. Usable weapon already equipped: leave all items/equipment unchanged.
4. No equipped weapon but an owned usable weapon exists: create nothing and
   leave that real item unchanged; the ordinary equipment UI may equip it.
5. No equipped weapon and no owned usable weapon: create the class starter and
   equip it atomically in `weapon`.

For this bootstrap, an owned usable weapon means an owned `character_items`
row whose snapshotted equipment slot is `weapon` and whose definition remains
valid for historical use; display name is never consulted. The reviewed
bootstrap is a CLI/maintenance path, defaults to report-only preview, requires
an explicit apply flag, processes one Champion per transaction, and is never
invoked by migration SQL or ordinary page load.

The prototype production provider remains active during schema/service rollout
and bootstrap review. It is removed only after the starter grant service,
creation integration, bootstrap path, equipment reads, and cutover tests are
available. After cutover, a Champion with no equipped weapon receives a
controlled unavailable-weapon result; production combat never silently falls
back to prototype damage.

## 6. Item level

Item level is an immutable positive integer fixed by the content that created
the drop.

For Task 19:

```text
item_level = encounter.loot_source_level
```

`loot_source_level` is snapshotted from the server enemy/encounter definition
when a new encounter is created. Champion level does not raise item level and
is not part of the formula. Existing encounters that predate the Task 19
marker do not generate retroactive physical drops.

A base item is eligible only when its minimum item level is at most the source
item level. An affix tier is eligible only when its own minimum item level is
at most the item level. The generator selects only from eligible definitions
and tiers. Low-level content can therefore drop items, but never endgame base
types or endgame affix tiers. There is no artificial no-drop penalty merely
because a high-level Champion fights low-level content.

Item level initially controls generation power, not equip eligibility. There
is no Champion-level equip requirement in Tasks 18–20.

## 7. Rarity

The initial rarities are deliberately small:

| Rarity | Initial affixes | Name form |
|---|---:|---|
| Normal | 0 | `Base` |
| Magic | exactly 1 prefix or suffix | `Prefix Base` or `Base Suffix` |
| Rare | exactly 1 prefix and 1 suffix | `Prefix Base Suffix` |

Rarity weights are version-controlled server configuration and are not fixed
by this design. The server first rolls rarity from definitions eligible for
the source, then rolls the required affixes. If no valid combination exists,
the generator deterministically falls back to the highest lower rarity it can
complete; it never emits a partially valid rarity.

Special modifiers are supported as a future affix position in the schema but
are not generated or activated in Tasks 18–20. Unique, legendary, set, and
crafted rarities are outside scope.

## 8. Affix model and naming

An affix definition contains:

- stable `affix_key` and mutually exclusive `family_key`;
- position: `prefix`, `suffix`, or future `special`;
- display fragment, such as `Hunter` or `of Health`;
- canonical modifier key and operation;
- allowed item categories through a relational allowlist;
- active flag.

An affix tier contains its affix key, ordinal tier, minimum item level, and
inclusive integer minimum/maximum roll. An instance roll records the affix,
tier, and final integer value. That result never changes after generation.

One item cannot contain two affixes from the same family. A prefix and suffix
may modify the same public statistic only when their families are different;
their values then combine using the statistic's declared stacking rule. The
generator enforces both item-definition family allowlists and affix-category
allowlists.

The server builds and stores the display name at generation:

```text
trim(prefix_fragment + " " + base_display_name + " " + suffix_fragment)
```

Missing fragments produce no doubled or leading/trailing spaces. Example:
`Hunter Axe of Health`. The browser never constructs the authoritative name.

## 9. Modifier vocabulary and activation stages

All rolled numeric values are signed integers. Flat values use game units;
percent and rate values use basis points (`100 bp = 1%`). Operations are
explicit (`flat` or `additive_percent`) rather than inferred from a label.

| Modifier | Data model | Task 20 combat effect |
|---|---|---|
| weapon base damage, flat damage, damage % | supported | active for new weapon snapshots |
| Maximum Life, Maximum Mana | supported | active, with no-free-refill rule |
| Strength, Dexterity, Vitality, Energy, Fate | supported | active through `CharacterStats` |
| toughness/armour | supported | active in current defense |
| fire/lightning/poison/cold resistance | supported | active in current defense |
| Attack Rate | supported | active for future weapon-action duration snapshots |
| Cast Rate | supported | active for future skill-action duration snapshots |
| Block Rate | supported | deferred; prototype Block remains unchanged |
| critical chance, critical damage | supported | projected/aggregated; hit/critical resolution deferred |
| dodge | supported | projected/aggregated; hit/miss resolution deferred |
| life on hit | supported | deferred |
| bleed/poison/burn/freeze/shock damage/effects | family-capable | deferred |

Task 19's initial roll pool must contain only modifiers whose storage and
public display are complete. It should favor the Task 20-active subset so a
dropped item has an observable purpose. Deferred modifiers may exist as
inactive definitions for structural tests but must not be randomly generated.

## 10. Rate semantics

Item Attack Rate, Cast Rate, and Block Rate are additive percent bonuses stored
as signed integer basis points. Item bonuses of `500 bp` and `750 bp` combine
to `1250 bp` (12.5%). They are not raw chances and are not multiplicative
factors in storage.

At the derived-stat boundary:

```text
rate_factor = base_rate_factor * max(0.10, 1 + total_rate_bonus_bp / 10000)
effective_duration_ms = max(1, round(base_duration_ms / rate_factor))
```

Task 20 activates Attack Rate for weapon action duration and Cast Rate for
skill duration. The effective duration is calculated and persisted when the
action starts; later equipment changes cannot alter it. Rate never changes
cooldown, Action cost, damage, or Turn duration unless a later design says so.

Block Rate is reserved for a later approved passive/active Block formula. It
does not alter the current provisional Block prompt availability, success
chance, or damage reduction in Task 20.

## 11. Class and item compatibility

Warrior, Mage, Rogue, and Cleric may equip every initial item. There are no
class, attribute, Champion-level, or weapon-family restrictions in Tasks
18–20. Slot compatibility and ownership are sufficient. This keeps loot useful
without inventing class balance rules. Restrictions can be added later as
definition data and server validation, but cannot be inferred by the client.

## 12. Inventory model

Inventory belongs to one Champion, never to the account. An item may be
unclaimed in one encounter reward context or owned by exactly one Champion.
It cannot be shared between Champions.

The initial inventory is an unbounded ordered collection. There is no stored
inventory slot index and no capacity rejection. Ordering is stable: unequipped
items are sorted newest claim first, then descending item ID. The existing 25
cells become one display page; paging is presentation, not ownership.

An equipped item remains owned by the same Champion. Ownership is stored on
the item; equipment state is stored separately. Equipping does not move or
rewrite ownership. The ordinary Inventory projection lists unequipped items;
the Equipment projection lists equipped items; both are views of the same
Champion-owned collection.

## 13. Equipment commands

### 13.1 State rules

Equip, unequip, and swap are allowed only while the selected Champion is
alive and has no unresolved encounter. For this milestone:

- `active`: inventory may be viewed, equipment mutations are rejected;
- `victory_loot`: claim is allowed, equipment mutations are rejected;
- `closed` or no encounter: normal equipment mutations are allowed;
- DEAD: inventory remains historical/readable, but claim/equip/unequip are
  rejected.

### 13.2 Operations

An equip request supplies an owned item ID and its desired slot. The server
locks the Champion, Account combat mutex when required, active encounter,
command token, item, current slot row, and displaced item in a consistent
order. It rechecks life/combat state, ownership, and definition compatibility.

- Empty target: insert the equipment relation.
- Occupied target: atomically replace the relation; the displaced item remains
  owned and becomes unequipped.
- Already equipped in that same slot: successful idempotent no-op.
- Item equipped elsewhere: reject; the client must express the intended slot
  and the server never silently invents a multi-slot move.

Unequip removes only the requested owned item from its actual slot. A stale
item/slot pair is rejected unless it is a replay of the same completed token.
Unique constraints enforce one row per Champion/slot and one equipment row
per item.

There is no equipment mutation during unresolved combat. This intentionally
overrides the earlier Milestone 1 future possibility of live combat swapping:
it preserves the snapshot/current-defense architecture while removing a
mid-action exploit surface for Milestone 2.

## 14. Combat integration

Task 20 adds a real equipment provider and, only after the starter/equipment
cutover gate in Section 5.3 passes, removes the prototype provider from
production bootstrap while retaining it only where an explicit test fixture
needs it.

### 14.1 Offensive snapshot

At player action start, the provider loads the currently equipped weapon and
all equipped offensive modifiers, then persists the existing action snapshot
plus any additive fields required by the finalized Task 20 resolver:

- item instance and base definition identity;
- damage type;
- resolved base/flat/percent damage result;
- effective weapon or cast duration/rate input;
- accuracy, critical chance, and critical damage values for future use.

The action resolves exclusively from that stored snapshot. A later equipment
change, even after combat closes, cannot rewrite historical actions.

### 14.2 Current defense

At enemy hit resolution, `currentDefense()` derives toughness, elemental
resistances, dodge, and future Block inputs from the Champion's current
equipped records through `CharacterStats`. Since Task 20 blocks all equipment
mutation during unresolved combat, the value is stable during a Milestone 2
fight, but the resolution-time read contract remains intact for future rules.

### 14.3 Maximum resources

Equipment changes never heal or refill:

```text
new_current_hp = min(old_current_hp, new_max_life)
new_current_mana = min(old_current_mana, new_max_mana)
```

When a maximum increases, current value is unchanged. When it decreases,
current value clamps down in the same equipment transaction. HP and Mana are
never raised by equip, unequip, swap, refresh, projection, or login.

## 15. Drop generation and persistence

### 15.1 Exactly once at victory

When synchronization transitions an encounter to `victory_loot`, and in the
same locked transaction as terminal reward handling, Task 19 processes each
configured physical reward slot exactly once. It inserts one
`combat_item_drops` outcome row under unique `(encounter_id, reward_slot)`.

The outcome row exists for both `none` and `item`. For an item result, the
server also creates the complete immutable item and affix rows and links the
item uniquely to that outcome. A lost response, refresh, retry, duplicate
victory processing, or second tab finds the existing outcome. It never rolls
again.

The unclaimed item has no Champion owner. Its authority is the encounter drop
row, which belongs to the encounter's Champion. It is not account inventory.

### 15.2 Chance and item find

Chance values are integer basis points. Final balance weights remain outside
this design. The future-capable formula is:

```text
total_item_find_bonus_bp = sum(server-derived bonuses)
effective_chance_bp = min(
    10000,
    floor(base_drop_chance_bp * (10000 + total_item_find_bonus_bp) / 10000)
)
```

Bonuses are additive to one another and multiplicative to the base chance. A
5% base chance with +25% item find becomes 6.25%, not 30%. Negative totals
cannot reduce the multiplier below zero. The server snapshots the effective
chance and hidden random roll on the outcome row for audit; neither is public.

### 15.3 Claim and close

An explicit Claim action transfers the item to the fighting Champion exactly
once by setting owner and claim timestamps under locks. Replaying the same
token and same claim returns the persisted success. A different command or
payload with the token is a collision. Another Champion or account receives a
not-found/rejected result without learning item details.

`Close / Continue` atomically auto-claims every remaining item outcome before
closing the encounter. This is the authoritative no-accidental-loss rule. It
is safe because inventory has no capacity limit. If any claim cannot complete,
the transaction rolls back and victory remains open. A repeated close returns
the already-closed stable result and cannot duplicate ownership.

## 16. Idempotency and concurrency

Every item mutation—claim, equip, unequip, and atomic swap—requires a UUIDv4
request token. A durable command row is unique per Champion and token and
stores command type, a SHA-256 fingerprint of canonical intent, relevant item
and slot identities, result code, and completion timestamp.

- Same token + same command/fingerprint: return the recorded semantic result.
- Same token + different command/fingerprint: reject as a collision.
- A token row is written in the same transaction as its mutation.
- Database uniqueness is the last guard for two-tab races.

Close/Continue uses its existing terminal replay behavior and additionally
performs idempotent auto-claim. It must not require the browser to submit item
IDs; the server reads all unclaimed outcomes for the locked encounter.

## 17. Proposed relational schema

Names below are authoritative for planning. Changing one requires a reviewed
spec revision first. All tables use InnoDB and matching `INT UNSIGNED`
foreign keys after live-shape preflight.

### 17.1 Migration 005 — inventory foundation

#### `item_definitions`

- PK: `definition_key VARCHAR(64)` with ASCII binary collation.
- Fields: display name, category, subtype, equipment slot, damage type,
  immutable-generation base damage/toughness/rate values, minimum item level,
  maximum rarity, glyph, active flag, timestamps.
- Indexes: category/slot/active and minimum item level.
- Immutable after an instance uses it except display-only corrections and
  retirement; balance changes require a new definition key/version.
- Migration 005 seeds `basic_sword`, `basic_wand`, `basic_dagger`, and
  `basic_mace` as active level-1 Normal-eligible weapon definitions. It creates
  no item instances and equips nothing.

#### `character_items`

- PK: `id INT UNSIGNED AUTO_INCREMENT`.
- FKs: nullable `character_id -> characters(id) ON DELETE CASCADE` and
  `definition_key -> item_definitions(definition_key) ON DELETE RESTRICT`.
- Fields: item level, rarity, display name, immutable base snapshots,
  non-null ASCII-binary `source_type VARCHAR(24)`, non-null ASCII-binary
  `source_key VARCHAR(128)`, and generated/claimed timestamps.
- Initial `source_type` CHECK values are `starter` and `combat_drop`. A combat
  drop source key is the canonical encounter/reward-slot identity; a starter
  source key is exactly `initial_weapon`.
- Constraints: item level positive; rarity allowlist; owner and claim
  timestamp nullability agree.
- Unique `(character_id, source_type, source_key)` is the durable grant guard.
  Starter rows use `starter`/`initial_weapon`; combat drops use their distinct
  drop provenance and retain `combat_item_drops` uniqueness as their primary
  generation guard. Provenance never depends on display name.
- Unique composite `(id, character_id)` supports ownership-matching equipment
  foreign keys later.
- Indexes: `(character_id, claimed_at, id)` and definition/item level.

#### `item_mutation_requests`

- PK: numeric ID; FK Champion `ON DELETE CASCADE`.
- Unique `(character_id, request_token)`.
- Fields: command type, request fingerprint, nullable item ID/source and
  target slots, stable result code, completion timestamp.
- Indexes: item ID and completion time.
- Mutable only from in-progress to one terminal result inside one transaction.

### 17.2 Migration 006 — generation and physical drops

#### `item_affix_definitions`

- PK `affix_key`; unique stable definition.
- Fields: family key, position, display fragment, modifier key, operation,
  active flag.
- Indexes: family/position/active and modifier key.
- Definition deletion is restricted once rolled.

#### `item_affix_tiers`

- Composite PK `(affix_key, tier_ordinal)`; FK affix `ON DELETE CASCADE` only
  before any roll exists, with rolled-row restriction preserving used tiers.
- Fields: minimum item level and inclusive min/max integer roll.
- Index on minimum item level.

#### `item_affix_category_rules`

- Composite PK `(affix_key, item_category)`; FK affix.
- Relational category allowlist; no JSON security rule.

#### `item_definition_affix_families`

- Composite PK `(definition_key, family_key)`; FK item definition.
- Restricts which affix families a base definition can roll.

#### `character_item_affixes`

- PK numeric ID; FK item `ON DELETE CASCADE`.
- Unique `(item_id, position)` for the initial one-prefix/one-suffix rule and
  unique `(item_id, family_key)` for mutual exclusion.
- Fields: affix key, tier ordinal, family snapshot, rolled integer value.
- Composite FK to the selected affix tier; immutable after insert.

#### `combat_item_drops`

- PK numeric ID; FK encounter `ON DELETE CASCADE`; nullable unique item FK
  `ON DELETE RESTRICT`.
- Unique `(encounter_id, reward_slot)` and unique `item_id`.
- Fields: outcome (`none`, `unclaimed`, `claimed`), hidden base/effective
  chance and roll basis points, claimed Champion/timestamp.
- Composite ownership checks occur in the service transaction; indexes support
  encounter projection and unclaimed lookup.

#### `combat_encounters` additions

- nullable `loot_source_level INT UNSIGNED`;
- nullable `item_drops_generated_at DATETIME(6)`.

New encounters snapshot a positive source level. Existing rows remain null
and are ineligible for retroactive generation. The generation timestamp is an
aggregate audit marker; unique reward-slot rows remain the exactly-once guard.

### 17.3 Migration 007 — equipment

#### `character_equipment`

- Composite PK `(character_id, equipment_slot)`.
- Unique `item_id` prevents one item occupying two slots.
- Composite FK `(item_id, character_id) -> character_items(id, character_id)`
  enforces matching ownership; Champion delete cascades, item delete is
  restricted while equipped.
- Fields: equipped and updated timestamps.
- Slot CHECK allowlists the ten authoritative slots.

#### `combat_actions` addition

- nullable `snapshot_weapon_item_id INT UNSIGNED` with an index and a foreign
  key to `character_items(id) ON DELETE RESTRICT`.
- Weapon actions set it at start; skills and historical prototype actions keep
  it null. Existing snapshot timing/damage columns continue to store resolved
  offensive values, and `resolves_timeline_ms - started_timeline_ms` is the
  immutable effective duration. No duplicate duration/rate column is added.

Core ownership, affixes, drop linkage, and equipment are relational. JSON is
not used for authoritative data. Optional future display metadata may use JSON
only when it has no ownership, validation, generation, or combat meaning.

## 18. Security and endpoint contract

Recommended routes are `inventory_state.php`, `item_claim.php`,
`equip_item.php`, and `unequip_item.php`. Exact routing may follow existing
endpoint naming, but each must:

- require authenticated `user_id` and selected `character_id`;
- require POST for mutations and valid session CSRF;
- require an exact request-key allowlist and strict scalar types;
- validate UUIDv4 tokens before database work;
- derive Champion and account ownership from session;
- lock and validate item/drop ownership and slot compatibility server-side;
- apply DEAD and unresolved-combat state rules server-side;
- return controlled generic errors that do not reveal another Champion's item;
- never log or return hidden rolls, generation weights, or request secrets.

Read projections are no-store. Mutation responses return a sanitized current
inventory/equipment or loot projection after the committed command.

## 19. Browser projection and UI

The item projection may expose:

- item ID, display name, rarity, item level;
- base type/category and compatible slot;
- glyph;
- public affix names, tiers if desired for display, and rolled values;
- public derived stat lines;
- equipped boolean and equipped slot;
- claim state for the selected Champion's victory drop.

It must omit generation seeds, hidden roll/chance thresholds, weight tables,
inactive definition data, mutation tokens/fingerprints, and all ownership
internals or items belonging to another Champion.

Task 18 replaces the Inventory note and empty placeholders with a real,
pointer-selectable 25-item page, safe empty state, details panel/tooltip, and
pagination when needed. It remains read-only. Migration 005 also seeds the
four starter base definitions and makes deterministic provenance representable,
but Task 18 grants or equips no starter items.

Task 19 renders each victory item with name, rarity, base type, affixes, item
level, and unclaimed/claimed state. Claim and Close/Continue are pointer
controls. Refresh renders the identical drop.

Task 20 makes equipment and inventory items pointer-interactive. Selecting an
item exposes compatible Equip/Swap or Unequip intent; drag-and-drop is not
required. Disabled reasons are visible for DEAD or unresolved-combat states.
All text is inserted with safe DOM text APIs or escaped server output.

## 20. Task split

### Task 18 — Persistent Inventory Foundation

Scope:

- Migration 005 and verification SQL;
- base definitions including four starter weapons, owned immutable item rows,
  deterministic provenance, and mutation receipt foundation;
- inventory repository/service/projector and read endpoint;
- real paged inventory display with persistence across refresh/login;
- security/ownership/projection tests.

Likely components: migration SQL and migration test; item catalogue seed;
`ItemRepository`, `InventoryService`, `ItemProjector`, bootstrap, endpoint;
`game.php`, a focused item HUD JavaScript module, CSS, and Node tests.

Non-goals: granting or equipping starter items, random generation, affixes,
victory drops/claim, equipment relations or effects, item disposal, capacity,
vendors, trading, crafting.

Acceptance: one selected Champion sees only its durable allowlisted items in
stable order; another account or Champion cannot enumerate or mutate them;
empty and more-than-25 states render safely; refresh and logout/login preserve
state; all four starter definitions and the provenance uniqueness contract are
present; Migration 005 passes structure tests but is not applied automatically.

### Task 19 — Item Generation + Physical Drops

Scope:

- Migration 006 and verification SQL;
- affix catalogue/tiers/rules, deterministic injected random source, item
  generator, source-level snapshot, exactly-once drop outcome;
- victory projection, claim command, and close auto-claim;
- physical-drop UI and claim-state rendering.

Likely components: combat definitions, encounter creation/terminal reward
path, repository and synchronizer integration, generator/catalogue services,
`CombatStateProjector`, `CombatService::closeVictory`, claim endpoint,
`combat_hud.js`, `game.php`, CSS, PHP/JS/migration tests.

Non-goals: equipment, item combat effects, final rarity/drop percentages,
rerolling, crafting, vendors, trading, retroactive drops for old encounters.

Acceptance: victory creates or records no-drop exactly once; an item result is
complete and immutable; refresh/two tabs/retries cannot reroll or duplicate;
only the fighting living Champion can claim; Close auto-claims; item level and
tiers are source-bound; private roll data is absent from projections.

### Task 20 — Equipment System

Scope:

- Migration 007 and verification SQL;
- equipment repository/service/projector and equip/unequip endpoints;
- atomic starter grant during new Champion creation;
- reviewed, report-first bootstrap for eligible existing living Champions;
- pointer-based equipment UI and atomic swap;
- equipment-aware `CharacterStats` and production combat equipment provider;
- active modifier subset, action snapshot integration, current defense, and
  HP/Mana clamp behavior.

Likely components: equipment schema/test; `CharacterStats`; item/equipment
services; `StarterEquipmentService`; `create_character.php`; reviewed bootstrap
CLI; `CombatEquipmentProvider` implementation and `CombatBootstrap`; the
weapon-instance snapshot field; `game.php`, item HUD JavaScript, CSS, PHP/JS
tests.

Non-goals: combat swapping, two-handed weapons, dual wield, class/stat/level
requirements, final critical/dodge/Block/status formulas, loadout/potion item
integration, item comparison automation, drag-and-drop requirement.

Acceptance: every newly created class receives its mapped Normal level-1
starter weapon exactly once and already equipped; eligible existing living
Champions receive at most one starter through the reviewed bootstrap; DEAD,
equipped, and already-armed Champions remain unchanged; one compatible owned
item occupies each slot; swap is atomic and retry-safe; wrong-slot/unowned/
cross-Champion/dead/combat mutations fail; equipped values affect only future
offensive snapshots and resolution-time defense; HP/Mana never refill and
clamp only downward; prototype production equipment is removed only after the
starter/equipment cutover is available and verified.

## 21. Test strategy

Tasks 18–20 use dependency-free PHP fakes, SQL structure tests, endpoint
contract tests, and Node fake-DOM tests in the established suite.

Required coverage includes:

- owner-only inventory, cross-user and cross-Champion denial;
- persistence across refresh and logout/login;
- stable ordering and more-than-25 paging;
- one generation outcome per encounter/reward slot;
- source item level and tier eligibility; no Champion-level inflation;
- immutable rarity/affixes/name after generation;
- no-drop persistence and duplicate generation retry;
- duplicate claim, response-loss replay, two-tab claim race;
- same-token replay and different-command/payload token collision;
- Close/Continue auto-claim and rollback on claim failure;
- equip wrong slot, unowned item, already-equipped item, and duplicate slot;
- atomic occupied-slot swap and two-tab equipment race;
- DEAD and active/victory-loot mutation locks;
- current HP/Mana unchanged on maximum increase and clamped on decrease;
- offensive snapshot immutability and current-defense resolution read;
- Attack/Cast Rate duration snapshot semantics and Block Rate inactivity;
- projection allowlist/privacy and safe item-name rendering;
- Warrior sword, Mage wand, Rogue dagger, and Cleric mace starter mappings;
- Normal rarity, item level 1, no affixes, owned/equipped starter state;
- creation/bootstrap retry and two-tab starter-grant uniqueness;
- existing equipped/owned usable weapon preservation;
- existing living no-weapon grant, DEAD skip, and unresolved-combat deferral;
- creation failure rollback and existing-bootstrap HP/Mana preservation;
- no production prototype fallback after successful Task 20 cutover;
- migration prerequisite, partial-install, uniqueness, FK, CHECK, safe rerun,
  and read-only verification structure;
- full existing PHP, Combat HUD, and Exploration HUD regressions.

## 22. Non-goals

Task 17 does not implement migration SQL, PHP services/endpoints, generation,
equipment behavior, UI behavior, art, crafting, vendors, trading, shared stash
or account inventory, auction house, sockets/gems, set items, legendary items,
final balance tables, or production deployment/database changes.

## 23. Decisions and review questions

This design resolves all core rules needed by Tasks 18–20. No user decision is
required before review. Later product decisions intentionally deferred are:
final catalogue values and drop/rarity weights; bows/ranged combat;
two-handed/dual-wield rules; item requirements; and activation of Block,
critical, dodge, life-on-hit, and status-effect formulas.
