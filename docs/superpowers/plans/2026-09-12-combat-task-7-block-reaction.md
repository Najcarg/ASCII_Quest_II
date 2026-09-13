# Combat Task 7 Block Reaction Implementation Plan

> **For Codex:** REQUIRED SUB-SKILL: Use `superpowers:executing-plans` to implement this plan task-by-task, with `superpowers:test-driven-development` for every behavior change and a review checkpoint after each task.

**Goal:** Add one durable, pointer-intent Block opportunity for each executing Cave Brute action, accept at most one timely attempt idempotently, and apply a provisional player Block at enemy-hit resolution without adding HUD, potion, reward, death, or later combat behavior.

**Architecture:** `CombatSynchronizer` creates the prompt exactly once when it creates an enemy action. The prompt remains on that incoming enemy `combat_actions` row; an accepted pointer command is a separate resolved player `block` action linked through `parent_action_id`. `CombatService::attemptBlock()` synchronizes first, then validates and records both sides atomically inside the existing Champion -> Account -> Encounter -> Action transaction. `CombatActionResolver` delegates incoming damage to a focused randomized `BlockResolver` using current defense at resolution. `CombatStateProjector` exposes one strict reaction-prompt allowlist. `CombatBootstrap` shares the existing `CombatRandomSource` and `CombatEquipmentProvider` objects across these boundaries.

**Tech Stack:** PHP, MariaDB/InnoDB, dependency-free PHP test runner.

---

## Approved Boundaries and Repository Adaptation

- Implement Combat Milestone 1 Task 7 only. Do not implement potion, combat HUD JavaScript/CSS, rewards, victory, permanent death, Slayer, real equipment persistence, Accuracy/Critical/Dodge, or keyboard Block controls.
- Do not create a migration. Migration 003 already provides `block_token`, `block_expires_timeline_ms`, `block_attempted_timeline_ms`, `block_prompt_x`, `block_prompt_y`, `resolved_damage`, `prevented_damage`, `parent_action_id`, request-token uniqueness, and Block-token uniqueness.
- Both Cave Brute prototype actions are eligible. A prompt exists only while its enemy action is pending, unattempted, and before its logical expiry.
- Set `block_expires_timeline_ms` to the enemy action's `resolves_timeline_ms`. “Before expiry” is strict: an attempt is valid only when `timeline_elapsed_ms < block_expires_timeline_ms`.
- The incoming enemy action owns only the prompt and `block_attempted_timeline_ms`; its `request_token` remains `NULL`. An accepted command creates a separate resolved child row with `actor = 'player'`, `action_kind = 'block'`, `definition_key = 'basic_block'`, `parent_action_id` equal to the incoming enemy action ID, the client UUID in `request_token`, `state = 'resolved'`, and `active_slot = NULL`.
- The Block child row uses the authoritative accepted logical position for `started_timeline_ms`, `resolves_timeline_ms`, and `completed_timeline_ms`. It has no cooldown, offensive snapshot, prompt, damage result, healing result, or active slot.
- A repeated identical request token replays the same persisted child command. A different request token after `block_attempted_timeline_ms` is set rejects. The existing `(encounter_id, request_token)` constraint provides request idempotency; the locked parent prompt/child state serializes competing attempts.
- Prompt placement uses configured normalized thousandth bounds of `100..900` for both axes and `CombatRandomSource::integer()` twice. Production converts the integers to three-decimal normalized values. `CombatRandomSource::token(32)` supplies the 64-character lowercase hexadecimal Block token.
- Enemy defensive Block and player reaction Block are separate mechanics and separately owned configuration contracts. `PrototypeEnemyDefenseResolver` consumes only `cave_brute.block` when the Cave Brute automatically defends against Champion attacks. `PrototypeBlockResolver` consumes only the dedicated `player_reactions.basic_block` definition when the Champion reacts to an incoming Cave Brute action. The two definitions may currently contain the same provisional numbers, but neither resolver may read the other's configuration.
- The player reaction `basic_block` definition owns the prompt placement bounds and the Task 7 foundation values: `prototype_chance_percent = 20` and `prototype_reduction_percent = 50`. With `attempted=false`, `PrototypeBlockResolver` performs no Block roll and applies no active Block benefit. With `attempted=true`, it calls the injected `CombatRandomSource::integer(1, 100)` exactly once; rolls `1..20` succeed and `21..100` fail.
- `PrototypeBlockResolver` composes the existing `ChampionDamageResolver`: passive Toughness/Fire resistance resolves first from current defense, then a successful provisional Block reduces that applied amount by 50%, with positive incoming damage still applying at least 1. A missed or failed Block returns the passive result unchanged. It returns final `incoming_damage`, total `prevented_damage`, `applied_damage`, and `blocked`, while the roll/chance remain server-only. These values and formulas are foundation-only, not final shield/equipment rules.
- Preserve account-wide mutation lock order: Champion, Account mutex, Encounter, then Action/Event details. No nested transaction and no browser timestamp or calculated outcome.

