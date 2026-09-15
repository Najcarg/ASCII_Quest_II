# Combat Task 8 Instant Per-Fight Potion Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Add one instant, zero-Action, request-idempotent prototype Health Potion command whose snapshotted encounter charge, Champion healing, resolved command, and Battle Info event persist atomically.

**Architecture:** `CombatService::usePotion()` follows the existing combat-command transaction: Champion, Account mutex, Encounter, then Action/Event details; it synchronizes first and validates only the returned authoritative Character and Encounter aggregates. `CombatRepository` supplies one optimistic charge-decrement operation and one resolved potion-command constructor while the existing Champion HP CAS and event append complete the transaction. `CombatStateProjector` remains unchanged because its current three-field potion allowlist is already sufficient and private. A strict `combat_potion.php` endpoint accepts only CSRF plus a UUID request token.

**Tech Stack:** PHP, MariaDB/InnoDB, PDO, dependency-free PHP and Node test runners; no framework or new dependency.

**Spec:** `docs/superpowers/specs/2026-08-31-combat-milestone-1-design.md`

## Global Constraints

- Implement Combat Milestone 1 Task 8 only. Do not add the Task 9 HUD, JavaScript/CSS, rewards, victory, permanent death, Slayer, item persistence, Accuracy/Critical/Dodge, or later mechanics.
- Do not create a migration. Migration 003 already supplies `potion_key`, `potion_charge_allowance`, `potion_charges_remaining`, `healing_applied`, the `potion` action kind, request-token uniqueness, and ordered combat events.
- The encounter's potion key, charge allowance, and remaining charges are immutable/snapshotted combat inputs except for decrementing `potion_charges_remaining` after an accepted use. Configuration reload or equivalent provisioning never increases either stored charge field.
- Use the configured `prototype_health_potion` definition: one charge and foundation-only `prototype_healing = 50`. Do not scatter these values into service, repository, endpoint, or JavaScript.
- Derive Maximum Life only through `CharacterStats::calculate($lockedCharacter)['resources']['max_life']`. Do not duplicate its formula or modify Maximum Life, Maximum Mana, or current Mana.
- Potion use is pointer intent, instantaneous, costs zero Action, has no cooldown/cast, never occupies `active_slot`, and does not alter a pending player action.
- Full HP, zero HP, dead/unavailable Champion, inactive/no encounter, missing/mismatched potion, and zero charges reject. Full HP is not an accepted zero-heal command and never spends a charge or creates a row/event.
- Synchronize first. Domain rejection after successful synchronization commits only that authoritative synchronization, matching existing combat-command semantics. Any accepted-potion persistence failure rolls the entire transaction back.
- Preserve lock order: selected/owned Champion, Account combat mutex, active Encounter, then Action/Event detail rows. Do not add nested transactions.
- Browser input is never authoritative for Champion identity, potion identity, HP, healing, charges, Mana, Actions, timing, cooldown, outcome, or event text.

---

## Current Repository Findings

- `CombatService::startOrResumeForLockedMovement()` already snapshots `prototype_health_potion`, its configured charge allowance, and initial remaining charges into the new Encounter. Resume/state paths do not reconstruct them.
- `CombatStateProjector::project()` already exposes only `potion.key`, `potion.charge_allowance`, and `potion.charges_remaining`; it does not expose prototype healing, request UUIDs, command rows, or calculation internals.
- `CombatRepository::updateLockedCharacterCurrentHp()` already provides the locked Champion compare-and-swap write needed for healing.
- `CombatRepository::createAction()` already persists `action_kind = 'potion'`, `healing_applied`, nullable offensive/Block/cooldown fields, and a unique `(encounter_id, request_token)` replay key.
- `CombatRepository::appendEvent()` already allocates one locked per-encounter sequence and stores server-authored Battle Info text.
- `FakeCombatPdo` snapshots characters, encounters, actions, and events, so transaction rollback can prove atomicity. Task 8 adds only exact fake SQL/failure support for charge decrement and event insertion.

## Public Contracts

```php
public function usePotion(
    int $userId,
    int $characterId,
    string $requestToken,
): array;
```

```php
public function consumeLockedEncounterPotionCharge(
    int $encounterId,
    int $expectedRemaining,
    int $expectedVersion,
): bool;
```

```php
public function createResolvedPotionCommand(
    int $encounterId,
    string $definitionKey,
    string $requestToken,
    int $timelineMs,
    int $healingApplied,
): array;
```

The accepted endpoint body is exactly:

