# Task35 — complete universal Passive Tree V1

## Delivery / safety

- Exact accepted base: `7e680aaa14c1e7396f1a62db77dc5c14c1f6386d`.
- Feature branch: `feature/passives-task-35-complete-universal-passive-tree`.
- Isolated worktree: `/home/najcarg/Projects/ASCII_Quest_II/ASCII_Quest_II-task35`.
- **Migration014 NOT APPLIED anywhere during development.** No DB connection,
  production XP/level changes, passive grants, deployment, main promotion, rebase,
  squash, force push, Task32 cleanup, or Ascendancy implementation.
- The feature requires separately reviewed/authorized Migration014 application
  before deployment. An absent passive table fails closed, not silently ignored.
- Normal feature-only commits/push stop at the ChatGPT GitHub review gate.

## Audit of the accepted base (before implementation)

| Area | Existing authority / finding | Task35 decision |
| --- | --- | --- |
| Progression | `CharacterProgression`, static integer threshold table, max50, cumulative XP authoritative; alive levels projected from XP, dead levels frozen | Raise cap100 by extending the SAME curve; no reconciliation/backfill or XP writes |
| Rewards / pace | Cave Brute currently40 XP and25 Gold; rewards do not scale with Champion level | Leave all rewards/content unchanged; disclose high-level pace below |
| Reward history | `reward_progression` is immutable JSON validated against the progression curve | Accept complete exact old cap50-equivalent receipts OR complete new cap100 receipts; never rewrite old receipts |
| Main stats | Shared `CharacterStats` configuration; 1Strength=1 physical melee contribution and2 Toughness; Vitality10 life, Energy15 mana | Passives enter this same calculator before snapshots |
| Equipment | `EquipmentStatAggregator` validates typed integer modifiers; `EquipmentBootstrap`, persistent provider and resource clamping use it | Add separate passive aggregator and merge in deterministic equipment-then-passive order; no duplicate item bonuses |
| Task24 | `EffectiveWeaponDamage` adds Strength to physical weapon/unarmed ranges only | General `physical_damage` adds to that physical contribution, never converts it into fire weapon damage |
| Task25 | Accuracy/Dodge/Crit in CharacterStats and canonical combat resolvers | Feed existing values/caps; no passive-specific resolver |
| Task26 | LoH is current shared stats, landed weapon/unarmed contact only; MISS/skills/dead/overheal excluded | Add passive LoH to equipment LoH, leave qualification unchanged |
| Task27 | Block Rate is a factor; chance=base20% × factor, combat clamp75% | Store basis-point increases, not raw chance points |
| Task28 | Direct fire/cold/lightning/poison snapshots; fixed skills deliberately ignore equipment direct bonuses | Passives share this weapon direct-bonus machinery, no new skill scaling path |
| Task29/30 | Five canonical statuses; independent potency-derived proc chance, fixed schedules; intrinsic skill chance frozen in skill JSON | Bonus only Burn/Freeze/Shock in the same application engine; freeze bonus in nullable action snapshot |
| Task31 | Affix aggregation before offense/defense/timing | Preserve catalogue and all equipment calculations |
| Action Rate | `rates.action` is integer per-turn allowance1; `cast_rate` scales skill execution duration; `attack_rate` scales weapon execution duration | Row18B maps to **cast_rate_bp+1000**; no fractional extra turn action, weapon speed or cooldown change |
| Task34B | DB catalogue, Character→account→encounter locks; durable receipts, token collision rejection, strict selected-session endpoint payloads | Reuse authority/lock SQL through `SkillRepository`, separate passive allocation and receipt tables |
| UI / Task33.3 | Items/Skill Tree/Passive placeholder; one physical Details panel; independent mode selections | Replace placeholder, reuse `setPassiveDetails`; no additional Details panel or outer layout redesign |
| Classes | DB class keys warrior/mage/rogue/cleric; active skills are class-specific | Passive catalogue has NO class field/FK/filter; same80 choices for every class |
| Death | Per-Champion permadeath; history retained; dead level display never reconciled from XP | Keep allocations on death; no further unlocks; new Champion has no allocations |
| Snapshots | Offense and duration frozen at action creation; current defense/LoH are read where existing engine reads them | Freeze passive-derived offense/proc bonus too; do not change current-defense/LoH rules |
| Healing / potions | Fixed `prototype_healing`, capped by missing HP; no general Cleric healing system | Healing Power projects only; no potion, LoH, regen, enemy healing, max-life or resurrection multiplier |

