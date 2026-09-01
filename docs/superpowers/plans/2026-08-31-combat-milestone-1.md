# Combat Milestone 1 Foundation Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Add a durable, server-authoritative, pointer-only combat foundation inside ASCII Quest II's existing HUD shell, proving the approved Turn/Action model with one Cave Brute while preserving exploration and preventing refresh, retry, navigation, or duplicate-request exploits.

**Architecture:** A small PHP combat domain uses an injectable UTC clock, a capped logical combat timeline, centralized prototype definitions, `CharacterStats`, focused equipment/Block boundaries, and a transactional repository. MariaDB stores the active encounter, last synchronization, logical timeline, accepted actions/commands, ordered Battle Info events, and permanent Champion death. Each state read or command advances at most the centrally configured disconnected-catch-up window before returning a sanitized projection. A separate vanilla JavaScript module renders combat and sends intent; it never calculates authoritative outcomes.

**Tech Stack:** PHP 8.4, MariaDB 11/InnoDB, PDO, vanilla JavaScript, HTML/CSS, native PHP and Node test runners; no framework or new dependency.

**Spec:** `docs/superpowers/specs/2026-08-31-combat-milestone-1-design.md`

## Global Constraints

- Read `AGENTS.md` and the spec again before implementation.
- Do not apply migration 003 without a separate explicit approval and live preflight/backup.
- Do not commit or push until explicitly requested.
- Preserve the current three-column HUD, exploration mechanics, map JSON, Warp coordinates, CharacterStats formulas, and current HP/Mana persistence.
- Combat is pointer-only. Do not bind WASD, arrows, E, Q/W/E/R, numbers, or any keyboard attack.
- No player offensive action starts automatically.
- `ascii-quest/config/combat.php` is the only source of foundation timing/prototype values; do not scatter `10`, cooldowns, damage, rewards, or provisional Block values.
- `MAX_DISCONNECTED_CATCHUP_SECONDS` is centralized in that configuration and equals `5`; do not scatter the literal or replay skipped offline time through repeated polls.
- PHP/MariaDB decide ownership, time, Actions, cooldowns, damage, HP, potion charges, Block acceptance/outcome, rewards, death, and completion.
- JavaScript may interpolate display bars between server polls but may not make an unavailable action authoritative or send calculated outcomes.
- `CharacterStats` remains the source of Champion-derived values. Do not duplicate formulas in combat PHP or JavaScript.
- Entering, leaving, refreshing, recalculating, or changing equipment must never restore HP/Mana.
- Each implementation task follows red → prove failure → minimal implementation → focused pass → full regression → review checkpoint.
- Stop after each task/checkpoint if a regression or undocumented design choice appears.

## Approved encounter and scope decisions

1. **Cave Brute entry:** combat starts automatically when authoritative movement enters the stationary enemy's one-tile orthogonal fighting range. `E` is not used. Attempting to step onto the occupied enemy tile keeps the Champion on the previous tile and starts the same encounter.
2. **Atomic transition:** movement resolution, final Champion position, and start-or-resume encounter state share one database transaction. The unique active-encounter constraint prevents duplicate creation on retries/two tabs.
3. **Equipment:** real item/equipment persistence and swapping are deferred. Preserve and test the provider contract without creating a fake item backend.
4. **Adjacent-click exploration movement:** preserve it outside combat. Because it uses `move_character.php`, it follows the same range/contact trigger. Disable every movement input path after combat starts.
5. **Chat:** keep the existing tab visible/selectable/non-pausing. Do not create a Chat backend.
6. **Disconnected catch-up:** persist a logical combat timeline and wall-clock `last_synchronized_at`. Advance by the actual gap up to five seconds, set the wall-clock anchor to the real current time, and never process the skipped remainder on later polls.
7. **Lock order:** every relevant transaction locks the selected/owned Champion first, active encounter second if present, and action/event rows third as needed.
8. **Character creation:** allow it during unresolved combat. Keep other Champions unselectable for gameplay, protect the fighter/encounter, and restore ordinary selection after encounter closure.

---

## Expected File Map

### Create

