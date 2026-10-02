# Item System Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Build persistent Champion inventory, exactly-once physical victory drops, and equipment that authoritatively affects later combat.

**Architecture:** Three approval-gated tasks add schema and behavior in dependency order: inventory ownership, generation/drop claims, then equipment. Focused repositories and services own transactions; server projectors expose allowlisted state; the existing combat provider boundary preserves offensive snapshots and resolution-time defense.

**Tech Stack:** PHP 8 with PDO, MariaDB/InnoDB migrations, vanilla JavaScript/CSS, dependency-free PHP test runner, Node fake-DOM tests.

**Spec:** `docs/superpowers/specs/2026-10-02-item-system-design.md`

## Global Constraints

- Reinspect migration numbering before each implementation task; at Task 17 review time the next migrations are `005`, `006`, and `007`.
- Do not apply any migration to production without separate explicit approval.
- Use the selected session Champion; inventory is never account-shared.
- The client sends intent only and never supplies item stats, rolls, rarity, level, ownership, chance, or combat effects.
- All mutation endpoints require authentication, selected Champion, CSRF, exact input allowlists, and UUIDv4 request tokens.
- Preserve Champion → Account combat mutex → Encounter → item/drop/action rows lock order wherever combat exclusion participates.
- No equipment mutation is allowed while an encounter is `active` or `victory_loot`, or while the Champion is DEAD.
- Equipment never refills HP or Mana; increasing a maximum preserves current value and decreasing it clamps downward.
- Use no framework, ORM, Composer package, npm package, or new major dependency.
- Do not commit, push, deploy, or apply SQL without the approval applicable to that implementation task.
- Each task stops at `READY FOR TESTING`; Tasks 19 and 20 do not begin automatically.

## Review Focus

- A response lost after a committed claim must replay success without a second owner change; Task 19 tests token replay after reloading persisted rows.
- Close/Continue racing an explicit claim must end with one owner and one closed encounter; Task 19 tests both transaction orders.
- An item whose definition key is retired must remain readable and usable from its immutable snapshots; Tasks 18 and 20 test inactive-definition instances.
- Maximum-resource swaps near zero and above the new maximum must never heal or underflow; Task 20 tests increase, decrease, and atomic swap cases.
- Two tabs equipping different items to the same slot must serialize to one valid row and preserve both item owners; Task 20 tests the database uniqueness/race result.

---

## Task 18 — Persistent Inventory Foundation

### Scope and acceptance boundary

Task 18 creates persistent base definitions and Champion-owned immutable item
instances, a durable mutation-token foundation, a read-only inventory service,
and the first real 25-item paged inventory display. Test fixtures may seed
owned instances directly through fakes or reviewed SQL fixtures; there is no
random generation or gameplay acquisition yet.

Acceptance requires owner-only durable inventory, stable ordering, safe public
projection, empty/multi-page rendering, refresh/login persistence, and a
reviewed but unapplied Migration 005.

### Task 18.1: Freeze Migration 005 structure with RED tests

**Files:**
- Create: `tests/ItemInventoryMigrationTest.php`
- Modify: `tests/run.php`
- Create later in this task: `database/migrations/005_item_inventory_foundation.sql`
- Create later in this task: `database/migrations/005_item_inventory_foundation_verify.sql`

**Interfaces:**
- Consumes: current `schema_migrations` and `characters.id INT UNSIGNED` conventions.
- Produces: tested SQL contract for `item_definitions`, `character_items`, and `item_mutation_requests`.

- [ ] **Step 1: Write failing migration-structure tests**

  Add tests named for prerequisites/rerun rejection, live parent-key preflight,
  table/FK/CHECK/unique/index requirements, seeded definition identity,
  non-destructive behavior, one migration record, and read-only verification.
  Assert Migration 005 requires `004_combat_enemy_ai_initialization`, rejects
  any partial target table set without its record, and never updates Champion
  resources.

- [ ] **Step 2: Run the focused RED test**

  Run: `php tests/run.php`
  Expected: FAIL because Migration 005 and verification SQL do not exist.