No class identity or lane name authorizes or forbids an allocation. Warrior
Energy/Mana, Mage Strength/Physical, Rogue Healing and Cleric Crit have explicit
service tests. Lane labels are organization, not archetypes or lane dependencies.

## Progression extension — minimal and explicit

`MAX_LEVEL=100`. All old levels1–50 retain exactly `100 × (L−1)²` cumulative XP.
Continue the same curve through100: Level100 is **980100 XP**. XP remains cumulative
at cap. No XP, stored levels, stat points or resources are modified by Migration014.
Alive display/skill/passive authority derives level from XP; a stale stored level
cache is not a point grant. The existing victory write updates its normal level
cache. Dead historical stored levels remain frozen. Level1/0XP stays Level1.

Active Skill Points stop at49 (`max(0,min(level,50)−1)`), matching the intended
Level1–50 active phase. Task35 grants only Passive Points during51–90. Existing
main-stat allocation/reward behavior is otherwise unchanged: no new stat grant
or auto-allocation/refill system is introduced.

Historical cap50 reward receipts are accepted only if EVERY field exactly matches
the old capped result; mixing old/new level semantics or changing accounting is
rejected. New victories use the100 cap. No historical receipt is transformed.
Migration012's original verifier contains its historical50-level cache rule;
its old cap-specific accounting queries are not a post-Task35 level100 verifier.
Earlier migrations are deliberately unchanged; use current progression tests
and the Task35 XP-derived passive verifier for current authority.

### Every Level51–100 cumulative threshold

| Level | XP | Level | XP |
| ---: | ---: | ---: | ---: |
| 51 | 250000 | 76 | 562500 |
| 52 | 260100 | 77 | 577600 |
| 53 | 270400 | 78 | 592900 |
| 54 | 280900 | 79 | 608400 |
| 55 | 291600 | 80 | 624100 |
| 56 | 302500 | 81 | 640000 |
| 57 | 313600 | 82 | 656100 |
| 58 | 324900 | 83 | 672400 |
| 59 | 336400 | 84 | 688900 |
| 60 | 348100 | 85 | 705600 |
| 61 | 360000 | 86 | 722500 |
| 62 | 372100 | 87 | 739600 |
| 63 | 384400 | 88 | 756900 |
| 64 | 396900 | 89 | 774400 |
| 65 | 409600 | 90 | 792100 |
| 66 | 422500 | 91 | 810000 |
| 67 | 435600 | 92 | 828100 |
| 68 | 448900 | 93 | 846400 |
| 69 | 462400 | 94 | 864900 |
| 70 | 476100 | 95 | 883600 |
| 71 | 490000 | 96 | 902500 |
| 72 | 504100 | 97 | 921600 |
| 73 | 518400 | 98 | 940900 |
| 74 | 532900 | 99 | 960400 |
| 75 | 547600 | 100 | 980100 |

Pace audit: at unchanged40 XP per Cave Brute, Level50→51 needs9900 XP
(248 further wins from the exact floor), Level50→90 needs552000 XP (13800 wins),
Level90→100 needs188000 XP (4700 wins), and Level1→100 needs24503 such wins.
This is a safe mathematical extension, **not a new reward/content balance pass**;
V1 content is slow for endgame progression. No production Champion XP was read
or changed to accelerate this.

`ascendancy_unlocked = level >= 100` is a **future-facing projection flag only**.
There are no Ascendancy classes, points, tree, mutation routes or active UI.

## All80 authoritative definitions (20 rows ×4 choices)

The sole gameplay source of values is the DB seed in
[`014_universal_passive_tree.sql`](../../database/migrations/014_universal_passive_tree.sql).
PHP validates/projects those rows; JS receives names/effects/state, never its own
value catalogue. The independent80-value table in `PassiveStatTest.php` is a
regression oracle only, never runtime data. Verifier fingerprints are generated
from seed literals, not independently maintained gameplay definitions.

