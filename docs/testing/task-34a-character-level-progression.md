# Task34A — Character Level Progression Foundation

## Pre-implementation audit and decisions

Base: `fd7fab8b42cbf5c6816ab6ccf3f291ffb0ccabb6`. Worktree `ASCII_Quest_II-task34a`, branch `feature/progression-task-34a-character-leveling`. The paused Task34 skill worktree/audit remains untouched. No skills, allocation, loadout or skill UI are part of this prerequisite.

### Existing schema and XP meaning

`characters.level` and `characters.experience` already exist in the legacy schema. Migration001 preserves them, adds main stats/stat_points, and removes legacy per-level combat-stat columns. Creation (`create_character.php:194–232` at base) inserts Level1/XP0/stat_points0. Victory increments `experience` without resetting it; therefore it is cumulative total XP, not progress inside a level. There is no baseline XP threshold/level-award implementation. `game.php:553` explicitly says next-level progress is undefined. No DB schema dump specifying XP column width is tracked; migration must preflight live integer types and data, not assume inspected production schema.

### Combat reward path, replay and death

`CombatService::state`/commands obtain owned Champion → account mutex → unresolved encounter locks via `CombatAccessGuard`/`CombatRepository`, synchronize pending actions/timeline, and commit all changes together. `CombatSynchronizer::transitionToVictory` first conditionally changes active to victory_loot with `rewards_issued_at`, then calls `addLockedCharacterVictoryRewards`, generates items, cancels pending actions and writes the victory event. A failure rolls the caller's transaction back. Only the conditional transition winner awards rewards. Replayed commands use stored action tokens and terminal encounters do not synchronize/re-award. Existing reward size: `config/combat.php` Cave Brute gives **40 raw XP, 25 gold**; no XP multiplier pipeline exists. Items/gold must remain unchanged.

`transitionToDefeat` marks the Champion dead and retains killer/encounter/history; defeated Champions receive no victory XP. Dead characters must retain historical XP AND stored level during reconciliation and projections. New characters use separate identities. No progression after death, resurrection, XP resets or deletion of audit history.

### Stats and immutable combat assumptions

`CharacterStats` derives combat/resource values from main stats/equipment, not level. `CharacterStatAllocator` spends stored stat points. No existing level-triggered stat-award code exists. AGENTS and creation copy describe a future five-stat-points-per-level design; the current user explicitly authorizes **no NEW stat awards or automatic stat increases**. This task preserves the actual no-award behavior, stored stat_points, main stats, HP/Mana and Tasks24–31 formulas/exclusions, without implementing that separate design. Level changes must not heal resources or modify pending offensive snapshots, action allowances, enemy balance, loot source level or item generation.

### Curve decision, cap and authority

Use a server-owned explicit 50-entry integer threshold table for cumulative XP. Entry for Level L is `100 * (L - 1)^2`, L=1…50. First thresholds: 0, 100, 400, 900, 1600; Level50 begins at **240100 total XP**. Adjacent requirements grow by 200; first interval100, final interval9700. At the current 40-XP encounter reward the first level needs three victories; one ordinary victory cannot accidentally skip multiple thresholds. Large authorized server rewards still support multi-level crossings. No floats/randomness/client requirements; cap50 retains cumulative XP, displaying MAX, without capping/resetting XP. Integer overflow/database capacity errors must roll the whole reward back rather than discard XP.

For alive Champions, XP is the source of truth and level is its existing materialized cache: `levelForTotalXp(experience)`. Migration reconciles all alive mismatches, including downward corrections to a stale level cache, without losing XP; dead level/XP remain unchanged. UI projection derives alive progression from the same service. New champions stay1/0. Runtime victory calculates old/new progression once, updates XP and level in the same guarded reward transaction, and stores a small immutable reward progression JSON snapshot on the existing encounter for repeatable feedback (old/new level, before/after totalXP, XP gained). This extra field is warranted by stable replay feedback; no skill/stat-point fields or separate XP account are added. Old encounters have NULL snapshots and are not backfilled or invented.

### Display and security plan

Reuse the existing Level text/XP bar, no geometry redesign. Server projects level, cumulative XP, current/next thresholds, XP into level, interval size, remaining XP and integer percent; cap displays MAX. Combat HUD consumes the same authoritative projection and renders persisted Level Up old→new feedback alongside existing victory XP. Client never submits XP, level or requirements; existing authenticated exact-shape CSRF combat endpoints remain the only reward path. Selection/stat pages continue to display the existing persisted level cache, made consistent by migration012 and every subsequent victory; they do not mutate data on reads. Dead levels remain historical.

### Migration plan