- [ ] **Step 3: Create Migration 005 and verification SQL**

  Implement the three spec tables with exact ownership nullability, immutable
  snapshot columns, rarity/level checks, `(id, character_id)` uniqueness, and
  `(character_id, request_token)` uniqueness. Seed only reviewed initial base
  definitions. Follow the stored-procedure preflight convention and add a
  separate read-only metadata/count verification file.

- [ ] **Step 4: Run GREEN and SQL text safety checks**

  Run: `php tests/run.php`
  Expected: all tests pass, including the new Migration 005 cases.

- [ ] **Step 5: Review Migration 005 without applying it**

  Check the diff against the spec. Confirm no SQL command was run against a
  live database and record `NOT APPLIED` for handoff.

### Task 18.2: Add inventory domain projection with TDD

**Files:**
- Create: `ascii-quest/lib/ItemRepository.php`
- Create: `ascii-quest/lib/ItemProjector.php`
- Create: `ascii-quest/lib/InventoryService.php`
- Create: `ascii-quest/lib/ItemBootstrap.php`
- Create: `tests/InventoryServiceTest.php`
- Modify: `tests/run.php`

**Interfaces:**
- Consumes: `InventoryService::state(int $userId, int $characterId, int $page): array`.
- Produces: public `{items, pagination, equipment_locked, mutation_disabled_reason}` state and repository methods that always scope by user and Champion.

- [ ] **Step 1: Write failing owner/projection/paging tests**

  Cover correct owner, wrong account, another Champion on the same account,
  DEAD read-only state, empty state, 26-item pagination, stable newest-claim
  then descending-ID ordering, inactive definition readability, and omission
  of owner IDs, request rows, hidden snapshot internals, and unrelated items.

- [ ] **Step 2: Run the focused RED tests**

  Run: `php tests/run.php`
  Expected: FAIL because item domain classes are absent.

- [ ] **Step 3: Implement `ItemProjector::projectItem(array $row, array $affixes = [], ?string $equippedSlot = null): array`**

  Return only the spec allowlist. Build no names client-side. Treat repository
  rows as untrusted until scalar/category/rarity/slot values validate.

- [ ] **Step 4: Implement `ItemRepository` read queries**

  Add exact user/Champion selection, count, and paged unequipped inventory
  reads. Bind limit/offset as integers. Do not expose a query that can list all
  users' items.

- [ ] **Step 5: Implement `InventoryService::state()` and bootstrap**

  Validate positive page input, resolve selected ownership, calculate 25-item
  pagination, and include server-derived mutation lock reason. Task 18 exposes
  no mutation method.

- [ ] **Step 6: Run GREEN and the permanent suite**

  Run: `php tests/run.php`
  Expected: all tests pass.

### Task 18.3: Add the inventory read endpoint securely

**Files:**
- Create: `ascii-quest/inventory_state.php`
- Create: `tests/ItemEndpointSecurityTest.php`
- Modify: `tests/run.php`

**Interfaces:**
- Consumes: GET `page` as a strict positive decimal integer and session user/Champion.
- Produces: no-store JSON inventory projection; `401`, `404`, `422`, and `500` use controlled generic messages.

- [ ] **Step 1: Write failing endpoint contract tests**

  Cover unauthenticated/no-selected-Champion requests, non-GET methods,
  unknown keys, array/non-decimal/zero/overflow page values, cross-owner
  selection, no database call before early rejection, and safe JSON headers.

- [ ] **Step 2: Run RED**

  Run: `php tests/run.php`
  Expected: FAIL because `inventory_state.php` is absent.

- [ ] **Step 3: Implement the endpoint**

  Follow existing combat JSON/no-cache conventions. Pass only session-derived
  identities and the validated page to `InventoryService`.

- [ ] **Step 4: Run GREEN and lint**

  Run: `php tests/run.php && php -l ascii-quest/inventory_state.php`
  Expected: all tests pass; lint reports no syntax errors.

### Task 18.4: Replace the inventory placeholder with a read-only UI