Canonical keys use `passive_rNN_<lane>_<stat_suffix>` (lowercase lane a/b/c/d).
Suffixes are the snake_case effect name, except Healing Power=`healing_power`,
Cast / Action Rate=`action_rate`, Elemental Resistance=`elemental_resistance`,
Elemental Status Chance=`elemental_status_chance`. Row20 uses the named major's
snake_case name. Keys never contain class identity; every full stable key is
explicitly present in the migration.

| Row | A | B | C | D |
| ---: | --- | --- | --- | --- |
| 1 | Strength +3 | Energy +3 | Dexterity +3 | Vitality +3 |
| 2 | Maximum Life +25 | Maximum Mana +20 | Accuracy +5 | Maximum Life +40 |
| 3 | Physical Damage +5 | Fire Damage +5 | Critical Chance +4% | Healing Power +5% |
| 4 | Block Rate +10% | Cold Damage +5 | Critical Damage +10% | Life on Hit +3 |
| 5 | Toughness +4 | Lightning Damage +5 | Dodging +4% | Elemental Resistance +4% |
| 6 | Physical Damage +10 | Fire Resistance +5% | Poison Damage +5 | Healing Power +10% |
| 7 | Maximum Life +50 | Cold Resistance +5% | Bleed Damage +5 | Block Rate +15% |
| 8 | Strength +5 | Lightning Resistance +5% | Accuracy +8 | Vitality +5 |
| 9 | Life on Hit +5 | Maximum Mana +35 | Critical Chance +6% | Maximum Life +60 |
| 10 | Physical Damage +10 | Fire Damage +10 | Critical Damage +15% | Healing Power +10% |
| 11 | Toughness +6 | Cold Damage +10 | Poison Damage +10 | Life on Hit +5 |
| 12 | Block Rate +15% | Lightning Damage +10 | Bleed Damage +10 | Elemental Resistance +6% |
| 13 | Strength +7 | Energy +7 | Dexterity +7 | Vitality +7 |
| 14 | Maximum Life +75 | Maximum Mana +50 | Accuracy +10 | Healing Power +15% |
| 15 | Physical Damage +20 | Fire Damage +20 | Critical Chance +8% | Life on Hit +8 |
| 16 | Toughness +8 | Cold Damage +20 | Critical Damage +20% | Maximum Life +100 |
| 17 | Block Rate +20% | Lightning Damage +20 | Poison Damage +20 | Healing Power +20% |
| 18 | Life on Hit +10 | Cast / Action Rate +10% | Bleed Damage +20 | Elemental Resistance +10% |
| 19 | Physical Damage +20 | Elemental Status Chance +10% | Critical Damage +25% | Maximum Life +125 |
| 20 MAJOR | **Brutality:** Physical Damage +30 | **Elemental Mastery:** Fire +20, Cold +20, Lightning +20 | **Assassin:** Critical Damage +40% | **Divine Vitality:** Maximum Life +200, Healing Power +25% |

Row20 keys: `passive_r20_a_brutality`, `passive_r20_b_elemental_mastery`,
`passive_r20_c_assassin`, `passive_r20_d_divine_vitality`. Exactly80 active
rows; exactly4 major nodes. No automatic Champion allocations, slots or loadout.

## Point and row/pass authority

```
earned = clamp(projected_level - 50, 0, 40)
spent = count(character_passives)
available = max(0, earned - spent)
```

Level50=0,51=1,60=10,70=20,80=30,90=40,91–100=40. No mutable passive balance.
The client submits only `csrf_token`, `request_token`, `passive_key`.

- Pass1: exactly one unused node from Row1, then Row2, … Row20.
- Pass2 starts only after ALL20 first-pass selections: one different unused node
  from Row1, then Row2, … Row20. Any lane can follow any other lane.
- All unused nodes in the current row are projected available when affordable;
  only ONE of them may actually be allocated for that row/pass.
- Max2 per row, max40 total. There is no third or fourth pass.
- The server validates history as a prefix of ordinal slots
  `(pass_number−1)*20 + row_number`. Duplicates, gaps, wrong row/FK and invalid
  pass history fail closed. This is equivalent to first row below1, then first
  row below2, and also detects already-corrupt histories.
