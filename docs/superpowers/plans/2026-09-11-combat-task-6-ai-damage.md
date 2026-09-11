# Combat Task 6 AI and Damage Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Add the approved Cave Brute skill-first AI and server-authoritative player/enemy damage resolution while preserving the durable capped combat timeline and every Task 1–5 contract.

**Architecture:** Migration 004 adds one nullable encounter marker that distinguishes legacy encounters from Task-6-native encounters. A pure `CaveBrutePolicy` chooses an action or a precise wait position; focused defense resolvers calculate provisional Task 6 damage; `CombatSynchronizer` processes one chronological event position at a time under the existing transaction and repository locks. The browser receives only a sanitized view of an already-started enemy action and never receives hidden AI, cooldown, Block-roll, snapshot, or defense inputs.

**Tech Stack:** PHP, MariaDB/InnoDB, PDO, native PHP test runner; no framework, Composer package, JavaScript, CSS, or new dependency.

**Spec:** `docs/superpowers/specs/2026-08-31-combat-milestone-1-design.md`

## Global Constraints

- Read `AGENTS.md`, the specification, and this plan before each execution task.
- Implement only Combat Milestone 1 Task 6. Do not begin Task 7 or any later milestone.
- Do not modify JavaScript, CSS, maps, exploration controls, item/equipment persistence, or `CharacterStats` formulas.
- Migration 004 may add exactly `enemy_ai_initialized_timeline_ms BIGINT UNSIGNED NULL`; no other schema field is approved.
- Create and review Migration 004 locally, but do not apply it without a separate explicit live-integration approval.
- Existing rows remain `NULL`; never backfill legacy encounters to `0`.
- New Task-6 encounters persist both `enemy_ai_initialized_timeline_ms = 0` and `next_enemy_decision_timeline_ms = 0`.
- Preserve `TURN_DURATION_SECONDS = 10` and `MAX_DISCONNECTED_CATCHUP_SECONDS = 5` through the existing centralized combat configuration/registry.
- Preserve the authoritative lock order: selected/owned Champion, owning Account combat mutex, active Encounter, then Action/Event detail rows.
- `CombatRandomSource` is the only random boundary. Production uses `SystemCombatRandomSource`; tests use a deterministic fake.
- Enemy cooldowns, AI initialization/scheduling, Block chance/roll, defense inputs, and private snapshots remain server-only.
- Player attacks use their stored Task 5 `snapshot_base_damage`; Task 6 deliberately ignores Accuracy, Critical Chance, Critical Damage, and Dodge.
- Incoming enemy damage calls `CombatEquipmentProvider::currentDefense()` at resolution, never at enemy-action start.
- HP is clamped to zero and never heals as a side effect. Task 6 does not issue rewards, enter loot state, persist permanent death, set `died_at`, or invoke Slayer logic.
- No new action starts for an actor whose HP is zero; no player action starts against a zero-HP Cave Brute.
- Each task follows RED → confirm the intended failure → minimal GREEN → full regression → focused review checkpoint.
- Do not commit, push, deploy, or access the live database unless a later instruction explicitly approves that exact operation.

---

## Current Architecture and File Map

### Existing interfaces to preserve

```php
interface CombatRandomSource
{
    public function token(int $bytes = 32): string;
    public function integer(int $minimum, int $maximum): int;
}

interface CombatEquipmentProvider
{
    public function offensiveSnapshot(array $lockedCharacter, string $attackKey): array;
    public function currentDefense(array $lockedCharacter): array;
}
```

`CombatService::state()` and `CombatService::startPlayerAction()` already acquire the Champion, Account, and Encounter locks through `CombatAccessGuard`, synchronize before command validation, persist optimistic encounter versions, and project safe state. `CombatSynchronizer` already owns wall-clock capping, logical timeline advancement, and resolution-before-Turn-boundary ordering. `CombatRepository` already enforces Encounter-before-Action access and the `(encounter_id, actor, active_slot)` database constraint prevents two pending actions for one actor.

### Create during Task 6 execution

- `database/migrations/004_combat_enemy_ai_initialization.sql` — one-column additive migration with preflight and one-time migration record.
- `database/migrations/004_combat_enemy_ai_initialization_verify.sql` — read-only schema and legacy-row verification.
- `ascii-quest/lib/CaveBrutePolicy.php` — pure skill-first action/wait/stop decision.
- `ascii-quest/lib/EnemyDefenseResolver.php` — automatic Cave Brute Block contract.
- `ascii-quest/lib/PrototypeEnemyDefenseResolver.php` — one cryptographic/deterministic Block roll using configured 20%/50% values.
- `ascii-quest/lib/ChampionDamageResolver.php` — incoming enemy-damage contract.
- `ascii-quest/lib/PrototypeChampionDamageResolver.php` — approved Toughness/Fire Resistance formulas only.
- `ascii-quest/lib/CombatActionResolver.php` — coordinates exactly-once pending-action result persistence and returns updated in-memory Champion/Encounter state.
- `tests/CombatMigration004Test.php` — Migration 004 structure, safety, and verification-file contracts.
- `tests/CombatTask6Test.php` — pure policy/resolver and chronological service/synchronizer behavior.

### Modify during Task 6 execution

- `tests/run.php` — register the two focused Task 6 test arrays.
- `tests/CombatRepositoryTest.php` — extend `FakeCombatPdo` and repository SQL/rollback coverage.
- `tests/CombatServiceTest.php` — preserve Task 4/5 synchronization, service, and projection regressions after the synchronization result gains current Champion state.
- `tests/CombatSecurityTest.php` — assert the expanded enemy projection still excludes private data.
- `ascii-quest/lib/CombatRepository.php` — marker, chronological action, action result, encounter HP/schedule, and Champion HP persistence.
- `ascii-quest/lib/CombatSynchronizer.php` — capped, position-by-position event loop.
- `ascii-quest/lib/CombatService.php` — new encounter marker, synchronized Champion projection, and zero-HP command rejection.
- `ascii-quest/lib/CombatStateProjector.php` — one allowlisted active enemy action.
- `ascii-quest/lib/CombatBootstrap.php` — explicit production resolver/policy/random wiring and test injection seams.
- `docs/tasks/current-task.md` — only after local Task 6 verification, record the checkpoint without claiming deployment.

### Deliberately unchanged

- `ascii-quest/config/combat.php`: it already contains Cave Brute Action 2, `smash` 1.5s/3s/18 Physical, `fire_slam` 2s/6s/24 Fire, and Block 20%/50%.
- `ascii-quest/lib/CombatRandomSource.php` and `SystemCombatRandomSource.php`: the required interface and cryptographic `random_int()` implementation already exist.
- Migration 003, CharacterStats, endpoints, JavaScript, CSS, maps, rewards, death, potion, player Block, and combat HUD files.

## Task 6 Domain Contracts

The implementation tasks use these exact contracts:

```php
final class CaveBrutePolicy
{
    public function __construct(private CombatTurnEngine $turnEngine) {}

    /**
     * @return array{decision:'start', definition_key:string}
     *     |array{decision:'wait', next_timeline_ms:int}
     *     |array{decision:'stop'}
     */
    public function decide(
        array $encounter,
        int $championCurrentHp,
        array $enemyDefinition,
        array $lockedActionHistory,
        array $turnState,
        int $timelineMs,
    ): array;
}

interface EnemyDefenseResolver
{
    /** @return array{blocked:bool,incoming_damage:int,prevented_damage:int,applied_damage:int} */
    public function resolve(array $playerAction, array $enemyDefinition): array;
}

interface ChampionDamageResolver
{
    /** @return array{incoming_damage:int,prevented_damage:int,applied_damage:int} */
    public function resolve(array $enemyAction, array $currentDefense): array;
}

final class CombatActionResolver
{
    /**
     * @return array{encounter:array<string,mixed>,character:array<string,mixed>}
     */
    public function resolvePending(
        array $encounter,
        array $lockedCharacter,
        array $lockedAction,
    ): array;
}
```