- `ascii-quest/config/combat.php` — turn duration, five-second disconnected-catch-up cap, one stationary Cave Brute encounter position/range definition, and explicitly provisional enemy, weapon, skill/effect, potion, damage, Block, and reward definitions.
- `ascii-quest/lib/CombatClock.php` — clock interface.
- `ascii-quest/lib/SystemCombatClock.php` — UTC production clock.
- `ascii-quest/lib/CombatDefinitionRegistry.php` — validates/serves configured combat definitions.
- `ascii-quest/lib/CombatEncounterTrigger.php` — pure orthogonal range/direct-contact predicate used by movement.
- `ascii-quest/lib/CombatTurnEngine.php` — pure turn/action/time calculations.
- `ascii-quest/lib/CombatRandomSource.php` — testable random token/position/roll boundary.
- `ascii-quest/lib/SystemCombatRandomSource.php` — production `random_bytes`/`random_int` implementation.
- `ascii-quest/lib/CombatEquipmentProvider.php` — offensive snapshot/current-defense boundary.
- `ascii-quest/lib/PrototypeCombatEquipmentProvider.php` — temporary configured weapon provider only; no fake item ownership.
- `ascii-quest/lib/BlockResolver.php` — Block-resolution interface.
- `ascii-quest/lib/PrototypeBlockResolver.php` — centralized provisional foundation resolver.
- `ascii-quest/lib/EnemyDefenseResolver.php` — minimal automatic enemy-Block boundary.
- `ascii-quest/lib/PrototypeEnemyDefenseResolver.php` — centrally configured provisional Cave Brute defense.
- `ascii-quest/lib/CaveBrutePolicy.php` — simple skill-first decision policy.
- `ascii-quest/lib/CombatRepository.php` — PDO locks and persistence.
- `ascii-quest/lib/CombatSynchronizer.php` — chronological due-event/turn/enemy advancement.
- `ascii-quest/lib/CombatStateProjector.php` — player-visible response allowlist that excludes enemy cooldowns.
- `ascii-quest/lib/CombatService.php` — use-case transaction orchestration.
- `ascii-quest/lib/CombatAccessGuard.php` — shared active-encounter/dead-state route decisions.
- `ascii-quest/lib/CombatBootstrap.php` — explicit construction of the above dependencies.
- `ascii-quest/combat_state.php` — owned encounter read/synchronize endpoint.
- `ascii-quest/combat_action.php` — weapon/skill intent endpoint.
- `ascii-quest/combat_block.php` — one-attempt Block endpoint.
- `ascii-quest/combat_potion.php` — instant, charge-limited potion endpoint.
- `ascii-quest/combat_close.php` — post-victory close endpoint.
- `ascii-quest/js/combat_hud.js` — testable pointer-only controller/renderer.
- `database/migrations/003_combat_foundation.sql` — reviewed durable state and Champion death migration.
- `database/migrations/003_combat_foundation_verify.sql` — read-only verification.
- `tests/CombatDefinitionTest.php` — configuration validation and client-hidden fields.
- `tests/CombatTurnEngineTest.php` — deterministic turn/Action rules.
- `tests/CombatServiceTest.php` — action, AI, Block, potion, victory, death, refresh, and idempotency behavior with fakes.
- `tests/CombatSecurityTest.php` — access locks, projection allowlist, and route contracts.
- `tests/CombatMigrationTest.php` — structural SQL/preflight regression checks.
- `tests/CombatHudTest.js` — combat DOM/controller tests.

### Modify

- `tests/run.php` — register the new PHP test arrays.
- `ascii-quest/game.php` — load active encounter/dead state and render exactly one exploration or combat center in the existing HUD shell.
- `ascii-quest/css/style.css` — restrained combat-center, ASCII figures, bars, popup, Battle Info, and loot-row styles only.
- `ascii-quest/js/exploration_hud.js` — honor combat mode for Warp/stat controls while preserving shared tabs/resource rendering.
- `ascii-quest/js/game_controls.js` — refuse initialization/movement in combat mode; preserve exploration behavior.
- `tests/ExplorationHudTest.js` — combat-mode regressions for existing HUD/exploration contracts.
- `ascii-quest/move_character.php` — treat the Cave Brute tile as occupied, atomically start/resume combat on range/contact, and reject later movement during non-closed combat.
- `ascii-quest/interact.php` — reject chest/Warp/transition interaction during non-closed combat.
- `ascii-quest/sync_map_state.php` — return a non-mutating combat-locked response.
- `ascii-quest/unlock_warp.php` — reject unlock during combat.
- `ascii-quest/travel_warp.php` — reject travel during combat.
- `ascii-quest/allocate_stat.php` — reject allocation during combat.
- `ascii-quest/select_character.php` — add CSRF and reject dead/other-Champion selection while an owned encounter is non-closed.
- `ascii-quest/character_select.php` — render DEAD and Resume Battle states; protect form contracts.
- `ascii-quest/delete_character.php` — reject deletion of the Champion whose combat encounter is non-closed and reject every DEAD Champion; ordinary eligible living-Champion deletion remains unchanged.
- `ascii-quest/account.php` — show Resume Battle without clearing or pausing the encounter.

### Deliberately unchanged

- `ascii-quest/lib/CharacterStats.php` and `ascii-quest/config/character_stats.php` formulas.
- `ascii-quest/lib/CharacterStatAllocator.php` security/calculation behavior.
- Warp definitions, Warp migration 002, map collision, chest rewards, traps, stairs, and transitions outside the active-combat guard.
- Existing map JSON stays unchanged: the single prototype encounter position/range is version-controlled in combat configuration and validated against the authoritative map layout/metadata.
- `ascii-quest/create_character.php` remains available during combat; creation must not touch, pause, reset, or close the fighting Champion's encounter.
- Item/equipment/inventory persistence, because none exists yet.

---

## Public Contracts to Keep Stable

```php
interface CombatClock
{
    public function now(): DateTimeImmutable;
}

interface CombatEquipmentProvider
{
    /** @return array<string, int|float|string> */
    public function offensiveSnapshot(array $lockedCharacter, string $attackKey): array;

    /** @return array<string, int|float> */
    public function currentDefense(array $lockedCharacter): array;
}

interface BlockResolver
{
    /** @return array{blocked:bool, incoming_damage:int, prevented_damage:int, applied_damage:int} */
    public function resolve(array $incomingAction, array $currentDefense, bool $attempted): array;
}

interface CombatRandomSource
{
    public function token(int $bytes = 32): string;
    public function integer(int $minimum, int $maximum): int;
}

interface EnemyDefenseResolver
{
    /** @return array{blocked:bool, incoming_damage:int, prevented_damage:int, applied_damage:int} */
    public function resolve(array $playerAction, array $enemyDefense): array;
}
```

```php
final class CombatService
{
    public function startOrResumeForLockedMovement(int $userId, array $lockedCharacter, array $encounterDefinition): array;
    public function state(int $userId, int $characterId): array;
    public function startPlayerAction(int $userId, int $characterId, string $actionKey, string $requestToken): array;
    public function attemptBlock(int $userId, int $characterId, int $enemyActionId, string $blockToken, string $requestToken): array;
    public function usePotion(int $userId, int $characterId, string $requestToken): array;
    public function closeVictory(int $userId, int $characterId, string $requestToken): array;
}
```