## Public Contracts

```php
interface BlockResolver
{
    /** @return array{blocked:bool,incoming_damage:int,prevented_damage:int,applied_damage:int} */
    public function resolve(
        array $incomingAction,
        array $currentDefense,
        bool $attempted,
    ): array;
}
```

```php
public function attemptBlock(
    int $userId,
    int $characterId,
    int $enemyActionId,
    string $blockToken,
    string $requestToken,
): array;
```

The public `reaction_prompt` value is either `null` or exactly:

```php
[
    'enemy_action_id' => int,
    'definition_key' => string,
    'name' => string,
    'damage_type' => string,
    'resolves_timeline_ms' => int,
    'expires_timeline_ms' => int,
    'block_token' => string,
    'x' => float,
    'y' => float,
]
```

No raw action, cooldown, snapshot, random roll, defense, future AI decision, or repository field may be merged into this projection.

### Task 1: Lock the provisional Block definition and pure resolver contract

**Files:**

- Create: `ascii-quest/lib/BlockResolver.php`
- Create: `ascii-quest/lib/PrototypeBlockResolver.php`
- Modify: `ascii-quest/config/combat.php`
- Modify: `ascii-quest/lib/CombatDefinitionRegistry.php`
- Modify: `tests/CombatDefinitionTest.php`
- Modify: `tests/CombatTask7Test.php`
- Modify: `tests/run.php`

- [ ] **RED:** Assert `cave_brute.block.server_only` remains the separately owned Cave Brute automatic-defense definition with provisional `20`/`50`, while `player_reactions.basic_block.server_only` independently owns the player reaction provisional `20`/`50` values and X/Y safe thousandth bounds `100..900`. Alter each definition independently and prove `PrototypeEnemyDefenseResolver` and `PrototypeBlockResolver` remain unaffected by changes to the other definition. Add deterministic player resolver tests: `attempted=false` returns passive damage with zero random calls; `attempted=true` with roll 20 succeeds and applies the 50% reduction; `attempted=true` with roll 21 fails and returns passive damage; each attempted case rolls exactly once. Also test minimum positive damage 1 and independent invalid result/config rejection.
- [ ] Run `php tests/run.php` and retain failures for the missing config keys/classes.
- [ ] **GREEN:** Add the interface and a `PrototypeBlockResolver` that injects `ChampionDamageResolver`, `CombatRandomSource`, and only the validated player reaction Block definition. Call passive damage exactly once. Skip randomness when not attempted; otherwise roll exactly once and apply the configured reduction only on success. Validate/expose player reaction definitions through a separate `CombatDefinitionRegistry::playerReaction()` API; retain independent enemy Block validation and do not duplicate Toughness/Fire formulas.
- [ ] Run `php tests/run.php`, lint the two new classes and modified PHP files, then run `git diff --check`.
- [ ] **Review checkpoint:** confirm both 20%/50% definitions remain separately centralized and explicitly provisional, each resolver consumes only its owning definition, the player resolver is pure, missed/failed prompts retain passive defense, successful attempts reduce damage, and no final equipment formula was added.