`CombatSynchronizer::synchronize()` will accept the locked Character and return both current aggregates:

```php
/** @return array{encounter:array<string,mixed>,character:array<string,mixed>} */
public function synchronize(
    array $encounter,
    array $lockedCharacter,
    int $playerActionAllowance,
    int $enemyActionAllowance,
): array;
```

The explicit result shape prevents the projector from returning stale Champion HP after one or more enemy resolutions in the same synchronization transaction.

---

### Task 1: Define and verify additive Migration 004

**Files:**
- Create: `database/migrations/004_combat_enemy_ai_initialization.sql`
- Create: `database/migrations/004_combat_enemy_ai_initialization_verify.sql`
- Create: `tests/CombatMigration004Test.php`
- Modify: `tests/run.php`

**Interfaces:**
- Consumes: Migration 003's `schema_migrations`, `combat_encounters.timeline_elapsed_ms BIGINT UNSIGNED NOT NULL`, and InnoDB table.
- Produces: nullable `combat_encounters.enemy_ai_initialized_timeline_ms BIGINT UNSIGNED NULL` plus migration ID `004_combat_enemy_ai_initialization`.

- [ ] **Step 1: Register a failing Migration 004 structural test.** Add `CombatMigration004Test.php` to `tests/run.php`. Require exact migration/verification paths and assert the migration: requires recorded Migration 003; aborts when 004 is already recorded; aborts if `combat_encounters` or the unsigned timeline parent field is absent/incompatible; aborts if the marker exists without the record; adds exactly one nullable unsigned BIGINT without a default; does not execute an `UPDATE`; records 004 once; and contains no HP, Mana, Action, Turn, status, timeline, or unrelated `ALTER` mutation.

  ```php
  assertCombat004SqlContains($sql, "migration_id = '004_combat_enemy_ai_initialization'");
  assertCombat004SqlContains($sql, "migration_id = '003_combat_foundation'");
  assertCombat004SqlContains(
      $sql,
      'add column enemy_ai_initialized_timeline_ms bigint unsigned null',
  );
  assertSameValue(false, preg_match('/\\bupdate\\s+combat_encounters\\b/', $sql) === 1);
  ```

- [ ] **Step 2: Prove RED.** Run `php tests/run.php`. Expected failure: `Migration 004 combat enemy AI initialization must exist.` No production PHP or Migration 003 change is permitted to satisfy this failure.

- [ ] **Step 3: Write the minimal one-time migration.** Follow Migration 003's stored-procedure convention. Preflight the 003 record, 004 absence, `combat_encounters` InnoDB existence, `timeline_elapsed_ms BIGINT UNSIGNED`, and marker absence. Add exactly:

  ```sql
  ALTER TABLE combat_encounters
      ADD COLUMN enemy_ai_initialized_timeline_ms BIGINT UNSIGNED NULL,
      ADD CONSTRAINT chk_combat_encounters_enemy_ai_initialized
          CHECK (
              enemy_ai_initialized_timeline_ms IS NULL
              OR enemy_ai_initialized_timeline_ms <= timeline_elapsed_ms
          );
  ```

  Do not specify a default and do not backfill. Insert only the 004 migration record after the `ALTER` succeeds.

- [ ] **Step 4: Add read-only verification SQL.** Query the 004 migration record; the marker's `COLUMN_TYPE`, nullability, default, and ordinal position; the named CHECK constraint; total encounter count; and counts grouped into `NULL`, `0`, and `>0` marker states. The file must contain no `INSERT`, `UPDATE`, `DELETE`, `ALTER`, `CREATE`, `DROP`, `TRUNCATE`, or `CALL`.

- [ ] **Step 5: Prove GREEN.** Run `php tests/run.php`. Expected: Migration 004 structural tests pass and all existing tests remain green.

- [ ] **Step 6: Verify the local migration diff.** Run `git diff --check`, then manually compare the `ALTER TABLE` column list against the single approved marker. Do not invoke `mysql`, `mariadb`, or the migration SQL.

- [ ] **Review checkpoint:** confirm existing rows remain `NULL`, no backfill/default exists, the CHECK allows `NULL`, and no schema field besides the approved marker is added. Stop for review if any MariaDB compatibility issue makes the CHECK unsafe; do not substitute another column.

### Task 2: Add pure Cave Brute policy and deterministic damage boundaries

**Files:**
- Create: `ascii-quest/lib/CaveBrutePolicy.php`
- Create: `ascii-quest/lib/EnemyDefenseResolver.php`
- Create: `ascii-quest/lib/PrototypeEnemyDefenseResolver.php`
- Create: `ascii-quest/lib/ChampionDamageResolver.php`
- Create: `ascii-quest/lib/PrototypeChampionDamageResolver.php`
- Create: initial `tests/CombatTask6Test.php`
- Modify: `tests/run.php`

**Interfaces:**
- Consumes: `CombatTurnEngine::canStartAction()`, `CombatRandomSource::integer()`, current Cave Brute definition arrays, persisted action timing/snapshot arrays, and `CombatEquipmentProvider` output shape.
- Produces: the exact `CaveBrutePolicy`, `EnemyDefenseResolver`, and `ChampionDamageResolver` contracts defined above.

- [ ] **Step 1: Add deterministic policy RED tests.** Define a `Task6SequenceRandomSource` fake whose `integer()` returns supplied values and counts calls. Test `CaveBrutePolicy` at explicit logical positions: `fire_slam` is selected first when ready; `smash` is selected when Fire Slam is cooling down; Action 0 waits until the Turn boundary; a pending enemy action waits until its resolve position; insufficient time for both actions waits until the Turn boundary; enemy HP 0, Champion HP 0, or inactive encounter returns `stop`; and a pending player action does not mark the enemy busy. Resolve a Fire Slam row, retain its stored `cooldown_ready_timeline_ms`, and prove the all-history input still selects Smash before that position and returns to Fire Slam preference at or after it.

  ```php
  assertSameValue(
      ['decision' => 'start', 'definition_key' => 'fire_slam'],
      $policy->decide($encounter, 145, $enemy, [], $turnState, 0),
  );
  ```

- [ ] **Step 2: Add deterministic resolver RED tests.** Require one Block roll per player action. A roll of 20 succeeds and a roll of 21 fails with the configured 20% chance. With stored base damage 20, blocked output is 10 applied/10 prevented and unblocked output is 20 applied/0 prevented. Change `snapshot_accuracy`, `snapshot_critical_chance`, and `snapshot_critical_damage` between cases and assert identical results.

- [ ] **Step 3: Add Champion formula RED tests.** For a stored Physical base of 18, assert the exact PHP `round()` result from `toughness / (toughness + 100)`. For stored Fire base 24, assert the exact result from `resistances.fire / 100`. Test very high Toughness and Fire Resistance still apply at least 1 damage for positive input. Change `dodging` only and assert the result is unchanged. Reject missing, zero, negative, or unsupported stored damage/type instead of accepting browser/default values.

- [ ] **Step 4: Prove RED.** Run `php tests/run.php`. Expected failures name missing `CaveBrutePolicy`, `PrototypeEnemyDefenseResolver`, and `PrototypeChampionDamageResolver` classes.