**Files:**
- Create: `ascii-quest/js/item_hud.js`
- Modify: `ascii-quest/game.php`
- Modify: `ascii-quest/css/style.css`
- Create: `tests/ItemHudTest.js`

**Interfaces:**
- Consumes: embedded initial inventory projection and `inventory_state.php?page=N`.
- Produces: `ItemHud.createController(...)`, 25-cell page rendering, pointer selection/details, and previous/next paging.

- [ ] **Step 1: Write failing fake-DOM tests**

  Assert empty state, item text via `textContent`, rarity class allowlist,
  selected details, exactly 25 cells per page, paging request, server order,
  network error state, and absence of equip/claim actions in Task 18.

- [ ] **Step 2: Run RED**

  Run: `node tests/ItemHudTest.js`
  Expected: FAIL because the module/UI does not exist.

- [ ] **Step 3: Implement the focused UMD item module**

  Keep fetch/render/controller logic outside `game.php`, accept injected fetch
  in tests, render all item strings safely, and keep interactions pointer-based.

- [ ] **Step 4: Wire `game.php` and CSS**

  Replace “Visual shell only” with the real grid, details, and paging controls.
  Preserve Equipment and Loadout placeholders and the existing dark monospaced
  visual language. Add filemtime cache versioning for the new script.

- [ ] **Step 5: Run UI GREEN and syntax checks**

  Run: `node tests/ItemHudTest.js && node --check ascii-quest/js/item_hud.js && php -l ascii-quest/game.php`
  Expected: all tests/checks pass.

### Task 18.5: Full Task 18 gate

**Files:** All Task 18 files.

**Interfaces:** Produces the reviewed inventory foundation consumed by Task 19.

- [ ] **Step 1: Run all automated regressions**

  Run: `php tests/run.php && node tests/ItemHudTest.js && node tests/CombatHudTest.js && node tests/ExplorationHudTest.js`
  Expected: zero failures.

- [ ] **Step 2: Run syntax and diff checks**

  Run PHP lint on every changed PHP file, Node syntax on changed JS, then
  `git diff --check`, `git status --short --branch`, and `git diff --stat`.
  Expected: zero errors and only Task 18 scope.

- [ ] **Step 3: Manual review checklist**

  With an approved test database only after migration approval: verify empty,
  one-item, and multi-page inventories; refresh and logout/login persistence;
  another Champion sees none of the first Champion's items; no production SQL.

- [ ] **Step 4: Stop for review**

  Report files, exact test counts, Migration 005 `NOT APPLIED`, manual checks,
  and `READY FOR TESTING`. Do not begin Task 19.

**Task 18 explicit non-goals:** random generation, affixes, physical drops,
claim, equipment relations/effects, deletion/discard, finite capacity, vendors,
crafting, trading, or shared inventory.

---

## Task 19 — Item Generation + Physical Drops

### Scope and acceptance boundary

Task 19 adds reviewed affix data, an injected server random source, source-level
generation, one durable outcome per victory reward slot, explicit claim, and
Close/Continue auto-claim. It does not equip or apply items.

### Task 19.1: Freeze Migration 006 with RED tests

**Files:**
- Create: `tests/ItemDropMigrationTest.php`
- Modify: `tests/run.php`
- Create later: `database/migrations/006_item_generation_drops.sql`
- Create later: `database/migrations/006_item_generation_drops_verify.sql`

**Interfaces:**
- Consumes: Migration 005 tables and combat Migration 004.
- Produces: affix, roll, drop-outcome, and encounter source/generation schema.

- [ ] **Step 1: Write failing SQL structure tests**

  Pin all five affix/drop tables and two encounter columns, composite tier FK,
  category/family rules, one prefix/suffix and family uniqueness, unique
  encounter/reward slot and item linkage, hidden basis-point bounds, legacy
  null behavior, partial-install rejection, one migration record, and read-only
  verification. Require Migration 005.

- [ ] **Step 2: Run RED**

  Run: `php tests/run.php`
  Expected: FAIL because Migration 006 files are absent.