```php
final class CombatEncounterTrigger
{
    public static function isInOrthogonalRange(int $championX, int $championY, int $enemyX, int $enemyY, int $range = 1): bool;
    public static function isDirectContact(int $requestedX, int $requestedY, int $enemyX, int $enemyY): bool;
}
```

`startOrResumeForLockedMovement()` is an internal transaction participant, not a public browser endpoint. It requires the caller to hold the selected Champion lock and active PDO transaction. `move_character.php` commits the final movement position and encounter transition together.

The endpoints obtain `userId` and `characterId` only from the session. Request bodies contain intended action/token fields, never a trusted Champion ID or calculated result.

The client projection has this top-level allowlist:

```text
encounter_id, status, server_observed_at, timeline, version,
turn, champion, enemy, player_actions, active_effects,
reaction_prompt, potion, battle_events, loot_phase
```

It must not contain `enemy.cooldowns`, `next_enemy_decision_timeline_ms`, raw offensive snapshots, hidden rolls, reward-issued guards, or database lock/version internals.

---

### Task 1: Lock combat definitions and pure Turn/Action rules

**Files:**
- Create: `ascii-quest/config/combat.php`
- Create: `ascii-quest/lib/CombatClock.php`
- Create: `ascii-quest/lib/SystemCombatClock.php`
- Create: `ascii-quest/lib/CombatRandomSource.php`
- Create: `ascii-quest/lib/SystemCombatRandomSource.php`
- Create: `ascii-quest/lib/CombatDefinitionRegistry.php`
- Create: `ascii-quest/lib/CombatEncounterTrigger.php`
- Create: `ascii-quest/lib/CombatTurnEngine.php`
- Create: `tests/CombatDefinitionTest.php`
- Create: `tests/CombatTurnEngineTest.php`
- Modify: `tests/run.php`

**Interfaces:** `CombatDefinitionRegistry::turnDurationSeconds()`, `maxDisconnectedCatchupSeconds()`, `enemy('cave_brute')`, `playerAction($key)`, `potion($key)`; `CombatTurnEngine::synchronizeTurn()`, `canStartAction()`, and `consumeAction()` operate on explicit state plus integer logical-timeline milliseconds. `CombatClock::now()` supplies wall time only to calculate the capped synchronization delta and audit timestamps.

- [ ] Add failing tests proving the turn duration is read once as `10.0`, `MAX_DISCONNECTED_CATCHUP_SECONDS` is read once as `5.0`, turn duration supports test injection of 8/12/15, Cave Brute has Action 2/Smash/Fire Slam/basic Block, and the single prototype encounter has a stable ID/map key/integer position/glyph with `stationary=true`, orthogonal range 1, canonical identifiers, positive durations/cooldowns, and server-only enemy cooldowns.
- [ ] Add failing trigger tests proving Manhattan distance 1 accepts only above/below/left/right, diagonal and farther positions reject, and requested coordinates equal to the enemy coordinate are recognized as direct contact.
- [ ] Add failing deterministic tests: turn 1 starts with configured duration; both allowances initialize from actor Action; crossed boundaries reset rather than add; unused Actions are lost; consumption cannot go below zero; insufficient time rejects; a busy actor rejects; player and enemy may each be busy concurrently.
- [ ] Run `php tests/run.php` and capture the expected missing-class/config failures.
- [ ] Implement minimal validated config and pure engine. Put all prototype amounts under a clearly named `prototype_balance` section and document that they are not final formulas.
- [ ] Run focused tests, then `php tests/run.php`.
- [ ] Run PHP lint on every new PHP file and `git diff --check`.
- [ ] Review checkpoint: confirm no JavaScript timing authority, damage formula, DB write, or gameplay entry was introduced.

### Task 2: Add reviewed durable encounter persistence

**Files:**
- Create: `database/migrations/003_combat_foundation.sql`
- Create: `database/migrations/003_combat_foundation_verify.sql`
- Create: `ascii-quest/lib/CombatRepository.php`
- Create: `tests/CombatMigrationTest.php`
- Extend: `tests/CombatServiceTest.php` with repository fakes/contracts
- Modify: `tests/run.php`

**Required migration shape:**