- [ ] **Step 5: Implement minimal pure policy.** Inspect the complete server-owned action history supplied under lock, including resolved enemy rows. Derive each action's latest cooldown-ready position from persisted rows; use the Turn engine for Action/time/busy acceptance. Return `fire_slam`, then `smash`, otherwise the earliest strictly future pending resolution, cooldown-ready position, or Turn boundary. Return `stop` for inactive/zero-HP state. Never choose randomly or derive cooldown from wall time.

  ```php
  if ($encounter['status'] !== 'active'
      || (int) $encounter['enemy_current_hp'] === 0
      || $championCurrentHp === 0) {
      return ['decision' => 'stop'];
  }

  $pendingEnemyAction = $this->pendingEnemyAction($lockedActionHistory, $timelineMs);
  if ($pendingEnemyAction !== null) {
      return [
          'decision' => 'wait',
          'next_timeline_ms' => (int) $pendingEnemyAction['resolves_timeline_ms'],
      ];
  }

  if ((int) $turnState['enemy_actions_remaining'] === 0) {
      return [
          'decision' => 'wait',
          'next_timeline_ms' => (int) $turnState['turn_ends_timeline_ms'],
      ];
  }

  $futureCooldowns = [];
  foreach (['fire_slam', 'smash'] as $definitionKey) {
      $durationMs = (int) round(
          $enemyDefinition['actions'][$definitionKey]['duration_seconds'] * 1000,
      );
      $cooldownReady = $this->latestCooldownReadyTimeline(
          $lockedActionHistory,
          'enemy',
          $definitionKey,
      );
      if ($timelineMs >= $cooldownReady
          && $this->turnEngine->canStartAction(
              $turnState,
              'enemy',
              $timelineMs,
              $durationMs,
          )) {
          return ['decision' => 'start', 'definition_key' => $definitionKey];
      }
      if ($cooldownReady > $timelineMs) {
          $futureCooldowns[] = $cooldownReady;
      }
  }

  return [
      'decision' => 'wait',
      'next_timeline_ms' => min(
          (int) $turnState['turn_ends_timeline_ms'],
          ...$futureCooldowns,
      ),
  ];
  ```

  Implement `pendingEnemyAction()` and `latestCooldownReadyTimeline()` as private array scans over the supplied locked history. The cooldown helper considers both pending and resolved rows for actor `enemy` and the matching `definition_key`; it returns 0 when no prior action exists.

- [ ] **Step 6: Implement automatic Cave Brute Block.** `PrototypeEnemyDefenseResolver` receives `CombatRandomSource` in its constructor, calls `integer(1, 100)` exactly once, reads only `snapshot_base_damage` and configured `enemyDefinition['block']['server_only']`, and returns the four-field result. It must not read Accuracy/Critical fields or create a reaction prompt.

  ```php
  $incoming = (int) $playerAction['snapshot_base_damage'];
  $blockConfig = $enemyDefinition['block']['server_only'];
  $blockChancePercent = (int) $blockConfig['prototype_chance_percent'];
  $blockReductionPercent = (int) $blockConfig['prototype_reduction_percent'];
  $blocked = $this->random->integer(1, 100) <= $blockChancePercent;
  $prevented = $blocked ? (int) round($incoming * $blockReductionPercent / 100) : 0;
  $applied = max(0, $incoming - $prevented);
  ```

- [ ] **Step 7: Implement Champion damage formulas.** `PrototypeChampionDamageResolver` reads the enemy action's stored `snapshot_base_damage` and `snapshot_damage_type`. Physical uses current `toughness`; Fire uses current `resistances.fire`; `dodging` is ignored. Apply `round()`, clamp positive attacks to at least 1, and return incoming/prevented/applied integers.

  ```php
  $baseDamage = (int) $enemyAction['snapshot_base_damage'];
  $damageType = (string) $enemyAction['snapshot_damage_type'];
  $toughness = (int) $currentDefense['toughness'];
  $fireResistancePercent = (float) $currentDefense['resistances']['fire'];
  $reductionFraction = match ($damageType) {
      'physical' => $toughness / ($toughness + 100),
      'fire' => $fireResistancePercent / 100,
  };
  $applied = max(1, (int) round($baseDamage * (1 - $reductionFraction)));
  $prevented = $baseDamage - $applied;
  ```

- [ ] **Step 8: Prove GREEN.** Run `php tests/run.php`; then lint all five new PHP classes and `tests/CombatTask6Test.php`.

- [ ] **Review checkpoint:** confirm all random values originate from the injected boundary, the policy is deterministic/skill-first, values come from existing config, and no HP/repository/UI behavior exists in these pure classes.

### Task 3: Extend repository persistence and lock-aware fakes

**Files:**
- Modify: `ascii-quest/lib/CombatRepository.php`
- Modify: `tests/CombatRepositoryTest.php`
- Modify: Task 6 support in `tests/CombatTask6Test.php`

**Interfaces:**
- Consumes: Migration 003 action result fields and Migration 004 encounter marker.
- Produces:

  ```php
  // Existing all-history lock used by CaveBrutePolicy for busy/cooldown state.
  public function lockActionsForEncounter(int $encounterId): array;

  // New pending-only lock used exclusively for chronological due resolution.
  public function lockPendingActionsForEncounter(int $encounterId): array;
  public function resolveLockedActionWithDamage(
      int $encounterId,
      int $actionId,
      int $completedTimelineMs,
      int $resolvedDamage,
      int $preventedDamage,
  ): bool;
  public function updateLockedCharacterCurrentHp(
      int $userId,
      int $characterId,
      int $expectedCurrentHp,
      int $newCurrentHp,
  ): bool;
  ```

  `createEncounter()` and `updateLockedEncounterSynchronization()` additionally require/persist `enemy_ai_initialized_timeline_ms`; synchronization also persists `enemy_current_hp` and the existing `next_enemy_decision_timeline_ms` under the optimistic encounter version.

- [ ] **Step 1: Add repository RED tests.** Require new encounter insert SQL to include the marker; pending action query to use `state = 'pending' ORDER BY resolves_timeline_ms, id FOR UPDATE`; existing `lockActionsForEncounter()` to continue returning pending and resolved rows for enemy cooldown/busy policy evaluation; enemy action creation to persist `actor='enemy'`, configured action kind/key, `request_token=NULL`, active slot, logical start/resolve/cooldown, stored damage type/base, and null player-only snapshot fields; and result SQL to set only lifecycle/result fields while requiring matching encounter/action and `state='pending'`.

- [ ] **Step 2: Add HP and rollback RED tests.** Under Champion → Account → Encounter → Action locks, resolve an action and update either enemy encounter HP or Champion `current_hp`. Assert zero is accepted, negative HP is rejected before SQL, compare-and-swap mismatch returns false, and an injected final encounter/Champion/action write failure rolls back marker, HP, action state/results, schedule, allowance, and version together.

- [ ] **Step 3: Prove RED.** Run `php tests/run.php`. Expected failures: missing repository methods and missing marker/HP/result SQL parameters.

- [ ] **Step 4: Implement minimal prepared statements.** Preserve `requireEncounterLock()` and `detailRowsTouched`. `lockPendingActionsForEncounter()` sorts chronologically and locks rows. `resolveLockedActionWithDamage()` writes `state='resolved'`, `active_slot=NULL`, exact completion position, `resolved_damage`, and `prevented_damage`; it does not rewrite snapshots or cooldowns. `updateLockedCharacterCurrentHp()` requires the existing Champion lock and updates with user/ID/expected-HP predicates.

  ```sql
  SELECT * FROM combat_actions
  WHERE encounter_id = :encounter_id AND state = 'pending'
  ORDER BY resolves_timeline_ms ASC, id ASC
  FOR UPDATE;

  UPDATE combat_actions
  SET state = 'resolved',
      active_slot = NULL,
      completed_timeline_ms = :completed_timeline_ms,
      resolved_damage = :resolved_damage,
      prevented_damage = :prevented_damage
  WHERE id = :action_id
    AND encounter_id = :encounter_id
    AND state = 'pending';

  UPDATE characters
  SET current_hp = :new_current_hp
  WHERE id = :character_id
    AND user_id = :user_id
    AND current_hp = :expected_current_hp;

  UPDATE combat_encounters
  SET timeline_elapsed_ms = :timeline_elapsed_ms,
      last_synchronized_at = :last_synchronized_at,
      turn_number = :turn_number,
      turn_started_timeline_ms = :turn_started_timeline_ms,
      player_actions_remaining = :player_actions_remaining,
      enemy_actions_remaining = :enemy_actions_remaining,
      enemy_current_hp = :enemy_current_hp,
      next_enemy_decision_timeline_ms = :next_enemy_decision_timeline_ms,
      enemy_ai_initialized_timeline_ms = :enemy_ai_initialized_timeline_ms,
      version = version + 1
  WHERE id = :encounter_id
    AND version = :expected_version;
  ```

  Keep `lockActionsForEncounter()` unchanged as the all-history `ORDER BY id ASC FOR UPDATE` query used for cooldown history. Never substitute the pending-only result when calling `CaveBrutePolicy`.