- [ ] **Step 3: Create Migration 006 and verification SQL**

  Seed reviewed active affixes/tiers/rules separately from instance rolls.
  Leave existing encounter source/generation markers null and do not generate
  or backfill any item/drop row.

- [ ] **Step 4: Run GREEN and inspect without applying**

  Run: `php tests/run.php`
  Expected: all tests pass. Record Migration 006 `NOT APPLIED`.

### Task 19.2: Implement deterministic item generation with TDD

**Files:**
- Create: `ascii-quest/lib/ItemRandomSource.php`
- Create: `ascii-quest/lib/SystemItemRandomSource.php`
- Create: `ascii-quest/lib/ItemDefinitionRegistry.php`
- Create: `ascii-quest/lib/ItemGenerator.php`
- Create: `tests/ItemGeneratorTest.php`
- Modify: `tests/run.php`

**Interfaces:**
- Consumes: `ItemGenerator::generate(int $itemLevel, ItemRandomSource $random): array` and repository-supplied active catalogue.
- Produces: a validated immutable generation command containing definition, rarity, base snapshots, affix tier/value rows, and server-built name.

- [ ] **Step 1: Write failing generator tests**

  Cover eligible base/item level, Normal/Magic/Rare affix counts, one prefix
  and suffix for Rare, family/category restrictions, mutually exclusive
  families, inclusive tier roll range, fallback rarity, safe name spacing,
  deterministic random call order, unsupported special exclusion, and no
  Champion-level input.

- [ ] **Step 2: Run RED**

  Run: `php tests/run.php`
  Expected: FAIL because generation classes are absent.

- [ ] **Step 3: Implement registry validation and random interface**

  Validate catalogue rows before selection. The production random source uses
  `random_int`; tests inject queued values. No seed is persisted or projected.

- [ ] **Step 4: Implement `ItemGenerator::generate()`**

  Apply source-level eligibility, configured rarity weights, required affix
  positions, category/family exclusions, tier selection, immutable values, and
  name building. Return data only; persistence remains repository-owned.

- [ ] **Step 5: Run GREEN**

  Run: `php tests/run.php`
  Expected: all tests pass.

### Task 19.3: Add exactly-once victory generation and claim transactions

**Files:**
- Modify: `ascii-quest/config/combat.php`
- Modify: `ascii-quest/lib/CombatDefinitionRegistry.php`
- Modify: `ascii-quest/lib/CombatRepository.php`
- Modify: `ascii-quest/lib/CombatService.php`
- Modify: `ascii-quest/lib/CombatSynchronizer.php`
- Modify: `ascii-quest/lib/CombatStateProjector.php`
- Modify: `ascii-quest/lib/ItemRepository.php`
- Modify: `ascii-quest/lib/InventoryService.php`
- Modify: `ascii-quest/lib/ItemBootstrap.php`
- Create: `tests/ItemDropServiceTest.php`
- Modify: `tests/run.php`

**Interfaces:**
- Consumes: encounter `loot_source_level`, configured reward slots/base chance, server-derived `loot_chance`, and item generator.
- Produces: `InventoryService::claim(int $userId, int $characterId, int $dropId, string $requestToken): array`; victory projection `physical_drops`; close auto-claim.

- [ ] **Step 1: Write failing drop/claim/concurrency tests**

  Cover source snapshot on new encounters, old encounter no-retroactivity,
  effective chance formula, persisted none/item outcome, duplicate transition,
  response loss, refresh, two tabs, complete immutable roll, owner null before
  claim, correct Champion claim, cross-user/Champion denial, DEAD denial,
  replay, collision, close auto-claim, claim/close race orders, rollback on
  failed claim, and hidden fields absent from projection.

- [ ] **Step 2: Run RED**

  Run: `php tests/run.php`
  Expected: FAIL on missing drop behavior.

- [ ] **Step 3: Extend encounter creation and victory processing**

  Snapshot the configured positive source level for new encounters. At the
  first victory transition, insert one locked outcome per reward slot and, for
  an item outcome, persist item plus affixes in the same transaction. Treat the
  unique reward-slot key as the final race guard.