```text
characters:
  life_state VARCHAR(16) ascii_bin NOT NULL DEFAULT 'alive'
  died_at DATETIME(6) NULL

combat_encounters:
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY
  character_id INT UNSIGNED NOT NULL FK characters(id)
  enemy_key VARCHAR(64) ascii_bin NOT NULL
  status VARCHAR(24) ascii_bin NOT NULL
  active_slot TINYINT UNSIGNED NULL
  enemy_max_hp/current_hp INT UNSIGNED
  timeline_elapsed_ms BIGINT UNSIGNED
  last_synchronized_at DATETIME(6)
  turn_number INT UNSIGNED
  turn_started_timeline_ms/next_enemy_decision_timeline_ms BIGINT UNSIGNED
  player_actions_remaining/enemy_actions_remaining SMALLINT UNSIGNED
  potion_key VARCHAR(64) ascii_bin NULL
  potion_charge_allowance/potion_charges_remaining SMALLINT UNSIGNED
  reward_gold/reward_experience INT UNSIGNED
  rewards_issued_at/death_processed_at/completed_at DATETIME(6) NULL
  killer_enemy_key VARCHAR(64) ascii_bin NULL
  version INT UNSIGNED NOT NULL
  created_at/updated_at DATETIME(6)
  UNIQUE(character_id, active_slot)

combat_actions:
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY
  encounter_id INT UNSIGNED NOT NULL FK combat_encounters(id)
  parent_action_id INT UNSIGNED NULL self-FK
  actor VARCHAR(16) ascii_bin
  action_kind VARCHAR(24) ascii_bin
  definition_key VARCHAR(64) ascii_bin
  request_token CHAR(36) ascii_bin NULL
  active_slot TINYINT UNSIGNED NULL
  state VARCHAR(24) ascii_bin
  started_timeline_ms/resolves_timeline_ms/cooldown_ready_timeline_ms/completed_timeline_ms BIGINT UNSIGNED NULL
  snapshot_weapon_key/snapshot_damage_type VARCHAR(64) ascii_bin NULL
  snapshot_base_damage INT UNSIGNED NULL
  snapshot_accuracy/snapshot_critical_chance DECIMAL(7,3) NULL
  snapshot_critical_damage INT NULL
  block_token CHAR(64) ascii_bin NULL
  block_expires_timeline_ms/block_attempted_timeline_ms BIGINT UNSIGNED NULL
  block_prompt_x/block_prompt_y DECIMAL(6,3) NULL
  resolved_damage/prevented_damage/healing_applied INT UNSIGNED NULL
  UNIQUE(encounter_id, request_token)
  UNIQUE(encounter_id, actor, active_slot)
  UNIQUE(block_token)

combat_events:
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY
  encounter_id INT UNSIGNED NOT NULL FK combat_encounters(id)
  sequence_number INT UNSIGNED NOT NULL
  event_type VARCHAR(64) ascii_bin NOT NULL
  message VARCHAR(500) NOT NULL
  emphasis VARCHAR(24) ascii_bin NULL
  created_at DATETIME(6) NOT NULL
  UNIQUE(encounter_id, sequence_number)
```

- [ ] Write a failing structural test that requires migration ID `003_combat_foundation`, dependency/preflight checks for 001/002, `characters.id` exact `INT UNSIGNED` verification, matching combat foreign keys, all three tables, unique active/request/actor/event guards, lifecycle columns, and no destructive delete/reset.
- [ ] Prove the structural test fails because migration 003 is absent.
- [ ] Write migration 003 and read-only verification using the existing stored-procedure convention. Add `CHECK` constraints for enumerated foundation states and numeric invariants where MariaDB supports them.
- [ ] Add failing repository contract tests requiring the exact lock order: selected/owned Champion first, active encounter second if present, then action/event rows as needed. Cover both an existing encounter mutation and movement-triggered creation where no encounter row exists yet. Also test owned lookup, active uniqueness, request-token replay, append-only event sequence, and atomic commit/rollback.
- [ ] Implement only the repository methods required by the contracts; use prepared statements and `FOR UPDATE` on mutations. No method may acquire an encounter/action lock before its Champion lock.
- [ ] Run `php tests/run.php`, PHP lint, and `git diff --check`.
- [ ] Review checkpoint: inspect the SQL manually; do not connect to or modify the live database.

### Task 3: Start combat atomically from movement and establish exploration locks

**Files:**
- Create: `ascii-quest/lib/CombatAccessGuard.php`
- Create: `ascii-quest/lib/CombatBootstrap.php`
- Create: initial `ascii-quest/lib/CombatService.php`
- Modify: `ascii-quest/game.php`
- Modify: `ascii-quest/js/game_controls.js`
- Modify: `ascii-quest/move_character.php`
- Modify: `ascii-quest/interact.php`
- Modify: `ascii-quest/sync_map_state.php`
- Modify: `ascii-quest/unlock_warp.php`
- Modify: `ascii-quest/travel_warp.php`
- Modify: `ascii-quest/allocate_stat.php`
- Modify: `ascii-quest/select_character.php`
- Modify: `ascii-quest/delete_character.php`
- Create/extend: `tests/CombatSecurityTest.php`, `tests/CombatServiceTest.php`
- Extend: `tests/ExplorationHudTest.js`

- [ ] Write failing movement/service tests: moving onto a valid floor whose final position is orthogonally in range persists that position and starts one owned Cave Brute encounter without changing HP/Mana; a diagonal final position does not start combat.
- [ ] Write a failing direct-contact test: requesting the occupied enemy coordinate leaves the Champion on the previous valid floor coordinate, keeps the enemy coordinate unchanged, and starts combat with no overlap.
- [ ] Write failing atomic/idempotency tests: movement update and encounter creation commit/roll back together; a repeated identical movement request or two-tab race returns/resumes the same encounter; the unique active constraint prevents a second encounter.
- [ ] Write failing refresh tests: load returns the same encounter ID, current enemy HP, action state, and potion state rather than recreating Cave Brute.
- [ ] Write failure tests for dead/other-user Champions and for every movement request after encounter start.
- [ ] Write failing guard tests for movement, interaction/transition, map sync mutation, Warp unlock/travel, stat allocation, Champion switching, fighting-Champion deletion, and duplicate entry. Verify ordinary exploration remains allowed with no encounter.
- [ ] Write failing character-management tests proving an account can create another Champion during combat, the new Champion cannot be selected/entered while the encounter remains unresolved, the active Champion/encounter is unchanged, Resume Battle remains available, and normal selection returns after closure.
- [ ] Prove failures before implementation.
- [ ] Implement `CombatAccessGuard` as one shared query/service decision, not copied SQL predicates. Add it server-side to every listed route before mutation.
- [ ] Add CSRF validation to `select_character.php` and the corresponding hidden token in `character_select.php` without weakening current ownership checks.
- [ ] Validate the configured stationary enemy position against the authoritative map layout/transitions/objects/Warp, and treat that coordinate as occupied in authoritative movement without changing ordinary collision rules.
- [ ] Add the configured Cave Brute position/glyph to the server-provided exploration map state and render it as an occupied enemy overlay. Test that it is visible without mutating the underlying map JSON or treating it as a chest/Warp interaction target.
- [ ] Refactor `move_character.php` only as needed to lock the selected/owned Champion first before resolving the request. Lock an existing encounter second; for a normal in-range move, persist the final floor position; for direct contact, retain the previous position. Invoke `startOrResumeForLockedMovement()` and commit position/encounter together.
- [ ] Return `combat_started: true` plus the sanitized combat state. Update the existing movement consumer to stop accepting exploration input immediately and reload/switch `game.php` into the durable combat center. Preserve keyboard and adjacent-click movement when no encounter starts.
- [ ] Make `game.php` choose a combat state when a non-closed encounter exists; do not build full UI yet.
- [ ] Run focused tests, all PHP tests, existing HUD tests, and changed-file lint.
- [ ] Review checkpoint: prove no `E` path starts combat, no player/enemy coordinate overlap is possible, controls are not merely hidden, and exploration works unchanged outside combat.