- [ ] **Step 5: Extend the dependency-free PDO fake exactly to the new SQL.** Snapshot Character rows as well as Encounter/Action/Event rows for rollback. Reject unexpected SET clauses and record write/lock order; do not make the fake accept arbitrary SQL.

- [ ] **Step 6: Prove GREEN.** Run `php tests/run.php`; lint `CombatRepository.php`, `CombatRepositoryTest.php`, and `CombatTask6Test.php`; run `git diff --check`.

- [ ] **Review checkpoint:** inspect generated SQL for Champion → Account → Encounter → Action order, optimistic Encounter versioning, pending-state idempotency, zero HP support, and absence of schema assumptions beyond Migration 004.

### Task 4: Introduce exactly-once action resolution with current aggregate state

**Files:**
- Create: `ascii-quest/lib/CombatActionResolver.php`
- Modify: `tests/CombatTask6Test.php`
- Modify: `tests/CombatServiceTest.php`

**Interfaces:**
- Consumes: `CombatRepository`, `CombatDefinitionRegistry`, `CombatEquipmentProvider`, `EnemyDefenseResolver`, `ChampionDamageResolver`, and locked pending action arrays.
- Produces: `CombatActionResolver::resolvePending()` returning `['encounter' => ..., 'character' => ...]` with all in-memory HP matching successful repository writes.

- [ ] **Step 1: Add player-resolution RED tests.** Seed one pending Task 5 player weapon action and call `resolvePending()` at its stored `resolves_timeline_ms`. Assert the resolver uses `snapshot_base_damage`, performs one Block roll, decrements `enemy_current_hp` once, writes `resolved_damage`/`prevented_damage`, clears the active slot, and uses the exact stored resolve position as completion. A repeated call against the now-resolved row must not roll Block or damage again.

- [ ] **Step 2: Add historical-action RED tests.** Seed a player action already `resolved` before Task 6 with null result fields. Assert it is excluded from pending resolution and enemy HP remains unchanged; Task 6 never retroactively damages for historical actions.

- [ ] **Step 3: Add enemy-resolution RED tests.** Seed pending `smash` and `fire_slam` actions separately. Change the injected equipment provider after action start but before resolution; assert `currentDefense()` is called exactly at resolution and the latest Toughness/Fire Resistance determines damage. Assert Champion HP clamps to zero, never negative; no `life_state`, `died_at`, reward, encounter status, or Slayer field changes.

- [ ] **Step 4: Prove RED.** Run `php tests/run.php`. Expected failure: `CombatActionResolver` is missing.

- [ ] **Step 5: Implement the minimal actor-specific resolver.** For `actor='player'` and `action_kind='weapon'`, call `EnemyDefenseResolver`, update in-memory enemy HP with `max(0, old-applied)`, and persist the action result. For `actor='enemy'` with `smash`/`fire_slam`, call `currentDefense()` at that moment, call `ChampionDamageResolver`, update Champion HP with compare-and-swap, and persist the action result. Reject unknown actors/actions or mismatched definitions; never fall back to client/default damage.

  ```php
  return match ($lockedAction['actor']) {
      'player' => $this->resolvePlayerWeapon(
          $encounter,
          $lockedCharacter,
          $lockedAction,
          $lockedAction['resolves_timeline_ms'],
      ),
      'enemy' => $this->resolveEnemyAttackUsingCurrentDefense(
          $encounter,
          $lockedCharacter,
          $lockedAction,
          $this->equipment->currentDefense($lockedCharacter),
      ),
      default => throw new DomainException('Unsupported combat action actor.'),
  };
  ```

  Each branch first requires `state='pending'`, persists HP and the action result once, and returns matching in-memory Encounter/Character aggregates. It never reloads damage from the current player weapon or browser input.

- [ ] **Step 6: Prove GREEN.** Run `php tests/run.php`; lint `CombatActionResolver.php` and changed test files.

- [ ] **Review checkpoint:** confirm pending-state/result writes make Block and damage exactly once, player Accuracy/Critical fields are unused, enemy Dodge is unused, current defense is not snapshotted, and terminal lifecycle work remains untouched.

### Task 5: Process one authoritative enemy decision at a logical cursor

**Files:**
- Modify: `ascii-quest/lib/CombatSynchronizer.php`
- Modify: `ascii-quest/lib/CombatRepository.php`
- Modify: `tests/CombatTask6Test.php`
- Modify: `tests/CombatRepositoryTest.php`

**Interfaces:**
- Consumes: `CaveBrutePolicy::decide()`, existing enemy definitions, `CombatTurnEngine::consumeAction()`, `CombatRepository::lockActionsForEncounter()` for complete busy/cooldown history, and `CombatRepository::createAction()`.
- Produces: a focused private `CombatSynchronizer::processEnemyDecisionAtCursor()` operation. Its internal result contains the updated Encounter and Turn state, an optional started action, and an invocation-local `suppress_enemy_decisions` boolean; Task 6 consumes this operation without exposing it as a new public domain boundary.

  ```php
  /**
   * @return array{
   *   encounter:array<string,mixed>,
   *   turn_state:array<string,mixed>,
   *   started_action:?array,
   *   suppress_enemy_decisions:bool
   * }
   */
  private function processEnemyDecisionAtCursor(
      array $encounter,
      array $lockedCharacter,
      array $turnState,
      int $cursorMs,
  ): array;
  ```

- [ ] **Step 1: Add skill-first cursor-operation RED tests.** Invoke public `synchronize()` with an already-AI-initialized Encounter whose marker and next decision are both 0 and whose wall-clock gap is zero. With both actions ready, require one pending `fire_slam`; with Fire Slam cooling, require one pending `smash`. Resolve the earlier Fire Slam row but retain its future `cooldown_ready_timeline_ms`; assert Smash is selected before that stored position and Fire Slam becomes preferred at/after it. Assert enemy Action decreases from 2 to 1. Read the expected kind/damage type/duration from the action's public definition fields and cooldown/base damage from its `server_only` fields; assert the stored action uses those exact values, with start at the cursor, resolve at cursor plus duration, and cooldown-ready at cursor plus cooldown. Record that `processEnemyDecisionAtCursor()` was reached through public synchronization.

  ```php
  $definition = $enemyDefinition['actions']['fire_slam'];
  assertSameValue(false, array_key_exists('cooldown_seconds', $definition));
  assertSameValue(false, array_key_exists('prototype_damage', $definition));
  assertSameValue((string) $definition['kind'], $storedAction['action_kind']);
  assertSameValue((string) $definition['damage_type'], $storedAction['snapshot_damage_type']);
  assertSameValue(
      $cursorMs + (int) round($definition['duration_seconds'] * 1000),
      (int) $storedAction['resolves_timeline_ms'],
  );
  assertSameValue(
      $cursorMs + (int) round($definition['server_only']['cooldown_seconds'] * 1000),
      (int) $storedAction['cooldown_ready_timeline_ms'],
  );
  assertSameValue(
      (int) $definition['server_only']['prototype_damage'],
      (int) $storedAction['snapshot_base_damage'],
  );
  ```