- Projection contains Champion identity/level/life, earned/spent/available,
  current_pass/next_row/completed_passes/complete,20 rows and each node's key,
  lane/name/description/effects/major/state/reason/unlocked_pass.
- States are locked/available/unlocked with explicit historical pass. Dead history
  remains inspectable; new dead allocations are refused, including positive-HP
  inconsistent dead rows. No account-wide inheritance.

## Internal stat mappings and exact semantics

All values are additive integers except existing basis-point rate modifiers.
No direct-damage value is reinterpreted as a percentage.

| Design stat | Internal modifier / behavior |
| --- | --- |
| Strength / Energy / Dexterity / Vitality | `strength` / `energy` / `dexterity` / `vitality`, added before normal derived-stat formulas |
| Maximum Life / Maximum Mana | `maximum_life` / `maximum_mana`, flat capacity only; never restore current HP/Mana |
| Accuracy | `accuracy`, same Task25 hit chance: clamp(base50 + Accuracy,5,95) at resolver |
| Physical Damage | New `physical_damage`; adds to `combat.melee_damage` and the physical-only contribution in `EffectiveWeaponDamage`. Physical weapon/unarmed = weapon base + Strength + passive physical + existing flat damage, then existing weapon-percent factor. Nonphysical weapons do not convert Strength/physical bonus into their element |
| Fire / Cold / Lightning Damage | `fire_damage` / `cold_damage` / `lightning_damage`; existing direct element snapshot components, existing resistance/crit/block resolution |
| Critical Chance | `critical_chance`, additive percentage points before existing CharacterStats90% cap and Task25 roll |
| Critical Damage | `critical_damage`, additive bonus percentage (not a separate multiplier engine) |
| Block Rate +10/+15/+20% | `block_rate_bp` +1000/+1500/+2000. Final factor=base1.00×max(.10,1+(equipment_bp+passive_bp)/10000). Effective attempted-block chance=base20%×factor, existing75% combat cap. +15% alone means23% chance, NOT35% |
| Toughness | `toughness`, physical reduction `T/(T+100)`; remaining physical damage rounded with existing minimum1. Strength still adds2T per point; no global redefinition |
| Dodging | `dodging`, additive percentage points, existing90% CharacterStats cap and75% actual Dodge cap |
| Elemental Resistance | Expand into `fire_resistance`, `cold_resistance`, `lightning_resistance`, each +X percentage points, existing90% caps; NEVER `poison_resistance` |
| Poison Damage | `poison_damage`, Task28 direct poison component AND Task29 poison status potency; existing potency-derived chance and resistance/ticks |
| Bleed Damage | `bleed_damage`, existing Bleed status potency/chance; no invented direct bleed identity |
| Life on Hit | `life_on_hit`, sum with equipment; Task26 current-stat contact handling unchanged: weapon/unarmed HIT/CRITICAL/BLOCK only, no MISS/skills/revive/overheal |
| Healing Power | `utility.healing_power_percent`, summed percentage. Eligible future explicitly designed Champion healing = amount×(1+stat/100). V1 has no eligible new healing action; no global healing wrapper. Potions, LoH, passive regeneration, enemies, capacity and resurrection are NOT amplified |
| Cast / Action Rate | `cast_rate_bp` +1000; skill execution duration = round(base_duration/current_cast_rate), minimum1ms. It is the existing skill **action execution rate**, not `rates.action` (discrete allowance1), not weapon `attack_rate`, and not cooldown reduction. Equipment and passive cast bp add |
| Elemental Status Chance | `utility.elemental_status_chance_percent` +10 percentage points. Add to existing per-source Burn/Freeze/Shock chance, preserve90% cap. Poison/Bleed receive0 bonus. Requires an existing positive-power status source; elemental direct damage alone does not create statuses |

Elemental Mastery is three independent flat+20 direct components. Divine Vitality
is flat+200 capacity plus25 Healing Power. Every one of the80 definitions, every
effect type, and both compound majors has explicit independent test coverage.

## CharacterStats integration / snapshots