### Task 2: Persist one prompt when the enemy action starts

**Files:**

- Modify: `ascii-quest/lib/CombatRepository.php`
- Modify: `ascii-quest/lib/CombatSynchronizer.php`
- Modify: `tests/CombatRepositoryTest.php`
- Modify: `tests/CombatTask7Test.php`

- [ ] **RED:** For a native zero-gap Cave Brute start, assert one pending enemy action stores a 64-character server token, expiry equal to resolution, and normalized X/Y within configured safe bounds. Project/state twice without starting another action and assert token, expiry, X, and Y are identical. Assert player/resolved/ineligible actions have no prompt.
- [ ] Run `php tests/run.php` and retain the prompt-persistence failures.
- [ ] **GREEN:** Extend the existing action insert allowlist/SQL with the five existing Block columns. Player actions pass `NULL` for them. In `startEnemyActionAtCursor()`, call the injected shared random source once for `token(32)` and twice for bounded integers, convert coordinates to three-decimal normalized values, and include the fields in the single enemy-action insert. Never update/reroll an existing action on refresh.
- [ ] Run focused/full PHP tests, lint repository/synchronizer/tests, and run `git diff --check`.
- [ ] **Review checkpoint:** inspect SQL against Migration 003; confirm expiry never exceeds resolution, action creation remains atomic, and no migration/configured values are scattered.

### Task 3: Add the atomic one-attempt repository operation

**Files:**

- Modify: `ascii-quest/lib/CombatRepository.php`
- Modify: `tests/CombatRepositoryTest.php`
- Modify: `tests/CombatTask7Test.php`

- [ ] **RED:** Add repository tests for locking the target enemy action and its child actions only after Encounter lock. Assert a correct timely attempt creates exactly one resolved player `block` child with `definition_key = 'basic_block'`, `parent_action_id` equal to the enemy action, client UUID `request_token`, authoritative equal start/resolve/completion positions, and `active_slot = NULL`, while the parent enemy `request_token` remains `NULL`. Cover exact child-token replay, wrong token, expiry, distinct second UUID, competing requests, and rollback of both the child insert and parent attempt marker.
- [ ] Run `php tests/run.php` and retain missing repository method/SQL failures.
- [ ] **GREEN:** Add a focused locked parent-action lookup and a focused `createResolvedBlockCommand()` repository insert using the existing columns:

  ```sql
  INSERT INTO combat_actions (
      encounter_id, parent_action_id, actor, action_kind, definition_key,
      request_token, active_slot, state, started_timeline_ms,
      resolves_timeline_ms, completed_timeline_ms
  ) VALUES (
      :encounter_id, :parent_action_id, 'player', 'block', 'basic_block',
      :request_token, NULL, 'resolved', :attempted_timeline_ms,
      :attempted_timeline_ms, :attempted_timeline_ms
  )
  ```

  Then mark only the locked incoming action:

  ```sql
  UPDATE combat_actions
     SET block_attempted_timeline_ms = :attempted_timeline_ms
   WHERE id = :action_id
     AND encounter_id = :encounter_id
     AND actor = 'enemy'
     AND state = 'pending'
     AND block_token = :block_token
     AND block_attempted_timeline_ms IS NULL
     AND :attempted_timeline_ms < block_expires_timeline_ms
  ```

  Reuse `lockActionByRequestToken()` for replay identity and validate that the replay is the resolved `block` child for the requested parent. Do not let its single-row lock authorize arbitrary action resolution. Preserve the broad-lock checks already used by resolvers. Both writes remain in the caller transaction, so any later failure rolls both back.
- [ ] Extend `FakeCombatPdo` only for the exact SQL and rollback fields. Run full tests/lint/diff check.
- [ ] **Review checkpoint:** confirm Encounter precedes parent/child detail locks, the enemy request token is untouched, the child owns the UUID, the database uniqueness constraint remains the final retry guard, and parent-row serialization permits only one accepted child.