- [ ] **Step 2: Add sequential/concurrent cursor-operation RED tests.** A pending enemy action prevents another enemy start and produces a wait until its exact resolution. A pending player action at the same cursor does not prevent an enemy start. Assert the database active-slot constraint remains the final concurrency guard after the domain check.

- [ ] **Step 3: Add wait scheduling RED tests.** Zero enemy Actions persist the next Turn boundary; insufficient Turn time persists the Turn boundary; a pending enemy action persists its resolution; cooling actions persist the earliest strictly future cooldown that can change the decision. Assert `next_enemy_decision_timeline_ms` is updated under the existing Encounter lock and optimistic version.

- [ ] **Step 4: Add cursor-operation `stop` RED tests.** For inactive Encounter, zero Cave Brute HP, and zero Champion HP, make the stored next decision due at a zero-gap current cursor. Assert the policy is invoked once, no action starts, no Action is consumed, no sentinel is written, and the operation reports invocation-local enemy-decision suppression. Assert no Task 12/13 status, reward, or death transition is invented. Task 6 separately proves that this result cannot stall forward chronological processing.

- [ ] **Step 5: Prove RED.** Run `php tests/run.php`. Expected failures show that no authoritative enemy action is created, no enemy Action is consumed, wait positions are not persisted, and a due `stop` decision can remain eligible at the same cursor.

- [ ] **Step 6: Implement the private cursor operation.** At the supplied logical cursor, call `lockActionsForEncounter()` and pass the complete pending/resolved history to `CaveBrutePolicy` exactly once. Do not use `lockPendingActionsForEncounter()` as cooldown history. For `start`, recheck `CombatTurnEngine::canStartAction()`, consume one enemy Action, create one server-owned action, persist the next decision at that action's resolve position—the earliest point at which enemy busy state can change—and return suppression false. For `wait`, persist the policy's strictly future `next_timeline_ms` and return suppression false. For `stop`, create no action, persist no sentinel, return `suppress_enemy_decisions=true`, and leave the stored due position available for fresh policy evaluation on the next synchronization request.

  ```php
  $history = $this->repository->lockActionsForEncounter((int) $encounter['id']);
  $decision = $this->caveBrutePolicy->decide(
      $encounter,
      (int) $lockedCharacter['current_hp'],
      $enemyDefinition,
      $history,
      $turnState,
      $cursorMs,
  );

  return match ($decision['decision']) {
      'start' => $this->startEnemyActionAtCursor(
          $encounter,
          $turnState,
          $enemyDefinition,
          $decision['definition_key'],
          $cursorMs,
      ),
      'wait' => $this->persistEnemyDecisionPosition(
          $encounter,
          $decision['next_timeline_ms'],
      ),
      'stop' => [
          'encounter' => $encounter,
          'turn_state' => $turnState,
          'started_action' => null,
          'suppress_enemy_decisions' => true,
      ],
  };
  ```

  Both `startEnemyActionAtCursor()` and `persistEnemyDecisionPosition()` return this same four-key result shape. The start helper returns the Turn state after `consumeAction()`; wait/stop return the unchanged Turn state. Start and wait return `suppress_enemy_decisions=false`.

- [ ] **Step 7: Implement server-owned enemy action persistence.** Read the selected enemy action through its exact current config shape. Store `request_token=NULL`, `active_slot=1`, configured actor/kind/key, exact logical start/resolve/cooldown positions, `snapshot_damage_type`, and `snapshot_base_damage`; store null player-only weapon/Accuracy/Critical snapshot fields. Cooldown begins at action start. Do not use browser values or randomness to choose the action, and never look for enemy `cooldown_seconds` or `prototype_damage` at the action's top level.

  ```php
  $definition = $enemyDefinition['actions'][$definitionKey];

  $durationMs = (int) round(
      $definition['duration_seconds'] * 1000,
  );
  $cooldownMs = (int) round(
      $definition['server_only']['cooldown_seconds'] * 1000,
  );
  $damageType = (string) $definition['damage_type'];
  $baseDamage = (int) $definition['server_only']['prototype_damage'];
  $actionKind = (string) $definition['kind'];

  $action = $this->repository->createAction((int) $encounter['id'], [
      'actor' => 'enemy',
      'action_kind' => $actionKind,
      'definition_key' => $definitionKey,
      'request_token' => null,
      'active_slot' => 1,
      'state' => 'pending',
      'started_timeline_ms' => $cursorMs,
      'resolves_timeline_ms' => $cursorMs + $durationMs,
      'cooldown_ready_timeline_ms' => $cursorMs + $cooldownMs,
      'snapshot_weapon_key' => null,
      'snapshot_damage_type' => $damageType,
      'snapshot_base_damage' => $baseDamage,
      'snapshot_accuracy' => null,
      'snapshot_critical_chance' => null,
      'snapshot_critical_damage' => null,
  ]);
  ```

- [ ] **Step 8: Add the narrow public synchronization hook.** For an already-initialized Encounter only, if `next_enemy_decision_timeline_ms <= timeline_elapsed_ms`, invoke `processEnemyDecisionAtCursor()` once at the current timeline before returning—even when the capped target equals that timeline. Do not initialize a `NULL` marker, advance to another event position, or scan future events in this task.

  ```php
  $cursorMs = (int) $encounter['timeline_elapsed_ms'];
  if ($encounter['enemy_ai_initialized_timeline_ms'] !== null
      && (int) $encounter['next_enemy_decision_timeline_ms'] <= $cursorMs) {
      $decisionResult = $this->processEnemyDecisionAtCursor(
          $encounter,
          $lockedCharacter,
          $turnState,
          $cursorMs,
      );
      $encounter = $decisionResult['encounter'];
      $turnState = $decisionResult['turn_state'];
  }
  ```

  This hook exists solely to make Task 5 independently executable and to prove the native timeline-0/marker-0/decision-0/zero-gap case. Task 6 replaces this one-shot call site with the general loop while retaining the same private operation.

- [ ] **Step 9: Prove GREEN.** Run `php tests/run.php`; assert the public zero-gap synchronization created the expected Fire Slam at timeline 0; lint `CombatSynchronizer.php`, `CombatRepository.php`, and changed tests; run `git diff --check`.

- [ ] **Review checkpoint:** confirm Task 5 is independently green through public `synchronize()`: skill-first selection sees resolved cooldown history, one Action is consumed at most once, action timing is persisted, wait scheduling cannot remain at the same cursor, player/enemy concurrency is preserved, enemy self-overlap is rejected, and `stop` uses only invocation-local suppression. Confirm no legacy initialization or multi-position loop was added.

### Task 6: Build the chronological synchronization loop from completed cursor operations

**Files:**
- Modify: `ascii-quest/lib/CombatSynchronizer.php`
- Modify: `ascii-quest/lib/CombatService.php`
- Modify: `tests/CombatTask6Test.php`
- Modify: `tests/CombatServiceTest.php`

**Interfaces:**
- Consumes: Task 4's `CombatActionResolver::resolvePending()`, Task 5's completed `processEnemyDecisionAtCursor()` behavior, repository chronological locks, Turn engine, locked Character, wall clock, and the existing five-second cap.
- Produces: the four-argument `CombatSynchronizer::synchronize()` aggregate result documented above, with deterministic position-by-position resolution, Turn reset, and enemy-decision ordering.

- [ ] **Step 1: Add synchronization-result RED tests.** Require both Encounter and locked Character in the return value. Existing Task 4/5 cases must preserve the same timeline, wall anchor, Turn, allowance, cooldown, action-resolution ordering, no-auto-player-action rule, and no-free-healing behavior after callers unwrap the result.

