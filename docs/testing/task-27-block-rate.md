# Task 27 — Activate Block Rate

Base: `5f93bc176c0ce64384649880189b74350e4e5ba5`
Branch: `feature/combat-task-27-block-rate`
Worktree: `/home/najcarg/Projects/ASCII_Quest_II/task27-block-rate`

The explicit Task 27 brief is authoritative. `docs/tasks/current-task.md` still
contains historical Task 19; the item-system design's reserved Block Rate text
predates this approval. Read alongside Tasks 24–26 and the design's current-defense
section. No migration, schema change, database operation, bootstrap, production
access, main promotion or deployment is included.

## Formula and authority

```text
base_chance = basic_block.server_only.prototype_chance_percent  # currently 20
factor = CharacterStats.rates.block_rate                       # base 1.00
chance_percent = clamp(base_chance * factor, 0.0, 75.0)
blocked = valid_active_attempt && integer_roll(1, 100) <= chance_percent
```

Keep the float product: no integer cast or rounding before comparison. A 25.5 or
25.998 chance succeeds at roll 25, fails at 26. The configured base is not replaced
by a literal 20; a separately configured base 30 with factor 1.25 yields 37.5.
The upper cap applies to final chance, not the factor.

Production authority is exclusively locked equipped items →
`EquipmentStatAggregator` → `CharacterStats` → `rates.block_rate` → existing
`currentDefense()` projection's `block_rate` → `PrototypeBlockResolver`.
Snapshot base rate modifiers and canonical `block_rate_bp` affixes already add
once, in basis points, across items. The unchanged shared formula is
`base.block_rate * max(0.10, 1 + aggregated_block_rate_bp / 10000)`.
Thus -20000 bp still yields factor 0.10, chance 2%, not negative/zero chance.
The resolver additionally clamps the final product to zero defensively; it does
not reinterpret equipment or duplicate CharacterStats aggregation/floor rules.
Missing/nonfinite current factors are rejected when an attempted Block needs one.

Both persistent and prototype providers project the shared factor through their
existing defense view. The provider interface only gains a contract comment;
there is no added method, optional-method probe or broad provider refactor.

## Ordering, active requirement and resolution timing

1. Existing current Dodging chance/roll resolves first (unchanged 0–75 cap).
2. Successful DODGE returns immediately: no Block roll or passive mitigation.
3. After failed Dodge, only a persisted accepted active Block attempt can roll
   Block. No attempt or a rejected token means no Block roll and no automatic
   passive Block, however large the factor is.
4. Resolve existing toughness/fire-resistance damage; a successful Block applies
   the existing configured reduction (currently 50%) to passive-mitigated damage,
   preserving rounding and minimum applied damage. Outcomes remain HIT/DODGE/BLOCK.

The service's existing ownership, prompt-token, expiry and one-attempt gates are
unchanged. Intent acceptance itself does not roll. Block remains a zero-Action,
resolved child command pointing at the incoming action; no prompt, duration,
cooldown, coordinates or difficulty changes are made.

`CombatActionResolver` already reads `currentDefense()` for a locked pending enemy
action at **resolution time**. This patch extends that projection, not offensive
snapshots or persistence. Tests mutate an injected equipment reader after an
action is pending to prove current authority; real equip/unequip and stat changes
remain blocked during unresolved combat by existing rules.

Existing pending→resolved persistence, account/character/action locks and shared
transaction keep HP, damage and outcome durable once. Duplicate accepted intent,
repeated synchronization, fresh service/refresh and resolved history never reroll
or reapply damage, even if the injected factor changes afterward. Existing failed
transaction retries may reroll under the unchanged nontransactional RNG policy.

## Real RED / GREEN evidence

New focused tests and their runner were written and executed **before production
edits**. Commands ran in the worktree above. The runner loads existing fixtures
without executing unrelated cases.

```sh
php tests/run_task27.php
# RED: 5 passed, 15 failed (20 total)
php tests/run.php
# RED: 383 passed, 15 failed (398 total)
```