### Task 4: Add transactional `CombatService::attemptBlock()`

**Files:**

- Modify: `ascii-quest/lib/CombatService.php`
- Modify: `tests/CombatServiceTest.php`
- Modify: `tests/CombatTask7Test.php`

- [ ] **RED:** Test correct timely intent, child-command persistence, replay of the same child identity, distinct second request, wrong token, expiry, cross-owner/Champion rejection, zero player Actions, and a concurrent pending player weapon action. Assert successful Block changes neither player Action allowance nor player weapon state, leaves the enemy request token `NULL`, and rejected attempts create no child or Block marker.
- [ ] Run `php tests/run.php` and retain missing service method failures.
- [ ] **GREEN:** Follow `startPlayerAction()` transaction style: validate UUID/token shapes, begin the shared guard transaction, synchronize first, persist the authoritative synchronization, reject absent/non-active encounters, replay and validate an existing child command by request token, lock/validate the selected pending enemy action and existing child state, compare the synchronized logical timeline strictly before expiry, insert the resolved child command, mark the parent attempted, project state, and commit. A domain rejection may commit already-authoritative synchronization exactly as the existing command does; it must not partially persist either side of the attempt.
- [ ] Run focused/full tests, lint service/tests, and run `git diff --check`.
- [ ] **Review checkpoint:** confirm the browser supplies intent only, no Action is consumed, player execution is uninterrupted, and replay returns persisted state.

### Task 5: Apply Block exactly once at enemy-hit resolution

**Files:**

- Modify: `ascii-quest/lib/CombatActionResolver.php`
- Modify: `tests/CombatTask7Test.php`
- Modify: `tests/CombatServiceTest.php`

- [ ] **RED:** Use a recording resolver to prove missed actions pass `attempted=false`, accepted parent actions pass `attempted=true`, current defense is read at resolution, changing defense after action start changes resolver input, and one resolved enemy action invokes the resolver once. Separately use deterministic `PrototypeBlockResolver` rolls 20 and 21 to prove an accepted timely command can respectively succeed with reduction or fail without active reduction. Repeat synchronization at the same server time and assert no second resolver/random call, HP decrement, or result rewrite.
- [ ] Run `php tests/run.php` and retain failures showing enemy resolution bypasses `BlockResolver`.
- [ ] **GREEN:** Inject `BlockResolver` into `CombatActionResolver`. In the enemy branch, read `currentDefense()` once at hit resolution, derive `attempted` solely from the incoming action's persisted `block_attempted_timeline_ms !== null`, call `BlockResolver::resolve()` once, CAS Champion HP, and persist the final result through `resolveLockedActionWithDamage()`. Keep the pending-state guard as the exactly-once boundary; never expose the successful/failed roll.
- [ ] Run full tests/lint/diff check.
- [ ] **Review checkpoint:** confirm no defense snapshot, no browser outcome, no duplicate damage formula, and no reprocessing of resolved actions.

### Task 6: Project one safe durable reaction prompt

**Files:**

- Modify: `ascii-quest/lib/CombatStateProjector.php`
- Modify: `tests/CombatSecurityTest.php`
- Modify: `tests/CombatTask7Test.php`

- [ ] **RED:** Seed eligible, attempted, expired, resolved, player, and malformed actions. Assert only one eligible pending enemy action becomes the exact nine-field `reaction_prompt` allowlist. Reproject unchanged state and assert stable token/coordinates/expiry. Recursively reject cooldown, snapshots, prototype damage, future AI schedule, Block chance/roll/result, raw defense, request token, and repository metadata.
- [ ] Run `php tests/run.php` and retain the current-null projection failure.
- [ ] **GREEN:** Select the first pending enemy action in locked action order whose prompt fields are complete, unattempted, and whose expiry is strictly after the encounter logical timeline. Resolve display name/type from `CombatDefinitionRegistry`, build only the nine fields above, and keep `null` for every ineligible state.
- [ ] Run full tests/lint/diff check.
- [ ] **Review checkpoint:** recursively inspect serialized state; only the single-use Block token and normalized presentation fields cross the public boundary.