Reserve `012_character_level_progression.sql` / `_verify.sql`; future skill migration is **013**. **Migration012 NOT APPLIED**, anywhere. It requires recorded011, absent012/target column, expected existing parent types/nonnegative XP/lifecycle, enforced CHECKs and a named migration lock. DDL implicitly commits in MariaDB, so quiesced maintenance/backup and fail-closed partial-install inspection are mandatory. Add only the nullable encounter reward snapshot; reconcile alive level caches transactionally with the marker after DDL. No historical combat snapshots/events, gold/items/stats/resources or dead characters are changed. The verifier is read-only; parity tests compare SQL table thresholds with PHP configuration without executing a migration.

## Production impact report availability

The user authorized read-only impact inspection only. Attempt used configured SSH default key, `BatchMode=yes`, and intended `START TRANSACTION READ ONLY` selects for Champion id/name/current level/totalXP/life_state and column metadata via `sudo -n /usr/bin/mariadb`. SSH failed before reaching MariaDB:

`najcarg@192.168.0.235: Permission denied (publickey,password)` (exit255).

Only standard `~/.ssh/id_ed25519` exists; no SSH host config supplies another key. No passwords/credential files were read; no data or schema were accessed; no production impact rows are available. Exact blocker: a working approved SSH key/session is required. Do not substitute fixtures for production data. The local read-only report helper `scripts/report_character_progression_impact.php` computes every change/no-change/dead exception and large jump from exported rows if approved key access becomes available. `ssh-add -l` also reported no loaded identities. No production rows are available, so there is no calculated production change count or claim of zero impact.

## Verification evidence

### Implementation summary

- `config/character_progression.php`: explicit immutable v1 thresholds for all 50 levels; `lib/CharacterProgression.php`: integer validation, binary-search level derivation, pure display projection, reward planning, and strict immutable reward receipt validation. PHP integer overflow fails closed; DB storage-capacity failure rolls the entire transaction back, never truncates/caps XP.
- `CombatSynchronizer`: computes progression before the once-only victory transition, persists the receipt with the victory guard, updates XP/level/gold, and appends one aggregate `Level Up! old → new.` event for one or many crossed levels. No event is fabricated for pre-012 receipts.
- `CombatRepository`: existing owned locked transaction; reward UPDATE now includes level plus alive/current-HP/expected-XP checks. Encounter snapshot is persisted by the existing conditional active→victory transition. Existing character/account/encounter serialization, rewards_issued_at guard, gold and item generation remain the authority.
- `CombatStateProjector`: Champion progression and a validated persisted reward receipt. Replay never recomputes a victory receipt from today's loadout, XP or stored cache. The v1 curve is intentionally immutable: future threshold rebalancing requires an explicitly versioned history/migration design, not editing this table in place.
- `game.php`, `exploration_hud.js`, `combat_hud.js`: reuse existing Level, XP bar and victory area. XP label shows interval progress, ARIA/data retain cumulative total, MAX at cap, and single/multi-level feedback is text only. Existing 160ms resource-bar transition is preserved; no animation subsystem or CSS redesign added.
- Only baseline fixture/schema-emulator support and Task12 assertions intentionally superseded by progression were updated. Task12 now expects the new level cache, immutable receipt and one aggregate level event rather than explicitly asserting no leveling exists. Its once-only gold/XP/item/close assertions remain.

### Tests and results

Initial RED: `php tests/run_task34a.php` produced **0 passed / 6 failed**, missing progression service/config. GREEN:

| Command | Result |
| --- | --- |
| `php tests/run.php` | **820 passed, 0 failed** (all 803 baseline cases plus 17 Task34A cases). |
| `php tests/run_task34a.php` | **17 passed, 0 failed**. |
| `php tests/run_task13.php` | **10 passed, 0 failed**. |
| `php tests/run_task14.php` | **34 passed, 0 failed**. |
| `php tests/run_task27.php` | **20 passed, 0 failed**. |
| `php tests/run_task28.php` | **18 passed, 0 failed**. |
| `php tests/run_task29.php` | **43 passed, 0 failed**. |
| `php tests/run_task30.php` | **152 passed, 0 failed**. |
| `php tests/run_task31.php` | **122 passed, 0 failed**. |
| `node tests/ItemHudTest.js` | **21 passed, 0 failed**. |
| `node tests/CombatHudTest.js` | **32 passed, 0 failed**. |
| `node tests/ExplorationHudTest.js` | **30 passed, 0 failed**. |
| `node tests/Task33Test.js` | **14 passed, 0 failed**. |
| `node tests/Task33DetailsTest.js` | **11 passed, 0 failed**. |
| `node tests/CharacterProgressionHudTest.js` | **4 passed, 0 failed**. |
| PHP lint, all files in `ascii-quest`, `scripts`, `tests` | **153 files passed**. |
| `node --check`, all JS in `ascii-quest/js`, `tests` | **12 files passed**. |
| `git diff --check` | Passed. |