### Task 4: Implement authoritative synchronization and the Turn/Action engine

**Files:**
- Create: `ascii-quest/lib/CombatSynchronizer.php`
- Extend: `ascii-quest/lib/CombatService.php`
- Create: `ascii-quest/combat_state.php`
- Extend: `tests/CombatServiceTest.php`, `tests/CombatSecurityTest.php`

- [ ] Write failing fake-clock tests for initial 10-second window, one/multiple turn rollovers inside the permitted window, lost Actions, reset allowances, exact-boundary behavior, insufficient finish time, exhausted allowance despite ready cooldown, and player/enemy concurrent but same-actor sequential execution.
- [ ] Add exact disconnected-catch-up tests representing refresh, short network interruption, browser crash/reopen, and logout/login: a two-second gap advances the logical timeline by two seconds; a five-second gap advances five; a 30-second and a multi-hour gap each advance no more than five; `last_synchronized_at` moves to actual server now so an immediate next poll does not process another skipped chunk.
- [ ] Add capped-state tests proving encounter ID, Champion HP/Mana, enemy HP, turn/Actions, cooldowns, potion charges, Block, rewards, death, and Battle Info never reset; no player attack is synthesized; legitimate enemy damage reaching zero inside the processed five seconds still persists permanent death.
- [ ] Prove the tests fail against the initial service.
- [ ] Implement `CombatSynchronizer` with chronological logical-timeline ordering and the injected wall clock. Calculate `min(actual_gap, MAX_DISCONNECTED_CATCHUP_SECONDS)`, advance only that amount, then anchor `last_synchronized_at` to actual server now. Never use a browser timestamp or retain the skipped gap for later polls.
- [ ] Implement `combat_state.php` as an owned GET that synchronizes under transaction and returns only the projector output.
- [ ] Run focused/full PHP suites, lint, and diff check.
- [ ] Review checkpoint: inspect boundary ordering so an action allowed to finish exactly at turn end resolves before the next allowance reset.

### Task 5: Add manual weapon attack, cooldown, and offensive snapshots

**Files:**
- Create: `ascii-quest/lib/CombatEquipmentProvider.php`
- Create: `ascii-quest/lib/PrototypeCombatEquipmentProvider.php`
- Create: `ascii-quest/lib/CombatStateProjector.php`
- Create: `ascii-quest/combat_action.php`
- Extend: `ascii-quest/lib/CombatService.php`, `ascii-quest/lib/CombatSynchronizer.php`
- Extend: `tests/CombatServiceTest.php`, `tests/CombatSecurityTest.php`

- [ ] Write failing tests proving no auto attack occurs during synchronization; a valid pointer/request intent starts one weapon action; cooldown starts at click/start; one Action is consumed; own overlap rejects; too-late and duplicate requests reject/replay safely.
- [ ] Write provider tests: switching fake weapon state changes the next snapshot, not an already-started action; incoming defense is fetched at enemy-hit resolution rather than snapshotted at enemy start.
- [ ] Prove failures.
- [ ] Implement the provider boundary and one honestly labeled configured prototype weapon. Do not create item ownership records.
- [ ] Persist explicit offensive snapshot fields at action start and resolve only from them.
- [ ] Implement `combat_action.php` with POST, JSON intent, CSRF, canonical action key, UUID-format request token, session ownership, and server result.
- [ ] Implement projector allowlists; assert enemy cooldown/timing internals are absent.
- [ ] Run focused/full suites, lint, and diff check.
- [ ] Review checkpoint: search JavaScript/PHP endpoints for browser-supplied damage or duplicated CharacterStats formulas.

### Task 6: Add Cave Brute normal attack, Fire Slam, and simple AI

**Files:**
- Create: `ascii-quest/lib/CaveBrutePolicy.php`
- Create: `ascii-quest/lib/EnemyDefenseResolver.php`
- Create: `ascii-quest/lib/PrototypeEnemyDefenseResolver.php`
- Extend: `ascii-quest/lib/CombatSynchronizer.php`, `ascii-quest/lib/CombatService.php`
- Extend: `tests/CombatServiceTest.php`, `tests/CombatDefinitionTest.php`