- [ ] **Step 4: Implement claim command receipts and transfer**

  Canonical fingerprint includes command, drop ID, and selected Champion.
  Lock and validate encounter ownership/life/victory status, outcome, item,
  receipt, and current owner; atomically set item/drop claim fields. Replay a
  completed match and reject a collision.

- [ ] **Step 5: Extend Close/Continue**

  Under the existing close transaction, enumerate server-side unclaimed item
  outcomes, transfer each exactly once, then close. On any failure roll back
  all claims and the close. Preserve terminal close replay.

- [ ] **Step 6: Project only public drop/item data**

  Replace the empty `physical_drops` list with public projections and claim
  state. Exclude chance, roll, weights, ownership keys, and request receipts.

- [ ] **Step 7: Run GREEN**

  Run: `php tests/run.php`
  Expected: all tests pass.

### Task 19.4: Add claim endpoint and physical-drop UI

**Files:**
- Create: `ascii-quest/item_claim.php`
- Modify: `ascii-quest/game.php`
- Modify: `ascii-quest/js/combat_hud.js`
- Modify: `ascii-quest/css/style.css`
- Modify: `tests/ItemEndpointSecurityTest.php`
- Modify: `tests/CombatHudTest.js`

**Interfaces:**
- Consumes: POST exact JSON `{csrf_token, drop_id, request_token}`.
- Produces: sanitized combat loot state with stable claim result; Close submits no client item list.

- [ ] **Step 1: Write endpoint RED tests**

  Cover method/session/CSRF, exact key allowlist, positive integer ID, UUIDv4,
  generic cross-owner response, replay, collision, and rejection before DB for
  malformed intent.

- [ ] **Step 2: Write HUD RED tests**

  Cover name/rarity/base/affix/item-level rendering, safe text, claim pending,
  claimed/disabled state, request token reuse until response, retry after lost
  response, refresh identity, no-drop message, and Close auto-claim copy.

- [ ] **Step 3: Run RED**

  Run: `php tests/run.php && node tests/CombatHudTest.js`
  Expected: failures for the new endpoint and UI contract.

- [ ] **Step 4: Implement endpoint and pointer UI**

  Follow strict existing combat endpoint conventions. Render each projected
  item in the current victory row/panel and update the inventory view after a
  successful claim or close without trusting client-authored item data.

- [ ] **Step 5: Run GREEN and syntax checks**

  Run: `php tests/run.php && node tests/CombatHudTest.js && node --check ascii-quest/js/combat_hud.js && php -l ascii-quest/item_claim.php && php -l ascii-quest/game.php`
  Expected: zero failures/errors.

### Task 19.5: Full Task 19 gate

- [ ] **Step 1: Run all suites**

  Run: `php tests/run.php && node tests/ItemHudTest.js && node tests/CombatHudTest.js && node tests/ExplorationHudTest.js`
  Expected: zero failures.

- [ ] **Step 2: Run changed-file lint and diff checks**

  Run all changed PHP through `php -l`, changed JS through `node --check`, then
  `git diff --check`, status, and diff stat. Expected: only Task 19 scope.

- [ ] **Step 3: Manual two-tab/reload acceptance**

  On an approved migrated test environment, record pre/post encounter, drop,
  item, affix, owner, Gold, and EXP rows. Verify same visible drop across
  refresh/login, explicit claim, close auto-claim, two-tab retry, no duplicate
  Gold/EXP/item, and source-level tier bounds.

- [ ] **Step 4: Stop for review**

  Report exact counts, Migration 006 `NOT APPLIED` unless separately approved,
  manual results, and `READY FOR TESTING`. Do not begin Task 20.

**Task 19 explicit non-goals:** equipment, combat item effects, final drop or
rarity balance, retroactive old-encounter drops, rerolling, disposal, vendors,
crafting, trading, legendary/set items.

---

## Task 20 — Equipment System

### Scope and acceptance boundary

Task 20 adds one authoritative relation for the ten slots, retry-safe atomic
equip/unequip/swap, pointer UI, item-aware derived stats, and a production
equipment provider. It activates only the modifier subset in the spec.