`PassiveRepository` reads persisted definitions and allocations.
`PassiveStatAggregator` validates complete sequential history, sorts allocations
by stable key, sums active definition effects and never calculates combat damage.
Inactive owned nodes retain history but stop contributing. `CharacterStats`
accepts passive modifiers as its fourth argument, validates/merges them after
existing equipment totals, then runs existing main→derived→utility/rate formulas.
It does not read a DB or infer ownership.

`EquipmentBootstrap::stats`, persistent combat offense/defense/duration/stat
reads and equipment-resource clamping all receive the passive aggregate.
The production repositories always implement passive reads; legacy in-memory
providers without a passive store explicitly contribute none. Missing production
schema is not caught and silently replaced by zero.

Offensive numerical fields freeze exactly when existing actions freeze them:
physical/elemental/status potency, Accuracy/Crit and effective duration. The new
nullable `snapshot_elemental_status_chance` freezes the elemental proc bonus for
both weapon and intrinsic skill contacts. It contains a number, not a passive key
or definition reference. Old NULL/missing snapshots mean0; they never substitute
current passives. Skill intrinsic base odds remain in Task34B's immutable skill
JSON; `CombatStatusEffects` adds only the frozen elemental bonus in its existing
application loop. Existing DOTs retain potency/duration/schedule and independent
random draws. Pending action damage never changes after later definition or
allocation changes. Current defense/LoH remain current where existing rules read
them; new allocations are blocked throughout active/victory_loot anyway.

Successful unlock returns current CharacterStats to the existing item HUD stat
renderer, updating capacity/current-resource displays without healing. The
passive state and receipt themselves do not persist mutable derived bonuses.

## Endpoints, idempotency and concurrency

- GET `passive_state.php`: authenticated session, selected owned Champion only,
  no query caller IDs, no-store. Dead history is readable.
- POST `unlock_passive.php`: JSON content type,8KB bound, exact3-field whitelist,
  CSRF, strict UUIDv4 token/key syntax, authenticated selected identity, fail closed
  sanitized responses/logs. No earned/spent/available/class/row/pass input.
- Transaction acquires owned **Champion first**, then account mutex, then safe
  account-wide active/victory_loot encounter lookup/lock. This reuses34B authority
  SQL without locking another Champion through the encounter JOIN.
- Validate/replay durable `(character_id,request_token)` receipt after ownership
  lock, before mutable life/combat checks. Same token+same fingerprint returns the
  identical semantic result even after death/combat; different node collides.
- Fingerprint binds unlock command, selected Champion and key. Fresh allocation
  and success receipt insert commit together. All exceptions rollback both;
  rejected mutations have no success receipt. Lost-response retries are safe.
- Champion mutex serializes simultaneous spends. Unique node primary key plus
  unique `(character_id,row_number,pass_number)`, `pass_number IN(1,2)` and composite
  `(passive_key,row_number)` catalogue FK structurally guarantee no duplicate or
  >2 nodes per row even outside service code. Service also enforces pass order,
  alive/HP, active node, affordability and no active/victory_loot allocation.
- PHP service/memory and production-repository SQL-boundary adapters cover replay,
  collisions, stale serialized second requests, rollback and lock order. They are
  not claims of real two-connection MariaDB engine contention testing.

## Migration014 / read-only verification

New files:

- `database/migrations/014_universal_passive_tree.sql`
- `database/migrations/014_universal_passive_tree_verify.sql`

Requires canonical013 marker and prerequisite table/snapshot shapes; enforces
InnoDB parents and CHECK/FK settings, advisory lock, rerun rejection and partial
install rejection. Creates `passive_definitions`, `character_passives`,
`passive_mutation_requests`, plus the nullable numerical combat snapshot column.
Exactly80 active definitions; no grants, mutable point column, loadout, history,
skill/item/equipment/XP writes. DDL implicitly commits: a partial future install
requires explicit recovery, never blind rerun.

Verifier is read-only SELECT/CTE metadata/data inspection. Zero violation rows
means verified after separately authorized application. It checks column types,
nullability/collation, full non-prefix unique indexes, FK column order/targets and
delete/update rules, CHECK predicates, exact seed row SHA256 fingerprints,
20×4 shape, allocations' node/row/pass identity, <=2 row count, sequential prefix,
XP-derived point limit, receipt format and snapshot range. Catalogue fingerprints
stay below even1024-byte minimum GROUP_CONCAT limit per row.

