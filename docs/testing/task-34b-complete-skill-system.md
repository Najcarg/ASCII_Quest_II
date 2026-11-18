# Task34B — complete skill system

## Pre-schema architecture audit (before implementation)

Exact base/live-main prerequisite: `d675ce59ed7781227698e5551c4972332cdb10bf`. Isolated worktree `ASCII_Quest_II-task34b`, branch `feature/skills-task-34b-complete-skill-system`. AGENTS read. This deliberately integrates the architectural findings of the paused Task34 audit without changing that worktree, branch or untracked document. **No production access is authorized or performed for Task34B. Migration013 NOT APPLIED anywhere.**

### What changed since the paused audit

Task34A solved the previous blocker. `CharacterProgression` uses cumulative `characters.experience`, immutable integer thresholds `100*(L-1)^2`, cap50 retaining XP, and derives alive level from XP. Migration012 adds nullable `combat_encounters.reward_progression` with JSON CHECK and reconciles alive level caches; dead history is retained. Victory XP/level/gold, receipts, log feedback and loot remain one guarded transaction. Task34B neither modifies this curve nor grants XP/stat points nor changes resource formulas. Normal Level5 begins at1600 totalXP. User-provided last known production Kuki/Azmodan Level1/XP40 are context only, not queried or modified here.

### Existing combat integration points

- `config/combat.php` contains prototype weapon and Flame Strike actions; `CombatDefinitionRegistry` validates/exposes them and their optional timed effects. Legacy Flame Strike is `prototype_flame_strike`, fire24, duration1.5s, cooldown5s, four-second **cosmetic** Burning marker. Keep that exact identity and behavior for old pending/history; new learned Mage Flame Strike gets a separate canonical key. Never rewrite history or auto-grant/assign legacy Flame Strike.
- `CombatService::startPlayerAction`: owned Champion→account→encounter locks, chronological synchronization, durable token replay/collision checks, evaluator actor/target/busy/cooldown/action/turn-time checks, cast-rate duration and offensive snapshot persistence. This remains the only action engine.
- `CombatRepository` owns aggregate locking, numeric immutable action snapshots, timeline/event/status/reward persistence. `CombatSynchronizer` resolves already-started actions before normal victory/death transitions. New skill metadata must be frozen at start; neither current loadout nor current catalogue may be consulted to resolve pending skills.
- `CombatActionResolver` currently reads registry action identity for pending resolution and creates legacy timed cosmetic effects. Integrate frozen new-skill metadata and explicit intrinsic statuses here, retaining NULL-snapshot legacy fallback only for old prototype identities.
- `PersistentCombatEquipmentProvider` already zeroes weapon identity, equipment elemental/status additions and uses fixed skill base damage. Preserve Tasks24–31 exclusions: no Strength/weapon damage, life-on-hit, elemental item damage or equipment status amplification on skills. Shared accuracy/critical and cast-rate behavior stays as implemented.
- `CombatStatusEffects` and `CombatRepository::applyStatusEffect` already implement enemy poison/bleed/burn ticks and freeze/shock, four-second duration, refresh/expiry and immutable source identity. Task30 proc chance comes from intrinsic potency via `CharacterStats::statusEffectChance`; add no parallel status/healing/resource/AOE/buff engine.
- `CombatStateProjector` currently enumerates free registry skills. Production must instead project exactly four persisted loadout slots including explicit empty states. `combat_hud.js` already has all four controls and per-slot cooldown/name rendering, but PHP initial rendering/loadout special-cases prototype skill1. Action endpoint currently admits only key/token/CSRF; canonical skills additionally require the exact projected slot, while legacy replay/weapon intent remains compatible.

### Ownership, class keys, receipts and lifecycle

Actual class keys: `warrior`, `mage`, `rogue`, `cleric` (migration001 and StarterEquipmentService), not display names. Use class_id FKs to existing unsigned IDs, join class_key for authoritative decisions; do not assume a production class-key collation/type. Character creation is Level1/XP0; new Champion IDs have no skill allocations/loadout. Task13 prevents dead Champion mutation/deletion and preserves historical identity.