### Task 20.1: Freeze Migration 007 with RED tests

**Files:**
- Create: `tests/EquipmentMigrationTest.php`
- Modify: `tests/run.php`
- Create later: `database/migrations/007_character_equipment.sql`
- Create later: `database/migrations/007_character_equipment_verify.sql`

**Interfaces:**
- Consumes: Migration 006 and `character_items(id, character_id)` uniqueness.
- Produces: ownership-bound, one-item-per-slot `character_equipment` schema.

- [ ] **Step 1: Write failing schema tests**

  Assert Migration 006 prerequisite, preflight/rerun/partial rejection, ten-slot
  CHECK, PK Champion/slot, unique item ID, composite ownership FK, delete rules,
  indexes, nullable indexed `combat_actions.snapshot_weapon_item_id` with a
  restrictive item FK, no default equipment rows/resource changes, one
  migration record, and read-only verification.

- [ ] **Step 2: Run RED**

  Run: `php tests/run.php`
  Expected: FAIL because Migration 007 files are absent.

- [ ] **Step 3: Implement Migration 007 and verification SQL**

  Follow existing procedure convention. Create no equipment for existing
  Champions and do not alter HP/Mana.

- [ ] **Step 4: Run GREEN and inspect without applying**

  Run: `php tests/run.php`
  Expected: all tests pass. Record Migration 007 `NOT APPLIED`.

### Task 20.2: Extend CharacterStats through an equipment modifier boundary

**Files:**
- Modify: `ascii-quest/lib/CharacterStats.php`
- Create: `ascii-quest/lib/EquipmentStatAggregator.php`
- Create: `tests/EquipmentStatTest.php`
- Modify: `tests/CharacterStatsTest.php`
- Modify: `tests/run.php`

**Interfaces:**
- Consumes: `EquipmentStatAggregator::aggregate(array $items): array` canonical modifier map.
- Produces: `CharacterStats::calculate(array $character, array $equipmentModifiers = []): array`, preserving every existing one-argument result.

- [ ] **Step 1: Write failing aggregation/stat tests**

  Cover flat and additive-percent stacking, five main stats, maximum resources,
  toughness/resistances, attack/cast/block basis points, critical/dodge/life-on-
  hit aggregation, unknown modifier rejection, deterministic item order, and
  byte-for-byte-equivalent legacy results for an empty modifier map.

- [ ] **Step 2: Run RED**

  Run: `php tests/run.php`
  Expected: FAIL on missing aggregator/two-argument behavior.

- [ ] **Step 3: Implement canonical aggregation**

  Validate modifier keys/operations and sum signed integers with explicit
  bounds. Mark deferred values in output without activating combat formulas.

- [ ] **Step 4: Extend `CharacterStats::calculate()`**

  Apply item main-stat and direct modifiers once, retain centralized caps, and
  expose rate factors from basis-point totals. The default empty map must
  preserve current callers and tests.

- [ ] **Step 5: Run GREEN**

  Run: `php tests/run.php`
  Expected: all tests pass.

### Task 20.3: Implement equipment transactions and resource clamping

**Files:**
- Create: `ascii-quest/lib/EquipmentRepository.php`
- Create: `ascii-quest/lib/EquipmentService.php`
- Modify: `ascii-quest/lib/ItemProjector.php`
- Modify: `ascii-quest/lib/InventoryService.php`
- Modify: `ascii-quest/lib/ItemBootstrap.php`
- Create: `tests/EquipmentServiceTest.php`
- Modify: `tests/run.php`

**Interfaces:**
- Produces:
  - `EquipmentService::equip(int $userId, int $characterId, int $itemId, string $slot, string $requestToken): array`
  - `EquipmentService::unequip(int $userId, int $characterId, int $itemId, string $requestToken): array`
  - inventory state extended with authoritative equipment projection.