- [ ] **Step 2: Add legacy anchor RED tests.** Starting from persisted `timeline_elapsed_ms=55603`, marker `NULL`, and a target up to five seconds later, assert the persisted starting timeline is captured before catch-up; the marker and first enemy decision position become exactly 55603; no event/action has an earlier position; and processing then continues through the permitted target. An immediate second synchronization retains marker 55603 and does not repeat initialization or replay discarded wall time.

- [ ] **Step 3: Add native encounter RED tests.** Starting timeline 0, marker 0, next decision 0, and zero wall gap must invoke the Task 5 cursor operation and create the first Cave Brute action at timeline 0. This proves same-position due work is processed even when `cursor === target`.

- [ ] **Step 4: Add chronological-order RED tests.** In one five-second window, seed interleaved player resolve, enemy resolve, enemy cooldown-ready decision, and a Turn boundary. Record each processed position and assert sorting by logical position; at an equal position resolve actions in `resolves_timeline_ms, id` order, apply damage/complete actions, enforce zero-HP stops, reset the Turn, then invoke the Task 5 enemy-decision operation. Assert an action exactly at Turn end resolves before allowances reset.

- [ ] **Step 5: Add catch-up and `stop` integration RED tests.** Compare connected one-second polls with one capped five-second reconnect from equivalent persisted state and assert identical logical Encounter/Character/Action results. A 30-second wall gap advances only five seconds, reanchors `last_synchronized_at` to actual server now, and an immediate poll advances zero discarded time. With invocation-local enemy suppression active, omit enemy decision timing from future candidates but continue action resolution and Turn processing through the same capped target. On a later synchronization, reset only the local suppression flag, reevaluate authoritative HP/status once, and prove repeated `stop` remains safe without creating an action or terminal state.

- [ ] **Step 6: Prove RED.** Run `php tests/run.php`. Expected failures show the current synchronizer advances segment-wide, does not accept/return current Character state, does not initialize legacy AI at the starting cursor, and does not repeatedly invoke the completed Task 5 operation at intermediate positions.

- [ ] **Step 7: Implement the position-by-position event loop.** Replace/generalize Task 5's narrow one-shot call site; do not copy or create a second enemy-decision implementation. Before calculating forward events, capture the persisted starting timeline. If the marker is `NULL`, persist the marker and next decision at that start. At each cursor, process all due action resolutions one at a time, enforce HP stops, process a same-position Turn boundary, then invoke the same `processEnemyDecisionAtCursor()` if the enemy decision is due and enemy decisions have not been suppressed for this invocation. Choose the next cursor as the minimum strictly future pending resolution, non-suppressed next enemy decision, Turn boundary, or capped target. Process due work at the capped target before returning.

  ```text
  persisted start / compatibility anchor
      -> due action resolution(s), ordered by resolve position then ID
      -> zero-HP start guards
      -> same-position Turn reset
      -> due Cave Brute decision through Task 5
      -> earliest strictly future non-suppressed event or capped target
  ```

  ```php
  while (true) {
      foreach ($this->duePendingActionsAt((int) $encounter['id'], $cursorMs) as $action) {
          ['encounter' => $encounter, 'character' => $lockedCharacter]
              = $this->actionResolver->resolvePending($encounter, $lockedCharacter, $action);
      }

      $turnState = $this->turnEngine->synchronizeTurn(
          $turnState,
          $cursorMs,
          $playerActionAllowance,
          $enemyActionAllowance,
      );

      if (!$enemyDecisionSuppressed
          && (int) $encounter['next_enemy_decision_timeline_ms'] <= $cursorMs) {
          $result = $this->processEnemyDecisionAtCursor(
              $encounter,
              $lockedCharacter,
              $turnState,
              $cursorMs,
          );
          $encounter = $result['encounter'];
          $turnState = $result['turn_state'];
          $enemyDecisionSuppressed = $result['suppress_enemy_decisions'];
      }

      if ($cursorMs === $targetTimelineMs) {
          break;
      }

      $cursorMs = min(...$this->strictlyFutureCandidates(
          $encounter,
          $turnState,
          $enemyDecisionSuppressed,
          $cursorMs,
          $targetTimelineMs,
      ));
  }
  ```

  Add only private loop helpers with these roles:

  ```php
  /** @return array<int, array<string, mixed>> */
  private function duePendingActionsAt(int $encounterId, int $cursorMs): array;

  /** @return array<int, int> strictly future positions; always includes target/Turn end */
  private function strictlyFutureCandidates(
      array $encounter,
      array $turnState,
      bool $enemyDecisionSuppressed,
      int $cursorMs,
      int $targetTimelineMs,
  ): array;
  ```

  `duePendingActionsAt()` uses only `lockPendingActionsForEncounter()` and exact logical resolve positions. `processEnemyDecisionAtCursor()` independently uses `lockActionsForEncounter()` so resolved cooldown history remains visible. Pass `$targetTimelineMs` to `strictlyFutureCandidates()` and remove the redundant outer `min()` if the helper already includes the target.

- [ ] **Step 8: Preserve invocation-local `stop` semantics.** Initialize `enemyDecisionSuppressed=false` for each `synchronize()` call. If Task 5 returns `stop`, set it true, exclude enemy-decision timing from candidates for the remainder of that call, and continue other chronological work. Do not change `next_enemy_decision_timeline_ms` to a sentinel. A later request starts with false and reevaluates current authoritative HP/status.

- [ ] **Step 9: Preserve wall-clock semantics.** Calculate the capped target exactly once from the injected server clock, set `last_synchronized_at` to actual server now, never accept a client timestamp, and never carry discarded offline time to another request.

- [ ] **Step 10: Prove GREEN.** Run `php tests/run.php`; lint `CombatSynchronizer.php`, `CombatService.php`, and both changed test files.

- [ ] **Review checkpoint:** trace the `55603` fixture and the marker-0/zero-gap fixture manually; confirm action resolution precedes same-position Turn reset, Task 5 is the only enemy-decision implementation, a `stop` cannot loop or block other work, and no query resolves all actions `<= target` without intermediate decisions.

### Task 7: Wire production dependencies, new encounters, zero-HP commands, and safe projection

**Files:**
- Modify: `ascii-quest/lib/CombatBootstrap.php`
- Modify: `ascii-quest/lib/CombatService.php`
- Modify: `ascii-quest/lib/CombatStateProjector.php`
- Modify: `tests/CombatTask6Test.php`
- Modify: `tests/CombatServiceTest.php`
- Modify: `tests/CombatSecurityTest.php`

**Interfaces:**
- Consumes: existing service transaction boundary and the Task 6 policy/resolver constructors.
- Produces: explicit production construction with `SystemCombatRandomSource`, and `enemy.active_action` as the only new public enemy-action view.

- [ ] **Step 1: Add Bootstrap/new-encounter RED tests.** Assert production wiring supplies the same injected fake clock/equipment/random seams in tests. A newly created encounter must write marker 0 and next decision 0; first synchronization may create Fire Slam at timeline 0. Existing encounter resume must preserve its marker, HP, actions, and schedule.

- [ ] **Step 2: Add zero-HP command RED tests.** After synchronization reduces Cave Brute HP to zero, `startPlayerAction()` rejects without inserting/consuming another action. When Champion HP is zero, player and Cave Brute starts both stop. Rejection may commit already-authoritative synchronization, but it must not issue rewards, change status to victory/defeated, set death fields, or heal resources.