MariaDB11.8.6 normalization: remove only whitespace/backticks and allow exact
outer/group-parenthesized/flattened AND-before-OR equivalents. No arbitrary
parenthesis stripping or weaker CHECK accepted. Focused tests test the known
flattened major predicate and reject weaker alternatives. No real MariaDB server
or application of014 was used, so engine execution/rerun/real contention remain
future authorized acceptance checks. No-history-rewrite attestation additionally
requires before/after production backups, not inference from current rows.

## UI and browser evidence

Dark/gold ASCII Quest nodes,20 labeled horizontal rows, four equal choices per
row. Dependency arrows are centered BETWEEN ROWS, never lane-only chains. Node
buttons remain inspectable while locked; selection shows name, row/lane,
description, projected effects, major status, current state/pass/reason and
server-available Unlock button in the one existing Details panel.

Text markers distinguish `UNLOCKED_PASS1` vs `UNLOCKED_PASS2`; Pass2 also has a
double border. Not color-only. Banner displays Pass1/Pass2 completion and next row.
The board scrolls inside its panel; desktop uses the existing bounded sidebar
rectangle, tablet/mobile cap board height. The outer map rectangle never grows or
shrinks when selecting/unlocking. As in existing Items vs Skills tabs, natural
outer frame height can differ between Items and a tree tab; Task35 matches the
existing Skill-tab desktop frame and preserves Task33 alignment/fill behavior.
Details text scrolls internally while controls remain inside the existing frame.
No passive slots/loadout. Items/Skill/Passive selections restore independently.
No tab-selection network requests or new combat keyboard shortcuts.

Committed evidence:

- [Level51 available](task35/passives/level51-1440.png)
- [Level70 Pass1 complete, no remaining points](task35/passives/level70-1440.png)
- [Level71 Pass2 Row1 open](task35/passives/level71-1440.png)
- [Level90 completed two-pass tree](task35/passives/level90-full-two-pass.png)
- [1100px major Details](task35/passives/major-details-1100.png)
- [720px major Details](task35/passives/major-details-720.png)
- [390px major Details](task35/passives/major-details-390.png)
- [Passive geometry/state results](task35/passives/results.json)
- [Skill browser regression](task35/regression/skills.json)
- [Progression browser regression](task35/regression/progression.json)
- [Task33 browser regression](task35/regression/task33.json),
  [geometry](task35/regression/task33-geometry.json)

Browser fixtures are CLI-only database-free rendering of the REAL game template,
with projections from actual PHP service/catalogue seed parsing and mocked AJAX
responses. Chromium155 headless; PHP8.5.12; Node26.13.0. No real app API requests,
credentials, DB or production state. Desktop and390px screenshots visually
inspected in addition to DOM assertions. Widths1440,1101,1100,720,390,320 tested.

Passive browser: levels50/51/55/70/71/90; 40 unlock AJAX operations, all row/pass
transitions, point updates,20 Pass1+20 Pass2 markers, locked/available/unlocked
Details, internal scroll, mobile geometry and shared Items/Skills/Passives context.
Skill browser:4 classes,24 level/reason states,40 AJAX operations,24 layouts and4
combat slots. Progression browser:35 scenarios over5 widths. Task33 browser:
14 exploration+6 combat layouts, all bottom tabs/details modes,5 tall-sidebar
stresses and glyph/font checks. No separate prefeature geometry baseline file was
supplied to the legacy Task33 browser command; its structural regressions and the
new direct Items/Skills/Passives geometry comparisons passed.

## Validation / RED→GREEN

Before fixes, full PHP exposed43 old SQL-boundary fixtures without passive-store
queries plus4 expected progression-cap assumptions. No prior case was deleted;
fixtures now model zero passive allocations and assertions reflect the explicitly
changed cap100. Independent new tests cover all80 exact effects, all40 selection
steps, points51–100, cross-class access, stat aggregation, combat/status/LoH/cast/
block semantics, immutable snapshots, receipts/rollback/ownership/CSRF/schema.
New intrinsic proc tests initially exposed float test-roll types; integer rolls
fixed the fixture, not production semantics. Browser first demonstrated board
content could expand the outer sidebar: desktop containment fixed it; no assertion
was removed to hide overflow. Tree-to-tree frame comparisons reflect existing
Items vs Skills natural-height differences, documented above.