```json
{
  "csrf_token": "session token",
  "request_token": "UUID v4"
}
```

The durable accepted command is exactly a resolved player detail row: `actor = 'player'`, `action_kind = 'potion'`, `definition_key = encounter.potion_key`, client UUID `request_token`, `active_slot = NULL`, equal authoritative start/resolve/completion positions, actual `healing_applied`, and all cooldown/offensive/Block fields `NULL`.

### Task 1: Add locked charge consumption and resolved potion-command persistence

**Files:**

- Modify: `ascii-quest/lib/CombatRepository.php`
- Modify: `tests/CombatRepositoryTest.php`
- Test: `tests/CombatTask8Test.php`

**Interfaces:**

- Consumes: existing Champion and Encounter locks, `createAction()`, and Migration 003 columns.
- Produces: `consumeLockedEncounterPotionCharge()` and `createResolvedPotionCommand()` with the signatures above.

- [ ] **RED:** Using real `CombatRepository` with `FakeCombatPdo`, lock Champion → Account → Encounter and assert a one-charge encounter decrements `1 → 0` without changing `potion_charge_allowance`; a stale expected remaining/version returns `false`; zero expected remaining rejects before SQL. Assert the resolved command stores the encounter definition key, UUID, actual heal, equal logical positions, `active_slot = NULL`, and all unrelated fields `NULL`. Replay through `lockActionByRequestToken()` returns that same row.
- [ ] Run `php tests/run_task8_red.php`. Expected repository-family failure: `CombatRepository::consumeLockedEncounterPotionCharge() is missing.`
- [ ] **GREEN:** Add only these focused methods. Use a distinct placeholder for every native-PDO occurrence:

  ```sql
  UPDATE combat_encounters
     SET potion_charges_remaining = potion_charges_remaining - 1,
         version = version + 1
   WHERE id = :encounter_id
     AND version = :expected_version
     AND potion_charges_remaining = :expected_remaining
     AND potion_charges_remaining > 0
  ```

  Reject `expectedRemaining <= 0` before SQL. Implement `createResolvedPotionCommand()` by delegating to `createAction()` with the exact resolved command shape; do not add a second idempotency mechanism.
- [ ] Extend `FakeCombatPdo` only for the exact charge SQL, compare-and-swap behavior, rollback state, and injected charge-update failure. Do not accept arbitrary Encounter updates.
- [ ] Run `php tests/run_task8_red.php`, `php tests/run.php`, `php -l ascii-quest/lib/CombatRepository.php`, `php -l tests/CombatRepositoryTest.php`, `php -l tests/CombatTask8Test.php`, and `git diff --check`.
- [ ] **Review checkpoint:** verify no charge increase API exists, allowance is never written, native PDO placeholders are not reused, Encounter lock is required, and request-token uniqueness remains the final replay guard.

### Task 2: Implement authoritative instant potion use

**Files:**

- Modify: `ascii-quest/lib/CombatService.php`
- Modify: `tests/CombatTask8Test.php`
- Use unchanged: `ascii-quest/lib/CharacterStats.php`

**Interfaces:**

- Consumes: `CombatSynchronizer::synchronize()`, `CharacterStats::calculate()`, Task 1 repository APIs, existing `updateLockedCharacterCurrentHp()`, `appendEvent()`, and `CombatStateProjector::project()`.
- Produces: `CombatService::usePotion(int $userId, int $characterId, string $requestToken): array`.

- [ ] **RED:** Assert a valid use at logical timeline `T` heals from post-synchronization current HP, consumes no Action, leaves Mana/enemy HP/pending weapon/Block/cooldown unchanged, decrements one charge without changing allowance, and creates one resolved non-active potion command at `T`. Cover zero player Actions, CharacterStats Maximum Life cap, full-HP rejection, zero-HP/inactive/no-encounter rejection, and configuration re-read/reprovisioning that cannot restore spent charges.
- [ ] Run `php tests/run_task8_red.php`. Expected service-family failure: `CombatService::usePotion() is missing.`
- [ ] **GREEN:** Validate UUID v4 before transaction, then follow this command order:

  ```php
  $decision = $guard->beginAtomic(CombatAccessGuard::GAME_LOAD, $userId, $characterId);
  $sync = $this->synchronizer->synchronize($encounter, $character, $playerAllowance, $enemyAllowance);
  $this->persistSynchronization($encounterId, $sync['encounter'], $encounter['version']);
  $replay = $this->repository->lockActionByRequestToken($encounterId, $requestToken);
  // Validate and return an existing resolved player potion command before charge/full-HP checks.
  // Otherwise validate active state, current_hp > 0, encounter potion key/definition, charges > 0, and current_hp < max_life.
  $healingApplied = min((int) $potion['prototype_healing'], $maximumLife - $currentHp);
  // Champion HP CAS, charge CAS/version increment, resolved command, one server event, projection, commit.
  ```

  The event is server-authored as `You recover {healing_applied} HP with Health Potion.`, uses `event_type = 'potion_used'`, and has `emphasis = NULL`. Update the in-memory Character HP, Encounter remaining charges, and version before projection.
