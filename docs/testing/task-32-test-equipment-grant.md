# Task 32 — Controlled test equipment grant and soft retirement

- Base: `d3c7f09898a6c64e29ced8836d59ccc053284330`
- Branch: `feature/items-task-32-test-equipment-grant`
- Worktree: `/home/najcarg/Projects/ASCII_Quest_II/task32-test-equipment`
- **Migration011 NOT APPLIED anywhere. No production database access, grant,
  retirement, bootstrap, main promotion, deployment, or browser gameplay.**
- Read AGENTS, historical Task19 task file, item-system architecture, Task31
  guidance, migrations005–010, starter/equipment/inventory/claim authority,
  catalogue/projector/stat/provider paths and combat fixtures. The explicit Task32
  brief and user-approved **soft-retirement revision** are authoritative.
  Original checkout's untracked `.agents/` and `.pi/` are untouched.

## Purpose and exact deterministic set

A maintenance-only CLI grants inventory items to **one explicitly identified,
owned, alive Champion**. No random source, ItemGenerator call, autoequip, resource
clamp/heal/refill, new browser route, catalogue/weight change or formula change.
Only test key, base key, affix key, tier and fixed value are specified; labels,
positions, families, operations, glyphs and base snapshots come from real rows.
All fourteen items are **ilvl10, Magic, exactly one tier2 affix**.

| Test key | Base key | Affix key | Value | Authoritative name | Exact stat line |
| --- | --- | --- | ---: | --- | --- |
| accuracy | worn_sword | keen | 7 | Keen Sword | +7 Accuracy |
| critical_chance | worn_sword | deadly | 7 | Deadly Sword | +7 Critical Chance |
| critical_damage | worn_sword | savage | 20 | Savage Sword | +20 Critical Damage |
| life_on_hit | copper_ring | vampiric | 7 | Vampiric Copper Ring | +7 Life on Hit |
| dodge | hide_boots | of_evasion | 7 | Hide Boots of Evasion | +7 Dodging |
| block_rate | wooden_shield | of_guarding | 5000bp | Wooden Shield of Guarding | +50.00% Block Rate |
| fire_damage | copper_amulet | flaming | 7 | Flaming Copper Amulet | +7 Fire Damage |
| lightning_damage | copper_amulet | stormcharged | 7 | Stormcharged Copper Amulet | +7 Lightning Damage |
| cold_damage | copper_amulet | frosted | 7 | Frosted Copper Amulet | +7 Cold Damage |
| poison | copper_charm | venomous | 7 | Venomous Copper Charm | +7 Poison Damage |
| bleed | copper_charm | bleeding | 7 | Bleeding Copper Charm | +7 Bleed |
| burn | copper_charm | scorching | 7 | Scorching Copper Charm | +7 Burn |
| freeze | copper_charm | freezing | 7 | Freezing Copper Charm | +7 Freeze |
| shock | copper_charm | shocking | 7 | Shocking Copper Charm | +7 Shock |

Names contain no authoritative `[TEST]` prefix; only CLI output labels maintenance
as TEST. Existing semantics remain authoritative: poison7 is direct damage **and**
35% proc/potency7; bleed/burn7 proc35% and tick7; freeze7 proc35% and slows subsequent
enemy action durations7%; shock7 proc35% and amplifies subsequent direct damage7%.
Existing mitigation, rounding, durations, caps and critical/Block rules remain.
Block5000bp yields factor1.50 (base20% attempted Block becomes30%, not70%).

## Migration011 — authored, never executed

Files: `database/migrations/011_test_item_provenance.sql` and
`011_test_item_provenance_verify.sql`. Identity `011_test_item_provenance`, required
prerequisite `010_combat_item_affix_completion`.

One ALTER on `character_items` does only:
1. Replace the existing named source CHECK from exactly `starter/combat_drop`
   to exactly `starter/combat_drop/maintenance_test`.
2. Add `retired_at DATETIME(6) NULL`, without backfill or historical updates.

Existing rows initially have NULL retirement. No FK, index, catalogue, weight,
provenance, affix or history changes. Preflight rejects missing010, recorded011,
any existing retirement column, missing/additional/unexpected source CHECKs,
partial installs, unexpected existing provenance or unexpected history/equipment
FKs. CHECK comparison accepts only harmless whitespace/backtick/outer-parenthesis
formatting variants of the exact expected expression, using binary comparison.
The migration also requires CHECK enforcement and takes a migration advisory lock.