- [ ] Write failing tests for Action 2, Smash, Fire Slam, internal cooldown start, skill-first readiness, normal fallback, insufficient Action/time, own-action overlap prevention, and no action after death/victory.
- [ ] Write a failing test that a player hit reaching Cave Brute performs one automatic, server-side, provisional Block roll with no reaction prompt and no client-supplied roll.
- [ ] Add a projection assertion that no enemy cooldown duration/end/ready state is visible.
- [ ] Prove failures.
- [ ] Implement the pure policy to return an intended definition key or wait; `CombatSynchronizer` performs the authoritative start under lock. Resolve minimal Cave Brute defense through `EnemyDefenseResolver` and the injected random source.
- [ ] Schedule the next decision from the earliest logical cooldown/resolution/turn position so connected polling and capped reconnect processing are deterministic and bounded.
- [ ] Resolve prototype enemy damage through server configuration and current Champion defense boundary; update stored HP without healing.
- [ ] Run focused/full suites, lint, and diff check.
- [ ] Review checkpoint: confirm there is one enemy only and no weighted/personality/boss/Slayer AI.

### Task 7: Add one-attempt bounded Block reaction

**Files:**
- Create: `ascii-quest/lib/BlockResolver.php`
- Create: `ascii-quest/lib/PrototypeBlockResolver.php`
- Create: `ascii-quest/combat_block.php`
- Extend: `ascii-quest/lib/CombatSynchronizer.php`, `ascii-quest/lib/CombatService.php`, `ascii-quest/lib/CombatStateProjector.php`
- Extend: `tests/CombatServiceTest.php`, `tests/CombatSecurityTest.php`

- [ ] Write failing tests: prompt exists only for an executing eligible enemy action; coordinates remain in configured normalized safe bounds; correct token before expiry records one attempt; replay is idempotent; second distinct attempt rejects; expired/missed prompt gets no active Block benefit; Block uses no Action.
- [ ] Write resolution tests showing current defense is fetched at hit resolution and the provisional resolver boundary receives `attempted=true/false` exactly once.
- [ ] Prove failures.
- [ ] Implement cryptographically random Block tokens and server-generated safe normalized coordinates. Persist them with the enemy action using logical expiry/attempt positions so refresh keeps the same opportunity without bypassing the catch-up cap.
- [ ] Implement provisional resolver values only in config/class, with comments and event wording that do not claim final balance.
- [ ] Implement CSRF/idempotent `combat_block.php`.
- [ ] Run focused/full suites, lint, and diff check.
- [ ] Review checkpoint: verify popup placement is presentation data while acceptance/outcome remains server authoritative.

### Task 8: Add instant per-fight potion charges

**Files:**
- Create: `ascii-quest/combat_potion.php`
- Extend: `ascii-quest/lib/CombatService.php`, `ascii-quest/lib/CombatStateProjector.php`
- Extend: `tests/CombatServiceTest.php`, `tests/CombatSecurityTest.php`

- [ ] Write failing tests: use is instant; consumes no Action; enforces configured per-fight allowance; caps HP at CharacterStats Maximum Life; does not change Mana; same request replay does not heal/decrement twice; second token after zero charges rejects; refresh does not reset charges.
- [ ] Add a test that swapping/reproviding an equivalent prototype potion cannot increase the encounter's snapshotted allowance.
- [ ] Prove failures.
- [ ] Implement charge decrement, HP update, command record, and event append in one locked transaction.
- [ ] Implement CSRF/idempotent `combat_potion.php`; return the persisted replay result for the same token.
- [ ] Run focused/full suites, lint, and diff check.
- [ ] Review checkpoint: verify no ordinary potion cooldown/cast/Action and no free heal on encounter creation.

### Task 9: Render combat center inside the existing HUD shell

**Files:**
- Create: `ascii-quest/js/combat_hud.js`
- Create: `tests/CombatHudTest.js`
- Modify: `ascii-quest/game.php`
- Modify: `ascii-quest/css/style.css`
- Modify: `ascii-quest/js/game_controls.js`
- Modify: `tests/ExplorationHudTest.js`

- [ ] Write failing structural tests for one unchanged left panel, one unchanged right Equipment/Loadout/Inventory shell, no Attack loadout slot, a mutually exclusive combat center, larger Champion/enemy ASCII regions, enemy name/HP, Turn indicator/bar, weapon attack/cooldown, reaction layer, effect list, loot row, and lower tabs.
- [ ] Write failing controller tests for Turn Bar interpolation from the server-projected logical timeline, player cooldown rendering, server disabled reasons, pending-click suppression, and no enemy cooldown DOM/state.
- [ ] Write failing integration tests that `game_controls.js` does not register movement/E handlers in combat and exploration mode remains unchanged.
- [ ] Prove failures with `node tests/CombatHudTest.js` and existing HUD tests.
- [ ] Implement one testable UMD combat module and conditional script loading/mode state in `game.php`. Do not add combat keyboard listeners.
- [ ] Add restrained dark/amber ASCII styles scoped under a combat root; keep desktop three-column rules and accepted exploration styles intact.
- [ ] Poll authoritative state at a modest interval; interpolate bars only between responses and reconcile every response/version.
- [ ] Run both Node suites, JS syntax checks, PHP lint, and diff check.
- [ ] Review checkpoint: manually inspect at approximately 1024px and verify the popup layer is inside the center arena, not the viewport.

### Task 10: Add skill cooldown and active-effect UI foundation

**Files:**
- Extend: `ascii-quest/config/combat.php`
- Extend: `ascii-quest/lib/CombatDefinitionRegistry.php`, `CombatService.php`, `CombatSynchronizer.php`, `CombatStateProjector.php`
- Modify: `ascii-quest/game.php`, `ascii-quest/js/combat_hud.js`, `ascii-quest/css/style.css`
- Extend: `tests/CombatServiceTest.php`, `tests/CombatHudTest.js`, `tests/ExplorationHudTest.js`