- [ ] On `DomainException` after synchronization persistence, commit only the synchronization and rethrow. On any accepted-persistence/concurrency failure, roll back the entire request. Never update Mana or player Action allowance.
- [ ] Run the focused tests after each valid/replay/rejection group, then run `php tests/run.php`, lint service/tests, and run `git diff --check`.
- [ ] **Review checkpoint:** verify replay occurs before full-HP/zero-charge checks, Maximum Life has no local formula, one Action is never consumed, the pending weapon stays active, and synchronization rejection semantics match Task 5/7.

### Task 3: Prove event idempotency and complete rollback atomicity

**Files:**

- Modify: `tests/CombatRepositoryTest.php`
- Modify: `tests/CombatTask8Test.php`
- Modify production only if a retained test exposes a real defect in Task 1/2 boundaries.

**Interfaces:**

- Consumes: the Task 2 transaction and existing fake rollback snapshots.
- Produces: retained failure-injection coverage for every accepted-potion write.

- [ ] **RED or retained GREEN:** Inject failure separately at Champion HP CAS, charge decrement, potion command insertion, event insertion, and final projection. For each case snapshot Character, Encounter, actions, and events before the request and assert exact equality after rejection/rollback. Assert same-token replay produces no second event and a different token at zero charges produces no event.
- [ ] Extend only the test fake with explicit `failPotionChargeUpdate` and `failEventInsert` switches. The fake must recognize only the Task 1 SQL and continue restoring Character/Encounter/action/event collections on rollback.
- [ ] If a retained test fails, use the existing service/repository boundaries for the smallest correction. Do not add nested transactions, schemas, or a new event/idempotency abstraction.
- [ ] Run `php tests/run_task8_red.php`, `php tests/run.php`, lint every changed PHP file, and run `git diff --check`.
- [ ] **Review checkpoint:** confirm the only accepted outcomes are all four durable writes or none, while a domain rejection may retain already-authoritative synchronization only.

### Task 4: Retain the existing safe potion projection

**Files:**

- Test: `tests/CombatTask8Test.php`
- Keep unchanged unless a test proves a defect: `ascii-quest/lib/CombatStateProjector.php`

**Interfaces:**

- Consumes: existing `CombatStateProjector::project()`.
- Produces: retained allowlist/privacy coverage; no new public fields.

- [ ] Assert the exact public shape remains `['key', 'charge_allowance', 'charges_remaining']`, spent remaining charges survive repeated state projections, and `prototype_healing`, `healing_applied`, request tokens, raw potion commands, Maximum Life calculation inputs, and server configuration remain absent recursively.
- [ ] Run `php tests/run_task8_red.php`. This family is expected to pass before production implementation because the current projector already meets Task 8.
- [ ] Make no projector production change if the assertions pass. If they fail, stop and review the unexpected privacy contract rather than broadening projection.
- [ ] Run `php tests/run.php`, lint the Task 8 test, and run `git diff --check`.
- [ ] **Review checkpoint:** confirm later HUD work receives authoritative remaining charges without receiving healing authority.

### Task 5: Add the strict pointer-intent endpoint

**Files:**

- Create: `ascii-quest/combat_potion.php`
- Modify: `tests/CombatTask8Test.php`

**Interfaces:**

- Consumes: `CombatBootstrap::service(getDb())->usePotion(session user, session character, request token)`.
- Produces: POST-only JSON endpoint accepting exactly `csrf_token` and `request_token`.