**MariaDB DDL implicitly commits.** The ALTER and subsequent migration marker
cannot be one rollback-able transaction. The two schema edits share one ALTER;
post-DDL validation precedes the separate marker. A crash/error after DDL leaves
an explicitly detected partial state, not an automatically repaired rerun. A
future separately authorized operator must quiesce writes, inspect prerequisites,
backup schema/data, and arrange manual recovery on failure. No SQL application,
including to a local test database, was performed or authorized here.

Verification is read-only USE/SELECT and reports zero violations on success:
010/011 markers, exact three-source CHECK, nullable DATETIME(6) retirement column,
and existing restrictive action/receipt/drop/equipment FKs and affix cascade.
It does **not** universally require every retirement value NULL after legitimate
future cleanup. Initial NULL/no-backfill and no history mutation are established
by static SQL review; a current-state verifier cannot prove unchanged history
without a prior baseline. SQL syntax/DDL execution and real FK/locking behavior
remain unverified. **This runtime requires Migration011 before any later,
separately authorized deployment.**

## CLI modes (examples only — no grant performed)

Set `USER_ID` and `CHARACTER_ID` explicitly to positive integer IDs for one owned
Champion, only during separately authorized testing. No implicit selected
Champion, account-wide iteration, browser session, all-Champions mode or unequip.

```sh
# Default grant PREVIEW: read/lock only, no writes
php scripts/grant_test_equipment.php --user-id="$USER_ID" --character-id="$CHARACTER_ID"
# Grant only missing identities, inventory only
php scripts/grant_test_equipment.php --user-id="$USER_ID" --character-id="$CHARACTER_ID" --apply
# PREVIEW retirement of active Task32 items
php scripts/grant_test_equipment.php --user-id="$USER_ID" --character-id="$CHARACTER_ID" --cleanup
# Atomically RETIRE those items; never delete them
php scripts/grant_test_equipment.php --user-id="$USER_ID" --character-id="$CHARACTER_ID" --cleanup --apply
```

Separated or `--name=value` IDs are supported. Unknown/duplicate/missing options,
noncanonical/nonpositive/overflow IDs, values on flags, positional arguments and
unsupported combinations fail with exit2 **before database bootstrap**. Domain/DB
failures exit1; successful previews/applies/skips/deferments exit0. SQL/credentials
are not exposed. The script and all maintenance libraries reject non-CLI SAPI
with404 before bootstrap; no runtime endpoint/bootstrap registers this service.

JSON reports Champion/user, mode, inspected Task32 count, per-key would_create,
created, already_exists or retired_exists, invalid dependency keys, retirement
counts and active final counts. Grant preview additionally reports planned final
count. Grant inspection includes retired provenance; cleanup inspection includes
only active targets. `final_count` means active Task32 rows, not historical row
count. For skipped_dead/deferred_combat no item inspection occurs; inspected/final
counts are NULL, and mutation counts are zero. Failures roll back, not partial
success. Existing equipped items, HP and Mana are preserved.

## Locking, validation and immutable idempotency

Transaction order: begin -> lock exact owned Champion -> existing users-row
account combat mutex -> unresolved encounter -> inspect Task32 rows/affixes ->
validate/preview/create or retire -> commit. There is **no mutex bootstrap INSERT**;
preview does only SELECT/locks and ends with rollback. Any failure rolls back.
Wrong owner/missing Champion fails, non-alive returns skipped_dead, active or
victory_loot returns deferred_combat, closed/no encounter is permitted. No combat
inventory mutation, synchronization, resource update or equipment assignment.

Provenance is `maintenance_test` / `task32:<testkey>` with the existing durable
unique `(character_id, source_type, source_key)`. For any new insertion, all
fourteen catalogue dependencies validate first: active/eligible definition,
Magic-compatible rarity, active affix, category AND family allowlists, tier2
existence/ilvl10 eligibility and inclusive value bounds. Production registry,
projector and aggregator validate the proposed snapshots before any INSERT.

Stored item fields include definition, level, rarity, display name, category,
subtype, slot, damage type/range, toughness, all three base rate modifiers, glyph,
owner, provenance and one common UTC generated_at=claimed_at timestamp. The one
relational affix snapshots position/key/tier/family/fragment/type/operation/value.