### Task 7: Wire the shared production dependencies

**Files:**

- Modify: `ascii-quest/lib/CombatBootstrap.php`
- Modify: `tests/CombatServiceTest.php`
- Modify: `tests/CombatTask7Test.php`

- [ ] **RED:** Use `CombatBootstrap::serviceForRepository()` with deterministic clock/equipment/random dependencies. Assert the same random object supplies prompt token/coordinates, the same equipment provider supplies current defense, and a due accepted Block resolves through the production graph.
- [ ] Run `php tests/run.php` and retain production-wiring failure.
- [ ] **GREEN:** Construct one `PrototypeBlockResolver` from the existing `PrototypeChampionDamageResolver`, the shared `CombatRandomSource`, and validated `player_reactions.basic_block` definition. Keep `PrototypeEnemyDefenseResolver` independently wired to the Cave Brute enemy definition through its existing action-resolution contract. Pass the player reaction resolver into `CombatActionResolver`; pass that same random source into `CombatSynchronizer`, which reads prompt bounds only from the player reaction definition. Preserve the single `CombatEquipmentProvider` instance already shared by service and action resolver.
- [ ] Run full tests/lint/diff check.
- [ ] **Review checkpoint:** confirm one random subsystem, one damage pipeline, one equipment provider, explicit dependencies, and no service locator/global state.

### Task 8: Add strict `combat_block.php` intent endpoint

**Files:**

- Create: `ascii-quest/combat_block.php`
- Modify: `tests/CombatSecurityTest.php`
- Modify: `tests/CombatTask7Test.php`

- [ ] **RED:** Add subprocess endpoint tests for POST-only, authenticated selected Champion, CSRF, valid positive enemy action ID, 64-character hexadecimal Block token, canonical UUID request token, malformed JSON, exact key allowlist, ownership, and generic 404/422/500 errors. Add an extra-key matrix for character/user IDs, coordinates, damage/prevented damage, outcome, Block chance/roll, expiry/timestamps, defense, HP, timing, cooldown, and Actions.
- [ ] Run the focused endpoint tests and retain the missing endpoint failure.
- [ ] **GREEN:** Mirror `combat_action.php`: accept exactly `csrf_token`, `enemy_action_id`, `block_token`, and `request_token`; obtain user/Champion only from session; call `CombatBootstrap::service(getDb())->attemptBlock()`; log original domain/internal detail server-side; return safe JSON and established HTTP codes.
- [ ] Run full tests and lint endpoint/security tests; run `git diff --check`.
- [ ] **Review checkpoint:** confirm no client-authored authoritative value reaches the service and no keyboard/JavaScript Block implementation exists.

### Task 9: Harden replay, races, rollback, and scope

**Files:**

- Modify: `tests/CombatRepositoryTest.php`
- Modify: `tests/CombatServiceTest.php`
- Modify: `tests/CombatSecurityTest.php`
- Modify: `tests/CombatTask7Test.php`

- [ ] **RED or retained GREEN:** Exercise same-token retries and distinct-token/two-tab attempts around expiry and resolution; inject prompt insert, attempt update, HP CAS, action result, and final encounter-version failures. Assert complete rollback of prompt/attempt/action/HP/timeline/allowance/version state and exactly one Block resolver call after successful resolution.
- [ ] Add scope assertions proving no reaction token is created for player actions, no player Action consumption, no potion/reward/victory/death/Slayer mutation, no Accuracy/Critical/Dodge branch, no UI/JS/CSS/map change, and no migration.
- [ ] If all new assertions pass, make no production refactor. If a test exposes a defect, use systematic debugging and make only the smallest correction within existing boundaries.
- [ ] Run `php tests/run.php`, `node tests/ExplorationHudTest.js`, PHP lint for every changed PHP file, `node --check ascii-quest/js/game_controls.js`, and `git diff --check`.
- [ ] **Review checkpoint:** audit lock order, replay identity, strict expiry, exactly-once resolver/damage, refresh persistence, projection privacy, and every scope exclusion.