- [ ] **RED:** Add subprocess endpoint tests for wrong method, missing selected session, malformed/non-object JSON, missing/extra keys, CSRF failure, malformed/non-v4 UUID, session-derived identity, and safe 404/422/500 errors. Iterate an authoritative-value matrix containing HP, Maximum Life, healing, charges, Mana, Actions, timeline/completion, cooldown, potion key/outcome, user ID, and character ID; every extra key must return `400` without invoking the service.
- [ ] Run `php tests/run_task8_red.php`. Expected endpoint-family failure: `combat_potion.php is missing.`
- [ ] **GREEN:** Mirror the established combat endpoints: `session_start()`, JSON and no-cache headers, session user/Champion, POST, exact sorted keys, `hash_equals()` CSRF, lowercase UUID-v4 validation, session-only identity, service call, and safe errors (`401`, `405`, `400`, `403`, `422`, `404`, generic `500`). Log original domain/internal details only through `error_log()`.
- [ ] Run endpoint-focused tests, `php tests/run_task8_red.php`, `php tests/run.php`, `php -l ascii-quest/combat_potion.php`, and `git diff --check`.
- [ ] **Review checkpoint:** confirm the body carries intent only, no potion key is accepted, and no internal detail reaches JSON.

### Task 6: Register permanent coverage and complete the Task 8 checkpoint

**Files:**

- Modify: `tests/run.php`
- Modify after all GREEN: `docs/tasks/current-task.md`
- Retain: `tests/CombatTask8Test.php`
- Retain: `tests/run_task8_red.php`

**Interfaces:**

- Consumes: all completed Task 8 behavior.
- Produces: permanent regression registration and accurate checkpoint tracking.

- [ ] Only after the isolated Task 8 suite reaches zero failures, add `CombatTask8Test.php` to `tests/run.php`.
- [ ] Run `php tests/run_task8_red.php`, `php tests/run.php`, and `node tests/ExplorationHudTest.js`; require zero failures.
- [ ] PHP-lint every created/modified PHP file, run `node --check ascii-quest/js/game_controls.js`, `git diff --check`, `git status --short --branch`, and `git diff --stat`.
- [ ] Audit the complete diff for client-authored healing/HP/charge/timing authority, charge resets, Action/cooldown changes, duplicate events, current-Mana writes, new schema, HUD/JS/CSS/maps, rewards/victory/death, secrets, debug output, or unrelated refactoring.
- [ ] Update the tracker to `TASK 8 IMPLEMENTED — AWAITING CHECKPOINT REVIEW`, recording actual counts, local/uncommitted/undeployed state, no migration, and that Combat Milestone 1 Task 9 has not started.
- [ ] **Review checkpoint:** stop with the complete local diff. Do not commit, push, deploy, access live systems, or start Task 9 without explicit approval.

## RED Traceability

| Approved behavior | Initial RED family |
|---|---|
| Instant, zero Action, works at zero allowance, pending weapon unchanged | Valid potion command |
| Configured full/partial heal capped by CharacterStats Maximum Life; Mana unchanged | Healing and full-HP boundary |
| One charge, allowance immutable, refresh/config cannot replenish | Replay and snapshotted charges |
| Same UUID replays once; different UUID at zero rejects | Replay and snapshotted charges |
| Resolved command identity/timeline/healing fields | Repository persistence |
| One restrained server event with actual healing | Event and rollback atomicity |
| Post-synchronization HP; active/eligible Champion only | Synchronization and lifecycle safety |
| HP/charge/action/event all-or-nothing | Event and rollback atomicity |
| Exact three-field safe potion state | Projection privacy |
| POST/session/CSRF/UUID/strict allowlist/safe errors | Endpoint security |

## Completed Writing-Plans Self-Review

- [x] **Specification coverage:** Every approved Task 8 charge, instant-use, CharacterStats cap, full-HP, idempotency, event, projection, endpoint, synchronization, and rollback rule maps to a numbered task and RED family.
- [x] **Placeholder scan:** No `TODO`, `TBD`, “implement appropriately,” unresolved signature, or unspecified error boundary remains.
- [x] **Interface consistency:** Task 2 consumes only the exact repository APIs produced by Task 1; Task 3 hardens the same transaction; Task 5 calls the exact `usePotion()` signature produced by Task 2.
- [x] **Schema consistency:** Migration 003 supports every planned field and constraint. No Migration 005 or other schema change is planned.
- [x] **Projection consistency:** The existing projector already exposes exactly the required encounter-snapshotted potion state, so the plan adds regression coverage and no speculative production edit.
- [x] **Transaction consistency:** Synchronization persists first; accepted HP/charge/command/event writes share that transaction; replays are checked before charge/full-HP rejection; domain rejection preserves only legitimate synchronization.
- [x] **Scope consistency:** No Task 9 HUD, JavaScript/CSS, real items, rewards/victory, death/Slayer, Accuracy/Critical/Dodge, migration, deployment, or live-system access is included.