- [ ] Write failing tests for a single configured prototype skill/effect: manual click; own cooldown starts immediately; Action consumed only if offensive/skill action; effect duration starts at resolution; cooldown and effect endpoints differ; multiple-effect projection/rendering uses an array.
- [ ] Write UI tests for READY, cooling down, no Actions, insufficient turn time, busy actor, and inactive encounter states on existing Skill 1/2/3/Ultimate slots; empty slots remain empty/disabled.
- [ ] Prove failures.
- [ ] Implement only the one prototype mechanism necessary to prove the interface. Do not add a class catalogue or permanent skill assignment backend.
- [ ] Render separate cooldown and effect bars from server-projected logical timeline positions.
- [ ] Run full PHP/Node suites, lint/check, and diff check.
- [ ] Review checkpoint: confirm Potion remains independent and no normal Attack slot appeared in Loadout.

### Task 11: Integrate Battle Info / Server Info / Chat

**Files:**
- Modify: `ascii-quest/game.php`, `ascii-quest/js/combat_hud.js`, `ascii-quest/css/style.css`
- Extend: `ascii-quest/lib/CombatStateProjector.php`
- Extend: `tests/CombatHudTest.js`, `tests/CombatServiceTest.php`

- [ ] Write failing tests that combat labels the first lower tab Battle Info, selects it at encounter start, renders ordered server-created events, permits Server Info/Chat tab switching, and keeps polling/timers active while another tab is selected.
- [ ] Add restrained emphasis tests for the allowlisted values `critical`, `blocked`, `level_up`, and `dead`; reject raw HTML in messages/emphasis.
- [ ] Prove failures.
- [ ] Reuse the current bottom tab architecture and `gameLogMessages` contract where practical. Escape all event text and map emphasis to fixed CSS classes.
- [ ] Do not create a chat network/backend; preserve its placeholder honestly.
- [ ] Run full suites and checks.
- [ ] Review checkpoint: verify arena supplies current reaction data while Battle Info is historical only.

### Task 12: Implement exactly-once victory rewards and persistent loot phase

**Files:**
- Extend: `ascii-quest/lib/CombatSynchronizer.php`, `CombatService.php`, `CombatRepository.php`, `CombatStateProjector.php`
- Create: `ascii-quest/combat_close.php`
- Modify: `ascii-quest/game.php`, `ascii-quest/js/combat_hud.js`
- Extend: `tests/CombatServiceTest.php`, `tests/CombatHudTest.js`

- [ ] Write failing tests: enemy reaches zero once; no enemy/player action starts afterward; timer stops; encounter becomes `victory_loot`; configured Gold and raw EXP update once in the same transaction; refresh/retry cannot duplicate; no level formula is invoked; loot phase and empty physical-drop row remain until explicit close.
- [ ] Write concurrent/replayed close tests: close is CSRF-protected/idempotent, only valid in victory loot, and unlocks exploration without healing HP/Mana.
- [ ] Prove failures.
- [ ] Implement terminal-state ordering before later due actions, reward timestamp guard, atomic character reward update, reward event, and persisted loot phase.
- [ ] Implement `combat_close.php` and center UI close control; do not auto-return.
- [ ] Run full suites and checks.
- [ ] Review checkpoint: inspect SQL/state transitions for exactly one reward path and no draggable Gold/EXP.

### Task 13: Implement permanent death, selection state, and Slayer hook

**Files:**
- Extend: `ascii-quest/lib/CombatSynchronizer.php`, `CombatService.php`, `CombatRepository.php`, `CombatAccessGuard.php`, `CombatStateProjector.php`
- Modify: `ascii-quest/character_select.php`, `ascii-quest/select_character.php`, `ascii-quest/game.php`, `ascii-quest/account.php`, `ascii-quest/delete_character.php`
- Regression only: `ascii-quest/create_character.php`
- Extend: `tests/CombatServiceTest.php`, `tests/CombatSecurityTest.php`, `tests/CombatHudTest.js`

- [ ] Write failing tests: HP zero persists exactly zero; life state becomes DEAD once; encounter records killer key/ID context; no enemy/combat continuation; refresh retains death; no heal/respawn/Warp; dead selection card remains; Enter Dungeon/select rejects; active-combat deletion rejects.
- [ ] Add a permanent-history deletion regression: a delete request for a DEAD Champion is rejected server-side, the Champion row remains, and its defeated combat/killer history remains.
- [ ] Add a test that the durable defeated encounter exposes a typed internal Slayer candidate/result boundary but runs no Slayer qualification/reward logic.
- [ ] Prove failures.
- [ ] Implement atomic death/lifecycle update and immutable killer context. Do not delete the Champion. Make `delete_character.php` reject `life_state = 'dead'` independently of encounter status. Preserve Migration 003's `ON DELETE CASCADE` for ordinary eligible living-Champion deletion; do not use the foreign key as the DEAD-state guard.
- [ ] Render DEAD and Resume Battle/loot states safely in Character Selection. Main Menu navigation must not clear state.
- [ ] After logout/login, query owned non-closed encounters before allowing a different Champion selection; force Resume Battle/loot where applicable. Keep Character Creation available and leave newly created/other Champion cards visible with Enter Dungeon unavailable until closure.
- [ ] Run full suites and checks.
- [ ] Review checkpoint: search for any current HP assignment from Maximum Life outside character creation and explicit potion healing.

### Task 14: Close refresh, navigation, security, and idempotency gaps