- [ ] **Step 1: Write failing transaction tests**

  Cover every compatible slot, wrong slot, item not owned, cross-Champion and
  cross-user, one ring/charm, shield off-hand, duplicate item, empty equip,
  occupied atomic swap, already-equipped replay/no-op, stale unequip, UUID
  replay/collision, two-tab same-slot race, DEAD lock, active and victory-loot
  locks, closed allowance, inactive-definition existing item, and rollback.

- [ ] **Step 2: Add failing HP/Mana invariant tests**

  Assert maximum increase preserves current values; maximum decrease clamps;
  swapping two resource items computes the final maxima once and never uses an
  intermediate free heal; zero stays zero; values cannot become negative.

- [ ] **Step 3: Run RED**

  Run: `php tests/run.php`
  Expected: FAIL because equipment domain classes are absent.

- [ ] **Step 4: Implement repository lock/query methods**

  Lock selected owned Champion first, Account combat mutex and unresolved
  encounter next, command row/token, item/equipment target rows afterward.
  Provide current equipped-item aggregate reads and compare-and-update
  HP/Mana methods.

- [ ] **Step 5: Implement equip/unequip services**

  Validate UUID, fingerprint, life/combat state, ownership, and slot. Perform
  replace as one transaction, compute final modifiers/maxima, clamp only down,
  persist receipt and projection, and rely on schema uniqueness as final guard.

- [ ] **Step 6: Extend projections**

  Separate unequipped inventory from ten equipment slots while retaining the
  shared item allowlist and equipped flags/slot. Never expose another owner's
  rows or command fingerprints.

- [ ] **Step 7: Run GREEN**

  Run: `php tests/run.php`
  Expected: all tests pass.

### Task 20.4: Replace the production prototype combat provider

**Files:**
- Create: `ascii-quest/lib/PersistentCombatEquipmentProvider.php`
- Modify: `ascii-quest/lib/CombatEquipmentProvider.php` only if additive snapshot fields require a documented interface comment/signature refinement
- Modify: `ascii-quest/lib/CombatBootstrap.php`
- Modify: `ascii-quest/lib/CombatService.php`
- Modify: `ascii-quest/lib/CombatActionResolver.php`
- Modify: `ascii-quest/lib/CombatPlayerActionEvaluator.php`
- Modify: `ascii-quest/lib/CombatRepository.php`
- Modify: `database/migrations/007_character_equipment.sql`
- Modify: `tests/CombatServiceTest.php`
- Create: `tests/EquipmentCombatIntegrationTest.php`
- Modify: `tests/run.php`

**Interfaces:**
- Consumes: equipped items and aggregated stats.
- Produces: existing `offensiveSnapshot(array $lockedCharacter, string $attackKey): array` and `currentDefense(array $lockedCharacter): array` with real data; action-start effective duration is persisted.

- [ ] **Step 1: Write failing combat integration tests**

  Cover equipped weapon identity/type/base+flat+percent damage; Attack Rate
  weapon duration; Cast Rate skill duration; immutable damage/duration/crit
  snapshot after later equipment change; current toughness/resistance read at
  enemy resolution; Block Rate no effect; critical/dodge/life-on-hit/status
  no active resolution; and no-weapon controlled unavailable state rather than
  fallback prototype damage.

- [ ] **Step 2: Run RED**

  Run: `php tests/run.php`
  Expected: FAIL because production bootstrap still uses the prototype.

- [ ] **Step 3: Implement `PersistentCombatEquipmentProvider`**

  Load exactly the selected Champion's equipped items, aggregate once per
  provider call, require one weapon for weapon action, feed modifiers through
  `CharacterStats`, and return only snapshot/current-defense fields.

- [ ] **Step 4: Persist effective duration and active offensive values at start**

  Apply Attack/Cast formula before turn-time validation and encode the
  effective action duration in the existing start/resolve timeline positions.
  Store the equipped weapon instance in
  `combat_actions.snapshot_weapon_item_id`; retain existing snapshot columns
  for resolved damage/type/critical inputs. Resolve future player offense only
  from those persisted values and add no duplicate duration or rate column.