- [ ] **Step 3: Add projection privacy RED tests.** A pending enemy action may appear only as:

  ```php
  'enemy' => [
      // existing key/name/glyph/current_hp/maximum_hp fields
      'active_action' => [
          'id', 'action_kind', 'definition_key', 'name', 'damage_type',
          'state', 'started_timeline_ms', 'resolves_timeline_ms',
      ],
  ]
  ```

  Assert absent everywhere: `cooldown_ready_timeline_ms`, cooldown readiness, `next_enemy_decision_timeline_ms`, `enemy_ai_initialized_timeline_ms`, future policy choice, Block chance/roll, raw defense, all snapshot fields, request token, reward/death guards, and database lock metadata. Resolved/history enemy actions must not remain `active_action`.

- [ ] **Step 4: Prove RED.** Run `php tests/run.php`. Expected failures: marker missing from creation, Task 6 production dependencies absent, zero-HP player start accepted, and no safe enemy active-action projection.

- [ ] **Step 5: Wire dependencies explicitly.** Construct `SystemCombatRandomSource`, both prototype damage resolvers, `CaveBrutePolicy`, and `CombatActionResolver` in `CombatBootstrap`; keep optional injection parameters for deterministic tests. Do not introduce global mutable state or a service locator.

- [ ] **Step 6: Update Service aggregate handling.** Pass the locked Character into synchronization, use returned Encounter/Character for optimistic persistence and projection, create Task-6-native encounters with marker/decision zero, and recheck both HP pools before accepting a new player action.

- [ ] **Step 7: Implement the projector allowlist.** Select at most the one pending enemy action already started. Resolve display `name`/`damage_type` from `CombatDefinitionRegistry`, not hidden snapshot/config internals; never expose cooldown-ready or future-decision state.

  ```php
  $activeEnemyAction = null;
  foreach ($lockedActions as $action) {
      if ($action['actor'] !== 'enemy' || $action['state'] !== 'pending') {
          continue;
      }
      $enemyDefinition = $this->definitions->enemy(
          (string) $encounter['enemy_key'],
      );
      $definition = $enemyDefinition['actions'][$action['definition_key']];
      $activeEnemyAction = [
          'id' => (int) $action['id'],
          'action_kind' => (string) $action['action_kind'],
          'definition_key' => (string) $action['definition_key'],
          'name' => (string) $definition['name'],
          'damage_type' => (string) $definition['damage_type'],
          'state' => 'pending',
          'started_timeline_ms' => (int) $action['started_timeline_ms'],
          'resolves_timeline_ms' => (int) $action['resolves_timeline_ms'],
      ];
      break;
  }
  ```

  Attach only this allowlisted value as `enemy.active_action`; do not merge the raw action, Encounter, definition, Block, or defense arrays into public state.

- [ ] **Step 8: Prove GREEN.** Run `php tests/run.php`; lint Bootstrap, Service, Projector, and changed tests; run `git diff --check`.

- [ ] **Review checkpoint:** inspect the serialized state recursively for every forbidden key and confirm no browser-facing endpoint accepts damage, timing, random, HP, Action, cooldown, or enemy decision values.

### Task 8: Harden chronological idempotency, rollback, and scope boundaries

**Files:**
- Modify: `tests/CombatTask6Test.php`
- Modify: `tests/CombatRepositoryTest.php`
- Modify: `tests/CombatServiceTest.php`
- Modify: `tests/CombatSecurityTest.php`
- Modify production files only when one of these retained tests reproduces a defect.

**Interfaces:**
- Consumes: completed Task 6 repository/service/synchronizer/projector contracts.
- Produces: retained regression matrix proving atomic, idempotent Task 6 behavior.

- [ ] **Step 1: Add exactly-once replay tests.** Repeat state synchronization at identical server time after automatic Cave Brute Block success/failure and enemy hits. Assert no second random call, HP decrement, action completion, Action consumption, or decision start. A previously resolved pre-Task-6 player action remains historical and untouched.

- [ ] **Step 2: Add transaction failure tests.** Inject failure at enemy action insert, action result update, Champion HP update, and final encounter synchronization/version update. Each failure must roll back timeline/anchor, marker, next decision, both HP pools, action/result rows, Action allowances, and version.

- [ ] **Step 3: Add mixed five-second chronology tests.** Include at least two enemy actions, one player resolution, a cooldown, and a Turn boundary inside one permitted target. Assert each action starts only after its predecessor resolves, Fire Slam remains preferred when it becomes ready, unused Actions reset rather than carry, and no event beyond the target is processed.

- [ ] **Step 4: Add scope-exclusion tests.** Assert no reaction prompt/token is created, no potion charge changes, no reward/EXP/Gold changes, no `victory_loot`, no `life_state`/`died_at`, no Slayer event, no Accuracy/Critical/Dodge branch, and no second enemy definition or weighted choice.

- [ ] **Step 5: Prove RED before any correction.** Run `php tests/run.php`; record each reproduced failure. If all new assertions pass without production changes, record that evidence and do not refactor merely to create work.

- [ ] **Step 6: Make only minimal reproduced corrections.** Keep every change within the named Task 6 files; stop for design review if a fix needs another schema field or Combat Milestone 1 Task 7+ behavior.

- [ ] **Step 7: Prove GREEN.** Run `php tests/run.php`, all changed-file PHP lints, and `git diff --check`.

- [ ] **Review checkpoint:** independently trace lock order, action/result idempotency, random-call counts, chronological positions, cap reanchoring, projection privacy, and zero-HP stopping.

### Task 9: Full local verification and Task 6 checkpoint documentation

**Files:**
- Modify: `docs/tasks/current-task.md`
- No feature files unless a verification command reproduces a Task 6 defect.

**Interfaces:**
- Consumes: the complete local Task 6 diff.
- Produces: a reviewable, unapplied, undeployed Task 6 checkpoint.

- [ ] **Step 1: Run the complete PHP suite.** Run `php tests/run.php`; require a total greater than the Task 5 baseline of 145 and zero failures.

- [ ] **Step 2: Preserve exploration regression.** Run `node tests/ExplorationHudTest.js`; require the current 28-test baseline and zero failures. Task 6 must not modify HUD JavaScript.

- [ ] **Step 3: Lint every created/modified PHP file.** Run `php -l` on the five policy/resolver classes, `CombatActionResolver.php`, Repository, Synchronizer, Service, Projector, Bootstrap, and all changed PHP tests.

- [ ] **Step 4: Run static repository checks.** Run `node --check ascii-quest/js/game_controls.js`, `git diff --check`, `git status --short --branch`, `git diff --stat`, and `git diff`.

- [ ] **Step 5: Perform the plan/spec scope audit.** Search the diff for `reaction_prompt`, `block_token`, potion writes, Gold/EXP/reward writes, `life_state`, `died_at`, Slayer, Accuracy/Critical/Dodge resolution, JavaScript/CSS/map changes, and schema fields other than the approved marker. Explain every match or remove the out-of-scope change.

- [ ] **Step 6: Update the tracker only after GREEN.** Set status to `TASK 6 IMPLEMENTED — AWAITING CHECKPOINT REVIEW`; state Migration 004 is created but unapplied, Task 6 remains local/uncommitted/undeployed, and Task 7 has not started.

- [ ] **Step 7: Request independent code review.** Review the complete diff against the Task 6 addendum and this plan, with special attention to legacy anchoring at the persisted starting timeline, same-position ordering, exactly-once damage/randomness, hidden fields, rollback, and Task 7+/12/13 exclusions.

- [ ] **Review checkpoint:** stop with the complete uncommitted diff and actual verification counts. Do not apply Migration 004, commit, push, deploy, or begin Task 7.

### Task 10: Approval-gated Migration 004 and live acceptance runbook

**Files:** No repository edits. This task is an operational checklist for a separately approved live-integration run.

**Interfaces:**
- Consumes: reviewed Task 6 commit, reviewed Migration 004/verification SQL, live deployment checkout, live `ascii_quest` schema, and authenticated manual browser session.
- Produces: verified one-column live schema, deployed reviewed PHP, and recorded legacy compatibility evidence.