### Task 10: Final local verification and checkpoint documentation

**Files:**

- Modify only after all GREEN: `docs/tasks/current-task.md`

- [ ] Run the complete PHP and Exploration HUD suites, all changed-PHP lints, JS syntax check, `git diff --check`, `git status --short --branch`, `git diff --stat`, and full `git diff`.
- [ ] Search the complete Task 7 diff for browser-trusted damage/timing/coordinates/outcomes, leaked random/config/snapshot fields, migrations, potion/reward/death code, or JavaScript/CSS/map changes. Classify every match.
- [ ] Update the tracker to `TASK 7 IMPLEMENTED — AWAITING CHECKPOINT REVIEW`, recording actual counts, local/uncommitted/undeployed state, no migration, and that Task 8 potion has not started.
- [ ] Request independent code review of the complete diff against the authoritative specification and this plan.
- [ ] **Review checkpoint:** stop with the local diff. Do not commit, push, deploy, access live systems, or begin Combat Milestone 1 Task 8.

## RED Test Traceability for This Planning Pass

| Approved behavior | Initial RED family |
|---|---|
| Eligible-only prompt; stable refresh; safe bounds | Prompt creation/persistence |
| Timely token; separate child command; same-token replay; distinct-token rejection | Service/repository attempt |
| Wrong/expired rejection with no attempt marker | Service/repository attempt |
| Zero Action cost; works at zero Actions; player action remains pending | Service attempt |
| Missed/accepted attempted flag; current defense; exactly-once resolution | Resolver integration |
| Exact safe projection and recursive privacy | Projector security |
| POST/session/CSRF/UUID/allowlist/ownership | Endpoint security |
| Duplicate/two-request serialization | Repository race/rollback |

## Completed Writing-Plans Self-Review

- [x] **Specification coverage:** Every approved prompt, attempt, expiry, idempotency, hit-resolution, randomness, projection, endpoint, and concurrency rule maps to a numbered task and RED family.
- [x] **Current architecture:** Hit resolution stays in `CombatActionResolver`, chronology in `CombatSynchronizer`, commands in `CombatService`, projection in `CombatStateProjector`, persistence in `CombatRepository`, and production assembly in `CombatBootstrap`.
- [x] **Schema consistency:** Migration 003 already supplies every required field and uniqueness guard. The plan creates no migration and no new persistence field.
- [x] **Lock/idempotency consistency:** Account-wide commands retain Champion -> Account -> Encounter -> Action order. The enemy action retains a null request token; a separate resolved child `block` action owns the client UUID and links to its locked parent. Same-token child replay and distinct-token rejection use existing row locks and uniqueness.
- [x] **Randomness consistency:** The existing shared `CombatRandomSource` supplies one prompt token, two bounded placement values, and exactly one provisional success roll only for an attempted Block. Missed prompts do not roll. Chance and result remain server-only.
- [x] **Block ownership consistency:** `PrototypeEnemyDefenseResolver` owns Cave Brute automatic defense through `cave_brute.block`; `PrototypeBlockResolver` owns Champion reaction defense through `player_reactions.basic_block`. Their identical provisional 20%/50% values are independently configured and independently validated.
- [x] **Damage consistency:** `PrototypeBlockResolver` composes the existing current-defense damage resolver, uses only the player reaction definition's provisional 20%/50% roll, and does not duplicate CharacterStats, Toughness, or Fire formulas.
- [x] **Projection consistency:** The plan defines one exact nine-field prompt allowlist and retains the existing safe enemy active-action projection.
- [x] **Placeholder scan:** No `TODO`, `TBD`, “implement appropriately,” unresolved interface, or invented schema/config field remains.
- [x] **Scope consistency:** No Task 8 potion, Task 9 HUD, rewards/victory, permanent death, Slayer, real equipment, Accuracy/Critical/Dodge, keyboard control, JavaScript/CSS/map, deployment, or live database work is included.