**Files:**
- Extend all combat domain/endpoint/security tests and only the production files implicated by a failing test.

- [ ] Build a route matrix test covering unauthenticated, wrong owner, missing/invalid CSRF, dead, active combat, victory loot, closed, and ordinary exploration for each state-changing endpoint. Character creation is the explicit allowed management action during active combat and must leave the encounter unchanged.
- [ ] Add two-tab/concurrency tests for duplicate action, potion, Block, rewards, death, close, and movement-triggered encounter creation.
- [ ] Add disconnect/reconnect tests for 2-second, 5-second, 30-second, and multi-hour gaps. Verify advancement is respectively 2, 5, at most 5, and at most 5 seconds; state does not reset; immediate repoll does not replay skipped time; no player auto action appears; death inside the allowed window remains terminal.
- [ ] Add projection snapshot tests proving enemy cooldowns, future AI timing, DB metadata, and hidden resolver values never reach JS.
- [ ] Add Main Menu/logout/login/create/select/delete regressions proving creation is allowed without touching combat, another Champion cannot enter, the fighter cannot be deleted, Resume Battle remains, closure restores selection, and navigation is not a free escape.
- [ ] Run failures first and make only targeted corrections.
- [ ] Run all PHP/Node tests and static checks.
- [ ] Independent review checkpoint: inspect transaction boundaries; exact Champion → encounter → action/event lock ordering; catch-up cap/anchor behavior; request-token handling; terminal-state ordering; no-free-healing; and every server lock independently from the implementer pass.

### Task 15: Full verification and manual browser checklist

**Files:** No new feature work; fixes only for reproduced failures.

- [ ] Run `php tests/run.php` and record actual counts.
- [ ] Run `node tests/ExplorationHudTest.js` and record actual counts.
- [ ] Run `node tests/CombatHudTest.js` and record actual counts.
- [ ] Run `php -l` on every changed/new PHP file, including all endpoints and combat classes.
- [ ] Run `node --check ascii-quest/js/exploration_hud.js`, `node --check ascii-quest/js/game_controls.js`, and `node --check ascii-quest/js/combat_hud.js`.
- [ ] Validate any changed JSON with `php -r`/`json_decode` or an available JSON checker; Combat Milestone 1 does not require a map JSON change for its single configured prototype position.
- [ ] Run `git diff --check` and `git status --short --branch`.
- [ ] Confirm no migration was applied, no database credential/path was printed, and no commit/push occurred.
- [ ] Perform the approved live entry and refresh test only after migration application is separately authorized and completed by the user/deployment workflow.
- [ ] Manually verify pointer-only attacks, no combat hotkeys, 1024px HUD layout, larger ASCII figures, Turn Bar, player cooldowns, hidden enemy cooldowns, randomized bounded Block prompt, potion idempotency, Chat tab non-pause, exploration locks, capped reconnect/refresh resume, character creation without switching, victory loot phase, and permanent DEAD selection.
- [ ] Report created/modified files, migration status, automated results, manual steps, known prototype values, and deferred systems. Stop for user review.

---

## Required Test Matrix Traceability

| Requirement | Primary task/test |
|---|---|
| Configurable turn, reset/lost Actions, insufficient time | Task 1 / `CombatTurnEngineTest.php` |
| Orthogonal movement/contact starts exactly one encounter | Task 3 / movement + service tests |
| Diagonal non-trigger and enemy-coordinate non-overlap | Tasks 1 and 3 / trigger + movement tests |
| Refresh preserves encounter ID and Cave Brute HP/state | Tasks 3, 4, and 14 / service refresh tests |
| Offline gaps advance 2/5/max-5 seconds without repeated replay | Tasks 4 and 14 / fake-clock reconnect tests |
| Death within the allowed catch-up window remains permanent | Tasks 4, 13, and 14 / service death tests |
| Champion → encounter → action/event lock order | Tasks 2, 3, and 14 / repository/service order tests |
| Creation allowed; switching blocked until encounter closes | Tasks 3, 13, and 14 / character-management tests |
| No auto attack, manual weapon, cooldown at start, no overlap | Task 5 / `CombatServiceTest.php` |
| Offensive snapshot and current defense | Task 5 / provider/service tests |
| Cave Brute skill-first AI, hidden cooldowns | Task 6 / service/definition/projection tests |
| One bounded Block attempt, no Action | Task 7 / service + JS tests |
| Instant per-fight idempotent potion, no Action | Task 8 / service/security tests |
| Existing HUD shell, Turn/cooldown/effect UI | Tasks 9–10 / `CombatHudTest.js` |
| Battle Info auto-open; Chat does not pause | Task 11 / JS + service clock tests |
| Victory/reward once and retained loot phase | Task 12 / service/JS tests |
| Permanent DEAD and Slayer context | Task 13 / service/security/markup tests |
| Movement/Warp/allocation/change/menu locks | Tasks 3 and 14 / security tests |
| Refresh preserves state and advances only the capped interval | Tasks 4 and 14 / fake-clock service tests |
| Existing exploration unaffected | Every task / current 49 PHP + 25 HUD baseline suites |

## Implementation Review Stops

Do not treat this as one giant patch. The preferred review boundaries are:

1. Tasks 1–2: domain contract and migration only;
2. Tasks 3–4: entry/locks and authoritative timeline;
3. Tasks 5–8: player/enemy/Block/potion mechanics;
4. Tasks 9–11: HUD presentation;
5. Tasks 12–13: terminal states;
6. Tasks 14–15: independent hardening and verification.

At each boundary, provide the diff, actual test counts, migration status, and manual testing needs, then wait for approval.