Failures demonstrated the missing 1.25/1.50/2.00 factors, 75% cap, 0.10 floor,
fractional comparison, provider projection, modified chance after failed Dodge,
configured base/reduction, fire-resistance path, invalid-factor rejection, lower
clamp, resolution-time factor, and accepted-intent replay's modified outcome.
Base, aggregation, no-attempt, successful-Dodge and invalid-token cases already
passed. The existing 378 PHP cases remained green at this RED stage.

After the production changes:

```sh
php tests/run_task27.php
# GREEN: 20 passed, 0 failed
```

The first full run then reported 392 passed, 6 failed: old direct resolver/fake
provider fixtures lacked the new defense field, an exact prototype-defense
assertion lacked the field, and one historical assertion required Block Rate
absence. Updated only those fixtures/contracts and stale Block-specific labels;
no behavioral regression expectation was removed.

Final verification:

```sh
php tests/run.php
# 398 passed, 0 failed
php tests/run_task27.php
# 20 passed, 0 failed
node tests/ItemHudTest.js
# 21 passed, 0 failed
node tests/CombatHudTest.js
# 32 passed, 0 failed
node tests/ExplorationHudTest.js
# 30 passed, 0 failed
find . -name '*.php' -not -path './.git/*' -print0 | xargs -0 -n1 php -l
# all 123 PHP files passed
find . -name '*.js' -not -path './.git/*' -print0 | xargs -0 -n1 node --check
# all 7 JavaScript files passed
git diff --check
# passed
```

Focused coverage: base 20/21; factor 1.25 → 25/26; 1.50 → 30/31;
2.00 → 40/41; factor 10 → capped 75/76; negative modifier/floor → 2/3;
25.5 and 25.998 fractional boundaries; both providers; single/multiple additive
bp snapshots/affixes including persisted integer strings; all unrelated stats
unchanged; no active attempt; Dodge short circuit; failed Dodge then modified
Block; unchanged physical/fire mitigation/reduction/minimum; configured chance
and reduction; malformed factor; defensive zero clamp; current-resolution stats;
repeated sync/refresh; accepted and resolved intent replay; rejected intent.

The full run includes Task 24 Strength/unarmed, Task 25 hit/critical/Dodge/enemy
Block and legacy snapshots, Task 26 Life on Hit, attack/cast durations, resource
caps, security/ownership, equipment locks, timelines, rewards and permanent death.
`CharacterStats`, `EquipmentStatAggregator`, combat config and Task 26 files are
unchanged. No hit/crit, enemy Block, attack/cast, LoH or UI rules were changed.

## Explicit deferrals

Passive Block; shield-specific bonuses; Block damage amount affixes; perfect
Block; parry; counterattack; cooldown changes; prompt difficulty modifications.
No new items/affixes, migrations or database writes are introduced.

## Architecture notes and manual tests still required

The existing defense boundary is array-shaped; both concrete providers and
relevant test fixtures now carry its explicit factor. No new persisted/public
field is needed. The production provider still performs the authoritative
locked equipment read; no client value is trusted.

No browser, real database or production testing was performed. Automated service
tests use existing deterministic in-memory PDO/equipment fixtures. Remaining
manual verification on an approved test environment:

- Equip available Block Rate gear outside combat; confirm the shared Details
  factor and that only timely active prompt attempts can Block.
- Verify unchanged popup availability/position/timing, one attempt, zero Action
  cost, 50% mitigation and literal HIT/DODGE/BLOCK event rendering.
- Refresh/reopen/two-tab replay around a resolved Block; confirm HP/outcomes
  remain durable once. Smoke-test normal/unarmed attacks, skills and LoH.
- Retain login/character selection, exploration/movement/transitions/stairs,
  traps/chests/map overrides, inventory/equipment and HP/Mana persistence checks.

Stop after the authorized feature commit/push for ChatGPT GitHub review.
No merge, deployment or database changes are authorized.