Matching existing definition/affix-key/tier identities return already_exists
without reroll, replacement, name/stat repair or any write. Wrong definition,
wrong affix/tier, extra affix, unknown Task32 key or duplicate identity fails
closed. Historical snapshot names/values stay immutable even if today's catalogue
changes. If all identities exist, their report does not require today's catalogue.
Mixed runs create only missing identities after full dependency validation.

## Soft retirement: why rows and references remain forever

Real history intentionally uses **ON DELETE RESTRICT**, not SET NULL:
`fk_item_mutation_requests_item` (migration005) and
`fk_combat_actions_snapshot_weapon_item` (migration007). Ordinary browser
equip/unequip therefore leaves permanent referenced item identities. The user
explicitly revised cleanup to **logical retirement**, rather than altering those
FKs, nulling history, deleting receipts/actions, or deleting items/affixes.

Cleanup targets only exact Champion + `maintenance_test` + `task32:%` +
`retired_at IS NULL`. Every active target is checked before the one UPDATE:
- Any equipment reference rejects the **entire** cleanup as test_item_equipped.
  There is no silent/automatic unequip or `--unequip` flag.
- Any combat-drop identity reference rejects as test_item_referenced.
- Existing historical action/receipt references are allowed and unchanged.
- Starter, normal/drop, unrelated maintenance and other Champion rows never match.

Apply sets only retired_at to one common UTC timestamp. Item/affix rows,
provenance, snapshots, IDs, receipt payloads and combat history remain unchanged.
No DELETE is issued; the existing affix cascade remains present but is never
triggered. Failure rolls back every retirement. A second cleanup reports
already_clean, makes no UPDATE, and never resets the original timestamp.

Active means retired_at IS NULL. Normal inventory count/page/list and current
EquipmentRepository/ItemRepository equipment reads exclude retired rows, as do
starter-bootstrap usable-weapon selection reads. Owned ID/affix reads intentionally
retain historical snapshots. Equip/unequip authority first validates owned
Champion/account/encounter locks, then durable receipt command/fingerprint and
completion. A matching completed token returns its original semantic result even
after retirement: replay is not a new mutation. Collisions remain authoritative.
Only a new command checks mutable Champion state, loads its owned item, rejects
retirement, and continues ordinary validation/mutation. Raw equip/unequip/
starter-equip methods and starter service still reject retirement. Both claim
UPDATE paths require active rows; explicit claim service also rejects a retired row. No normal UI/archive is added. Cleanup cannot retire
an equipped item, so valid current equipment cannot become retired in place.

Grant after retirement reports **retired_exists** per matching key in preview and
apply: no duplicates, resurrection, timestamp reset, or provenance reuse. The
unique identity remains occupied forever. A future test set must use a **different
namespace**, not reuse task32 keys. This is intentional architecture, not a purge.

## Real RED/GREEN, coverage and validation

Initial development RED before the service existed: focused **0 passed/1 failed**
(missing TestEquipmentGrantService). Initial conservative implementation reached
35/0. Inspection then found the real restrictive history FKs; development paused
without commit/push until the user authorized soft retirement.

For the revised behavior, tests and strict SQL-boundary fixture were updated
before runtime implementation: focused **32 passed/8 failed**, exit1. Real failures
included missing retirement counts/column, attempted DELETE, history cleanup,
retired-inventory/authority behavior and retirement rollback. Implementing logical
retirement/filtering/guards produced **40 passed/0 failed**. Further edge cases,
exact stat/behavior and historical-identity coverage initially yielded51 tests;
the review correction below brings current Task32 coverage to **55 tests**.
A read-only-verifier test initially misclassified the SQL string function REPLACE
as a write; the assertion was corrected to distinguish REPLACE INTO. A selection
SQL test initially matched interface declarations; corrected to match method bodies.
These harness errors were not claimed as product RED evidence.

Tests use actual maintenance, ItemRepository and EquipmentRepository SQL paths,
ItemProjector, EquipmentStatAggregator, CharacterStats,
PersistentCombatEquipmentProvider and combat services/resolvers. Accepted006/010
literal catalogue fixtures are read, never executed. Tests equip one legitimate
slot combination at a time in fixtures, not fourteen incompatible simultaneous
slots or an alternate stat path. All fourteen public names/lines and immutable
fields are asserted independently. Actual combat tests prove inclusive35/not36
proc, poison direct7 plus tick7, bleed/burn tick7, Freeze7 duration, subsequent
Shock7 amplification, and Vampiric7 healing. History fixtures prove action/receipt
references and payloads are retained, retired IDs remain readable, all affixes
remain, inventory hides retirement, new mutation authority rejects it, completed
valid receipt replay preserves its original result, and mixed/repeated runs do not
resurrect or reset anything. Fault injection proves all-or-nothing
grant and retirement; state/combat/ownership and strict CLI cases are covered.