- [ ] **Step 1: Stop and obtain explicit live-integration approval.** Do not connect to the server/database, push, apply Migration 004, or deploy merely because Tasks 1–9 pass.

- [ ] **Step 2: Verify the approved local checkpoint.** Confirm the exact approved commit/branch and clean worktree; rerun PHP/HUD tests, PHP lints, JavaScript syntax, and `git diff --check`. Stop on any mismatch.

- [ ] **Step 3: Back up live application and database.** Create a new timestamped backup directory; copy `/var/www/html/ascii-quest`; create a complete restorable `ascii_quest` SQL dump through the existing secret-safe server configuration; verify both paths are non-empty without printing credentials.

- [ ] **Step 4: Run read-only Migration 004 preflight.** Confirm Migration 003 is recorded; 004 is absent; `combat_encounters` is InnoDB; `timeline_elapsed_ms` is compatible; the marker and 004 CHECK are absent; and no partial schema exists. Inspect encounter ID 1's current status, `timeline_elapsed_ms`, `next_enemy_decision_timeline_ms`, HP, Actions, version, and action/event counts before migration. Stop rather than repair any discrepancy.

- [ ] **Step 5: Prepare and test the clean server checkout.** Push only the reviewed commit after approval, fetch/fast-forward the clean deployment checkout to that exact commit, and run the PHP/HUD/JavaScript/diff checks there. Stop before migration if any command fails.

- [ ] **Step 6: Apply Migration 004 exactly once only after a second explicit go/no-go confirmation.** Execute the reviewed SQL unchanged. On any database error, stop without rerunning or editing live schema.

- [ ] **Step 7: Verify the live schema before deploying PHP.** Run the read-only verification SQL and independently confirm the 004 record, exact nullable unsigned BIGINT column, null default, CHECK constraint, unchanged HP/Mana/timeline/status values, and encounter ID 1 marker still `NULL`.

- [ ] **Step 8: Deploy reviewed Task 6 PHP only after schema verification.** Use the established `rsync --delete --exclude='db.php'` application deployment pattern from the exact reviewed checkout. Preserve environment configuration and verify deployed PHP lints/permissions.

- [ ] **Step 9: Perform first authenticated legacy synchronization.** Record encounter ID 1's persisted starting timeline and `NULL` marker immediately before the request. Trigger one normal authenticated Task-6 state synchronization. Confirm the marker equals that exact starting timeline, the first new Cave Brute action's `started_timeline_ms` equals the marker, and no enemy action exists with an earlier start. Confirm capped advancement and actual wall-clock reanchoring remain correct.

- [ ] **Step 10: Continue controlled live acceptance.** Verify Fire Slam preference, Smash fallback after readiness changes, Action 2, sequential enemy actions with concurrent player action, exactly-once Block/damage, current Toughness/Fire Resistance use, hidden projection fields, and no historical action replay. Do not intentionally reduce Champion 15 to zero HP before Task 13 exists.

- [ ] **Step 11: Stop for browser review.** Report backup paths/sizes, preflight, before/after encounter evidence, migration/verification results, deployed commit, tests, smoke checks, live marker/first-action positions, HP changes, and git statuses. Do not begin Task 7.

---

## Test Traceability

| Approved Task 6 requirement | Primary RED/GREEN task |
|---|---|
| Migration 004 exact one-column contract; old rows remain NULL | Task 1 |
| New encounter marker/decision zero | Task 7 |
| Legacy marker anchors once at persisted starting timeline | Task 6 |
| First legacy decision exactly at anchor; no historical replay | Tasks 6 and 10 |
| New encounter first decision may occur at zero | Tasks 5, 6, and 7 |
| Skill-first Fire Slam, Smash fallback, Action 2 | Tasks 2 and 5 |
| Enemy cooldown begins at start; own overlap blocked | Task 5 |
| Player/enemy concurrent execution | Tasks 2, 5, and 8 |
| Injected random; deterministic Block success/failure exactly once | Tasks 2, 4, and 8 |
| Player damage uses stored base snapshot; Accuracy/Crit ignored | Tasks 2 and 4 |
| Historical resolved player action is not reprocessed | Tasks 4 and 8 |
| Current Toughness/Fire Resistance read at enemy resolution; Dodge ignored | Tasks 2 and 4 |
| Minimum positive damage and HP clamping | Tasks 2 and 4 |
| Zero-HP actors cannot start new actions; `stop` cannot loop | Tasks 2, 5, 6, and 7 |
| Resolution before same-position Turn reset | Tasks 6 and 8 |
| Multiple chronological events inside one five-second window | Tasks 6 and 8 |
| Long disconnect applies five seconds and reanchors actual wall time | Tasks 6 and 8 |
| Enemy AI/cooldown/Block internals absent from projection | Tasks 7 and 8 |
| No Combat Milestone 1 Task 7+/12/13 behavior | Tasks 4, 7, 8, and 9 |
| Live legacy marker/action evidence | Task 10, separately approved only |

## Completed Writing-Plans Self-Review

- [x] **Specification coverage:** Every approved Task 6 behavior and requested RED family maps to Tasks 1–10 and the traceability table. The dependency review found and corrected the original gap by making Task 5 deliver a complete enemy-decision-at-cursor operation before Task 6 builds the chronological loop from it.
- [x] **Placeholder scan:** The plan was searched for unfinished-work markers, vague implementation language, non-specific test instructions, and unnamed similar-task references; no placeholders remain.
- [x] **Interface/type consistency:** Tasks 2–4 produce the policy, action-resolution, repository, and Encounter/Character aggregate contracts consumed by Tasks 5–6. Task 5 produces the sole cursor-level enemy-decision behavior consumed by Task 6. Tasks 7–8 consume those completed contracts without renaming methods or changing return shapes.
- [x] **Cooldown-history consistency:** `CaveBrutePolicy` receives `lockActionsForEncounter()` output so resolved enemy rows continue supplying authoritative `cooldown_ready_timeline_ms`; the pending-only chronological query is never used as cooldown history, and no wall-clock or schema-derived cooldown state is introduced.
- [x] **Task dependency consistency:** Task 5 is independently RED/GREEN testable through the narrow public `synchronize()` current-cursor hook, including the marker-0/decision-0/zero-gap case. Task 6 replaces that one-shot call site with its general event loop and repeatedly invokes the same private `processEnemyDecisionAtCursor()` implementation rather than duplicating it.
- [x] **Implementation concreteness:** Tasks 2–7 include concise control-flow, formula, SQL, actor-resolution, cursor-decision, synchronization-loop, and projection sketches using existing method names and the declared interfaces; execution does not require inventing gameplay behavior or a new public boundary.
- [x] **Source-shape consistency:** Enemy action `kind`, `damage_type`, and `duration_seconds` come from the action definition, while `cooldown_seconds` and `prototype_damage` come only from its `server_only` array. Projection resolves the enemy through the existing Encounter `enemy_key`; no invented Encounter or configuration field remains.
- [x] **Migration consistency:** Every migration reference uses `004_combat_enemy_ai_initialization`; the only approved field is `enemy_ai_initialized_timeline_ms BIGINT UNSIGNED NULL`, existing rows remain `NULL`, and no backfill or additional schema field is planned.
- [x] **Scope consistency:** The plan contains no player Block reaction, potion, combat HUD, reward/victory, permanent-death, real-equipment, Accuracy/Critical/Dodge resolution, additional enemy, Migration 005, or Combat Milestone 1 Task 7+ implementation. Task 6 automatic Cave Brute Block remains the only Block behavior.
- [x] **Chronology consistency:** Legacy `NULL` encounters anchor and decide first at their persisted starting timeline; native marker-0 encounters can decide at timeline 0 with zero wall gap; due action resolution precedes same-position Turn reset; invocation-local `stop` suppression cannot loop and does not prevent other work from reaching the capped target.