There are **932 distinct PHP/JS unit cases** (820+112); focused/combat runner totals overlap the full suite and must not be added again. The original 911 baseline unit cases remain green. Pure-rule tests exhaust below/exact/above **every** level threshold (negative XP separately rejects below Level1), iterate all 240101 XP values through cap to prove an ordinary 40-XP victory never skips multiple levels, and cover multi-level awards, above-cap XP, numeric PDO strings, overflow and malformed values. Integration fixtures cover persisted single/multi/cap XP/level, two service instances/replay, immutable receipts, gold and unchanged stats/HP/Mana, reward-storage/event rollback and retry, wrong owner, terminal death, legacy history and exact-shape client rejection. Migration tests inspect 011 prerequisite/012 absence/partial-install/type/constraint preflights, all SQL/PHP threshold parity, read-only verifier and alive-only level reconciliation. No database/migration was executed for these tests.

### Browser evidence and reproduction

Both browser runners are dependency-free Chromium/CDP with the real game template and synthetic local repositories. No sessions, application HTTP calls, DB writes or migration application:

```sh
node tests/CharacterProgressionBrowserTest.js /tmp/ascii-quest-task34a-browser
node tests/Task33BrowserTest.js /tmp/ascii-quest-task34a-task33 --record-baseline --root=/path/to/checkout-at-fd7fab8
node tests/Task33BrowserTest.js /tmp/ascii-quest-task34a-task33
```

- Progression browser: **35 scenarios / 5 widths** (1440, 1100, 720, 390, 320), initial/below/exact threshold, single-level, multi-level, cap and above-cap states; authoritative level/XP/ARIA, settled pixel fill, cumulative XP retention, gained XP, old→new feedback, responsive overflow and zero application requests. The fixture `tests/fixtures/task34a_progression.php` generates these states using the actual CombatService/Repository/Synchronizer/Projector with FakeCombatPdo, not browser-owned requirements.
- Task33 browser: **14 exploration + 6 combat viewport layouts**, bottom tabs/details modes, font/glyph checks, retained item selection/equipment/pagination, five tall-sidebar stress checks, exact-base outer geometry and responsive placement all passed. Artifacts are in `/tmp/ascii-quest-task34a-task33` for this run; this location is not a committed production artifact.
- Committed evidence: [35 scenario results](task34a/browser-results.json), [desktop multi-level](task34a/1440-multi.png), [mobile multi-level](task34a/390-multi.png), [mobile MAX](task34a/390-cap.png). Screenshots are explicitly synthetic, manually inspected for correct gold/XP/Level Up and settled XP bar. A Chromium profile-cleanup race found during testing was fixed by awaiting browser exit and retrying temporary-directory cleanup; final browser run passes.

### Minimum manual normal-play verification (only after separate deployment/migration authorization)

Use an owned newly created alive Champion; creation is Level1/XP0/stat_points0. Survive three ordinary 40-XP Cave Brute victories: totals40 and80 remain Level1; total120 becomes Level2, displaying20/300 XP and `Level Up! 1 → 2`. Refresh/replay must not add another reward; stat_points/main stats must not increase and current HP/Mana must not be healed by leveling. A fresh Champion after death starts independently1/0. No normal-player fixture, production grant or XP mutation was executed by the agent. High-level/cap/multi-level browser checks above are synthetic and do not pretend to be production progression.

### Migration/rollout limitations

**Migration012 NOT APPLIED anywhere.** Future skill migration number is013. The new runtime reward persistence requires this migration before production use; this branch is for review, not ready for an uncoordinated code-only deployment. Deployment/application are not authorized here. An authorized operator must first obtain the real impact report and a validated backup, verify011/schema/lifecycle prerequisites, quiesce writers, apply012 and run the read-only verifier, compare dead/XP/stats/resources/history against the backup, then reconcile code/runtime under a separately approved rollout. DDL and reconciliation/marker are not jointly rollbackable; partial install requires manual inspection/restore, never blind rerun. Verifier shape/parity/immutability is statically tested only: MariaDB execution is deliberately unverified because migration application was forbidden. Without the new column, the verifier's later data queries fail closed rather than silently reporting success.

Production impact is **unknown**, not zero: SSH key authentication is blocked. No credentials were read. Once approved key access exists, export only id/character_name/level/experience/life_state using a read-only transaction and feed the JSON row list to `php scripts/report_character_progression_impact.php`; it outputs all no-change/changed/dead rows, XP-derived expected level, dead-preserving proposed level, signed delta and an absolute-delta≥5 large-change flag. Fixtures exercising that helper are not production evidence.

The five-stat-points-per-level design remains unimplemented as expressly required by this task. No new stat awards, skill allocation/loadout/UI, production writes, main promotion, deployment, rebase, squash, force push or changes to the original Task34 worktree occurred.