Equipment mutations establish the model: owned-character lock first, account combat mutex second, unresolved encounter check, UUIDv4 request token/fingerprint, semantic durable replay before new-mutation checks, commit/rollback. New skill receipts need a separate normalized table, not item-specific receipts. Serialize all allocations on the Champion row; unique unlock and slot/skill constraints backstop concurrency. Assignment/move/replacement/unassignment must block both active and victory_loot (including an account's other fighter). Unlocking does not alter loadout and may occur during combat; allocation still serializes with combat and cannot affect an already-pending action.

### Task33.3 UI hooks and geometry

`item_hud.js::createDetailsController` owns one physical Details panel, safe text rendering, independent retained selections and `setSkillDetails({name,description,stat_lines})`. Tabs are presentation-only. Use that path, with mutation controls hosted inside the shared Skill Details context, not a second Details panel. Items restore Item Details; Passive stays placeholder. Initial server projection avoids tab-triggered network calls. Fit a compact four-node dark/gold ASCII tree inside the existing right panel; preserve1100/720 responsive geometry, information panel and combat action colors.

## Design decisions before schema lock

**Source of truth: normalized DB definitions/prerequisite edges**, seeded only by migration013. PHP validates/translates DB rows into existing action definitions; JS receives display/state only. Test/browser fixtures parse the accepted migration seed statements rather than duplicate independent skill rules. No PHP/JS catalogue constants of names/damage/prerequisites.

Derived points: validate stored level syntax/range1–50, then use authoritative34A alive level (dead stored historical level). `earned=max(0,level-1)`, `spent=count(all character_skills audit rows)`, `available=max(0,earned-spent)`. Cost1. No mutable balance, refunds, client point fields or new stat awards. Malformed progression fails closed. Every new unlock rechecks earned/spent under the locked Champion and rejects overspend.

Relations planned: skill_definitions (class_id, class position, display/combat/status/active fields), same-class skill_prerequisites, character_skills, character_skill_loadout, separate completed mutation receipts; unique Champion/skill and Champion/slot; owned-unlock FK; ultimate discriminator FK/CHECK; nullable immutable JSON skill snapshot on combat_actions. Character deletion follows existing lifecycle guard with cascade of current allocations/loadout/receipts only on an actually authorized character deletion; definitions/prerequisite/unlock references restrict definition deletion. Dead rows remain because dead Champion deletion is forbidden. New migration adds no grants/backfills and updates no historical rows. MariaDB DDL implicit-commit partial installs require manual recovery, not blind rerun.

Every class has ROOT→BRANCH1→ULTIMATE and ROOT→BRANCH2. Requirements ROOT2, branches3, ultimate5; one point each. Exactly three normal and one ultimate per class. Values/complete catalogue and evidence will be recorded below from the migration source.

Production factories will always wire DB authority. Existing `serviceForRepository`/constructor prototype fixtures remain explicit legacy test adapters so baseline contracts still run; they are not the real PDO/server bootstrap. Real new requests cannot use a free prototype skill. Frozen canonical metadata resolves without catalogue/loadout reads; legacy NULL snapshots preserve exact historical prototype semantics.

## Authorization and rollout boundary

Implementation/tests/docs/browser fixtures/screenshots/normal commits/feature push only. No production access, migration application, Champion changes, unlocks/loadouts, XP changes, Task32 cleanup, main promotion or deployment. Future application of013 requires separate acceptance, backup, maintenance and authorization. Stop at GitHub review.

## Backend checkpoint evidence

Initial focused RED:0 passed/2 failed (missing SkillCatalogue/SkillService). Current backend GREEN:33 focused cases, including all16 skill executions, immutable pending status/damage after catalogue/loadout changes, allocation accounting/replay/rollback, moves/replacement/unassignment/combat locks, endpoint security and static migration validation. Existing PHP820 cases pass. No DB/migration was executed. Character→account→encounter locking intentionally avoids locking shared class rows or the other Champion through JOIN FOR UPDATE, preventing cross-Champion lock inversion. Further UI/regression/evidence sections follow before final completion.