Connection-free PDO doubles simulate row storage and transaction rollback. They
are not proof of MariaDB SQL execution, real concurrent lock scheduling, actual
FK execution, or browser gameplay. None of those are claimed.

### Review correction — completed receipts survive retirement

Previous feature SHA: `efeea121bfbd0ec6c427a7c30478c2e205f7af1f`. Review identified
that checking retirement before receipt replay wrongly invalidated successful
historical commands and masked token collisions. The previous test demanding
rejection of completed equip replay was incorrect and has been replaced.

Regression tests first ran against that exact runtime: **51 passed,4 failed**,
exit1. Both equip/unequip completed replays raised Item retired.; changed intent
was incorrectly reported as retirement rather than token collision; historical
replay under later dead/combat state failed. The existing fresh-token retirement
guards continued passing. Moving the item load/retirement check after receipt
handling and mutable-state validation produced **55 passed,0 failed**, exit0.

Tests create actual successful equip and unequip requests through EquipmentService
with the existing connection-free store, then apply a later retirement fixture.
Exact token/fingerprint replay must return the original stored success. Full
serialized store equality verifies no equipment/resource/receipt/snapshot or
retirement-timestamp changes, and injected write failure guards against replayed
mutations. Different slot/item intent or command still rejects as token collision;
fresh equip/unequip tokens still reject Item retired. Matching completed results
remain replayable in later dead/active/victory_loot states, while wrong ownership
still rejects and fresh commands check mutable state before retirement.

Other Task32 retirement guards were inspected without broad changes:
- InventoryService claim already validates/replays its durable receipt before
  mutable-state/drop retirement checks. ItemRepository and CombatRepository claim
  UPDATE guards affect actual claims, not that completed-receipt branch.
- CombatService closed-victory replay returns before ItemDropService auto-claim;
  auto-claim selects only unclaimed drops. Maintenance grants have no drop rows,
  and cleanup explicitly rejects combat_item_drops references.
- Raw EquipmentRepository mutation methods have no receipt replay; EquipmentService
  now returns before calling them for a completed token. StarterEquipmentService
  has no durable request-receipt replay, and Task32 cannot retire starter items.
- Maintenance grant/cleanup reruns use provenance identity rather than command
  receipts; retired_exists/already_clean behavior remains unchanged.

This inspection and regression execution are connection-free; no claim is made
about live SQL/concurrency/browser behavior. No migration, FK or history changes
were made for this review fix. Migration011 remains NOT APPLIED anywhere.

Final checks (all exit0; review-fix required checks rerun):

| Command | Result |
| --- | --- |
| `php tests/run.php` | 788 passed,0 failed (all733 existing tests, Tasks18–31 included) |
| `php tests/run_task32.php` | 55 passed,0 failed |
| `php tests/run_task31.php` | 122 passed,0 failed |
| `php tests/run_task30.php` | 152 passed,0 failed |
| `php tests/run_task29.php` | 43 passed,0 failed |
| `php tests/run_task28.php` | 18 passed,0 failed |
| `php tests/run_task27.php` | 20 passed,0 failed |
| `node tests/ItemHudTest.js` | 21 passed,0 failed |
| `node tests/CombatHudTest.js` | 32 passed,0 failed |
| `node tests/ExplorationHudTest.js` | 30 passed,0 failed |
| All PHP lint | 142 files passed |
| All JavaScript `node --check` | 7 files passed |
| `git diff --check` and staged equivalent | Passed |

Local loopback PHP HTTP checks (no database/bootstrap/browser gameplay) confirmed
404/empty body for the CLI including apply-like query parameters and all three
maintenance libraries, with no PHP warning/fatal logs. Actual valid-ID CLI
operations, SQL execution and browser tests remain deferred until separate
approvals. Stop after feature-only commit/push for ChatGPT GitHub review; no main
promotion, deployment, migration application or production grant/retirement.