- [ ] **Step 5: Wire production bootstrap**

  Instantiate the persistent provider from the real PDO/repository. Keep the
  prototype class only for explicit legacy test fixtures; production must not
  silently supply a weapon.

- [ ] **Step 6: Run GREEN and focused regressions**

  Run: `php tests/run.php`
  Expected: all tests pass, including snapshot/current-defense regressions.

### Task 20.5: Add mutation endpoints and pointer equipment UI

**Files:**
- Create: `ascii-quest/equip_item.php`
- Create: `ascii-quest/unequip_item.php`
- Modify: `ascii-quest/game.php`
- Modify: `ascii-quest/js/item_hud.js`
- Modify: `ascii-quest/css/style.css`
- Modify: `tests/ItemEndpointSecurityTest.php`
- Modify: `tests/ItemHudTest.js`

**Interfaces:**
- Equip POST: exact JSON `{csrf_token, item_id, slot, request_token}`.
- Unequip POST: exact JSON `{csrf_token, item_id, request_token}`.
- Produces: refreshed sanitized inventory/equipment state and visible disabled reasons.

- [ ] **Step 1: Write endpoint RED tests**

  Cover session/method/CSRF, exact keys, scalar IDs/slots/tokens, ten-slot
  allowlist, early rejection, generic ownership failure, replay/collision, and
  domain-to-status mapping.

- [ ] **Step 2: Write UI RED tests**

  Cover populated ten-slot paper doll, one ring/charm, item details, compatible
  Equip/Swap, Unequip, pending single-submit, token reuse on retry, post-success
  render, DEAD/combat disabled reason, pointer-only behavior, safe text, and
  no drag/drop requirement.

- [ ] **Step 3: Run RED**

  Run: `php tests/run.php && node tests/ItemHudTest.js`
  Expected: failures for missing endpoint/UI behavior.

- [ ] **Step 4: Implement strict endpoints**

  Follow the existing JSON endpoint security contract and delegate all
  authority to `EquipmentService`.

- [ ] **Step 5: Activate existing paper-doll markup and item controls**

  Keep the exact ten slots and current style. Use selection/action controls,
  `textContent`, server-compatible slot metadata, and visible lock messages.

- [ ] **Step 6: Run GREEN and syntax checks**

  Run: `php tests/run.php && node tests/ItemHudTest.js && node tests/CombatHudTest.js && node tests/ExplorationHudTest.js`
  Expected: zero failures.

  Run: PHP lint for changed endpoints/PHP and `node --check ascii-quest/js/item_hud.js`
  Expected: zero syntax errors.

### Task 20.6: Full Task 20 and milestone gate

- [ ] **Step 1: Run all permanent suites and record exact counts**

  Run: `php tests/run.php && node tests/ItemHudTest.js && node tests/CombatHudTest.js && node tests/ExplorationHudTest.js`
  Expected: zero failures in every suite.

- [ ] **Step 2: Run all changed-file syntax and repository checks**

  Lint changed PHP, syntax-check changed JS, then run `git diff --check`,
  `git status --short --branch`, `git diff --stat`, and inspect the complete
  diff. Expected: only Task 20 scope.

- [ ] **Step 3: Manual end-to-end acceptance on an approved migrated test environment**

  Verify claim → inventory → equip → new fight; future weapon snapshot uses
  the item; an already-started action retains old values; incoming damage uses
  current defense; combat/victory/DEAD equipment attempts fail; HP/Mana do not
  refill; refresh/logout/login retain ownership/equipment; two tabs cannot
  duplicate a slot or item.

- [ ] **Step 4: Stop for review**

  Report files, exact counts, migration status, manual evidence, production
  changes, and `READY FOR TESTING`. Do not deploy, apply production SQL, push,
  or expand into deferred mechanics.

**Task 20 explicit non-goals:** live combat swapping, two-handed weapons, dual
wield, bows/ranged behavior, class/stat/level requirements, final Block/
critical/dodge/life-on-hit/status formulas, loadout/potion item integration,
drag-and-drop requirement, crafting, vendors, trading, stash, sockets, sets,
or legendary items.