| Command / suite | Result |
| --- | --- |
| `php tests/run.php` | **1063 passed,0 failed** (all862 prior PHP cases retained +201 new) |
| all8 nonbrowser `tests/*Test.js` via Node | **138 passed,0 failed** (all125 prior cases +13 new Passive HUD) |
| Combined original/current | Original987 retained; **1201 total unit/integration cases green** |
| `php tests/run_task35.php` | 201 passed,0 failed (included above) |
| Task27 /28 /29 /30 /31 focused PHP | 20 /18 /43 /152 /122 passed,0 failed |
| Task34A /34B focused PHP | 17 /42 passed,0 failed |
| Task24/25/26,Task33 | Covered by full PHP+all JS and browser regressions |
| `node tests/PassiveBrowserTest.js <evidence-dir>` | PASS;6 level fixtures,40 AJAX,6 widths |
| `node tests/SkillBrowserTest.js <evidence-dir>` | PASS |
| `node tests/CharacterProgressionBrowserTest.js <evidence-dir>` | PASS;35 scenarios |
| `node tests/Task33BrowserTest.js <evidence-dir>` | PASS |
| Migration014 tests | 6 static/normalization/constraint-model tests,80 catalogue oracles and production SQL-boundary adapters green; **NOT APPLIED** |
| PHP lint | All tracked PHP files pass |
| JS syntax | All app/test JS files pass `node --check` |
| `git diff --check` | PASS |

Focused suites overlap full-suite cases and browser scenario counts are reported
separately, not added to1201. No actual migration execution or live API/browser
claim is hidden inside these totals.

### Follow-up security audit

The follow-up audit reproduced one fail-closed CSRF gap: an empty posted token
matched an empty string session token. Normal game rendering generates a nonempty
session token, but a malformed session must not authorize a mutation. A new
endpoint regression failed with expected403/actual200 before the fix; the passive
endpoint now explicitly rejects empty session/request CSRF strings. The regression
also checks missing, null, numeric, boolean and array session tokens and asserts
rejection before any DB access. No unrelated endpoint was changed.

Full PHP/JS, focused suites, all four database-free browser suites, all tracked PHP
lint, app/test JS syntax and full feature `git diff --check` were rerun. Fresh
local browser evidence is under `/tmp/task35-reaudit/{passives,skills,progression,task33}`;
committed evidence above remains representative (no visual changes). A temporary
`git archive` of the exact accepted base independently passed862 PHP and125 JS
cases; comparison of passing case names found zero missing from the final suites.
Migration014 was not applied and no real database or production state was accessed.

## Remaining limitations / required later acceptance

1. **014 remains NOT APPLIED.** Real MariaDB11.8.6 DDL/verifier execution, rerun,
   FK/CHECK enforcement and simultaneous two-connection contention must be checked
   only after separate authorization on an approved database. Static SQL/PDO
   adapter tests cannot prove engine execution.
2. No production Champion inspection, XP/level mutation, passive grants or live
   login/browser tests. Deployment/main promotion not performed.
3. Healing Power is intentionally projected but inactive until an explicitly
   designed Champion healing action uses it. Potions and LoH do not scale.
4. High-level XP pace remains slow with existing40-XP content; no balance changes.
5. No respec, third pass, passive loadout, class restrictions or Ascendancy system.
6. Catalogue inactivity preserves history but removes its stat contribution; no
   live catalogue-edit/admin UI or backfill tooling is added.
7. While the server always rechecks combat gates, a passive panel open before a
   new encounter may show a stale available button until refresh; the mutation
   still fails closed during active/victory_loot. No client-only authority.
8. GET refresh updates passive projection; successful unlock updates CharacterStats
   HUD too. Cross-tab/other-session changes still require refresh/game reload as
   in the existing UI; no new push-notification synchronization engine.

**READY FOR TESTING — STOP for ChatGPT GitHub review.**
