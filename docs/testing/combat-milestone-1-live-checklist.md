# Combat Milestone 1 Live Verification Checklist

Use this checklist only after the accepted Task 15 branch has been integrated into `main`. Record commands and results in the deployment log. Permanent-death testing should use a disposable test Champion.

## Pre-deploy

- [ ] Record the expected `main` SHA supplied by the integration manager.
- [ ] On the server, run `git status --short --branch` and confirm the tree is clean.
- [ ] Run `git rev-parse HEAD` before and after deployment and record both SHAs.
- [ ] Update with `git pull --ff-only`; stop if Git cannot fast-forward cleanly.
- [ ] Run `php tests/run.php`, `node tests/CombatHudTest.js`, and `node tests/ExplorationHudTest.js` on the server. Require zero failures.
- [ ] Run `node --check` for `combat_hud.js`, `exploration_hud.js`, and `game_controls.js`, plus PHP lint for all Combat Milestone 1 PHP files.
- [ ] Inspect migration status before opening the browser test. Do not infer status from application behavior.

## Database

- [ ] Query `schema_migrations` for `003_combat_foundation` and record whether it is present.
- [ ] Query `schema_migrations` for `004_combat_enemy_ai_initialization` and record whether it is present.
- [ ] If either migration is absent, stop and obtain explicit user approval before applying it. Apply 003 before 004.
- [ ] Never rerun either migration blindly. Both migrations intentionally reject an existing or partial installation.
- [ ] After an approved application, run the matching read-only verification SQL and review every result:
  - `database/migrations/003_combat_foundation_verify.sql`
  - `database/migrations/004_combat_enemy_ai_initialization_verify.sql`
- [ ] Confirm existing Champion HP, Mana, map position, Gold, and experience were not reset.

## Browser test

| # | Check | Expected result |
|---:|---|---|
| 1 | Select a living Champion. | Selection succeeds and the existing exploration HUD loads with persisted HP/Mana. |
| 2 | Approach the stationary Cave Brute. | The configured `B` enemy remains stationary at its authoritative map position. |
| 3 | Enter one-tile orthogonal range. | One encounter starts automatically. |
| 4 | Approach from a diagonal tile in a fresh test. | Diagonal range alone does not start combat. |
| 5 | Attempt to step onto the Cave Brute tile. | Combat starts while the Champion remains on the prior tile; coordinates never overlap. |
| 6 | Click Weapon Attack once. | One pointer-driven weapon action starts; one Action is consumed and no keyboard shortcut is involved. |
| 7 | Click Flame Strike when available. | One skill action starts and its Burning effect appears only after resolution. |
| 8 | Observe weapon and Flame Strike cooldowns. | Player cooldowns display and reconcile to server state; enemy cooldown internals are absent. |
| 9 | Observe the Turn bar and Action values through a boundary. | The 10-second Turn advances without client authority; unused Actions reset rather than carry over. |
| 10 | Wait for an eligible enemy action. | A bounded, server-issued Block prompt appears only for that pending action. |
| 11 | Use the Health Potion below maximum HP. | HP heals once, up to Maximum Life; one fight charge is consumed; Mana and Action are unchanged. |
| 12 | Open Battle Info. | Ordered, escaped server-created combat events remain readable. |
| 13 | Select Server Info. | The placeholder tab is selectable and combat continues. |
| 14 | Select Chat. | The placeholder tab is selectable, no Chat backend is implied, and combat continues. |
| 15 | Refresh during active combat. | The same encounter and persisted resources resume; at most five seconds of disconnected catch-up is processed. |
| 16 | Use Main Menu, then Resume Battle. | The encounter remains unresolved and resumes without HP/Mana reset. |
| 17 | Log out, log in, and resume. | The account-owned encounter is rediscovered; another Champion cannot bypass it. |
| 18 | Defeat the Cave Brute. | Combat immediately enters `victory_loot`; ordinary attacks, Potion, and Block are unavailable. |
| 19 | Record Gold/EXP before and after victory. | Exactly 25 Gold and 40 raw EXP are issued once, with one reward event. |
| 20 | Refresh in victory loot. | Loot state and rewards persist; Gold/EXP are not awarded again. |
| 21 | Close victory, then retry the close request if practical. | The encounter becomes closed once; a replay causes no duplicate mutation. |
| 22 | Return to the game with the living Champion. | Ordinary selection and exploration controls are restored with the same HP/Mana. |
| 23 | Start a fresh fight with the disposable Champion and allow defeat. | Enemy damage, not browser data, causes one terminal death transition. |
| 24 | Inspect the defeated HUD. | Champion HP is exactly 0. |
| 25 | Return to Character Selection. | The Champion card remains and clearly shows `DEAD`; Enter Dungeon is unavailable. |
| 26 | Refresh the defeated state. | HP stays 0 and combat does not restart or advance. |
| 27 | Log out and back in after defeat. | The dead Champion and defeated history remain durable; no resource refill occurs. |
| 28 | Submit or tamper with a dead-Champion selection request. | The server rejects selection even if UI disabling is bypassed. |
| 29 | Submit the dead-Champion deletion form with valid confirmation. | The server rejects deletion and preserves Champion/killer history. |
| 30 | Recheck HP and Mana after refresh, menus, login, selection, victory, close, defeat, allocation, and Warp attempts. | No navigation or lifecycle path restores HP or Mana for free; a dead Champion cannot Warp or mutate gameplay. |

## Known prototype values

- Turn duration: 10 seconds; disconnected catch-up cap: 5 seconds.
- Cave Brute: 120 HP, Action 2; Smash 1.5 seconds / 3-second cooldown / 18 physical damage; Fire Slam 2 seconds / 6-second cooldown / 24 fire damage.
- Weapon Attack: 1 second / 2.5-second cooldown / 20 prototype physical damage.
- Flame Strike: 1.5 seconds / 5-second cooldown / 24 prototype fire damage; Burning presentation window: 4 seconds.
- Health Potion: one charge per fight, up to 50 HP healing.
- Player reaction Block and Cave Brute Block: prototype 20% chance and 50% reduction at their separate server boundaries.
- Victory reward: 25 Gold and 40 raw EXP.

These are centralized foundation values, not final balance formulas.

## Intentionally deferred systems

Combat Milestone 1 does not include real persistent inventory, real equipment persistence or swapping, randomized physical item drops, full Slayer gameplay, Slayer titles or bounties, world bosses, PvP, a functional Chat backend, an Escape system, final balance formulas, a final Accuracy/Critical/Dodge system, or resurrection.

## Evidence to retain

- Deployed SHA and clean-tree output.
- Automated suite, syntax, and lint summaries.
- Migration-status queries and, only if approved migrations were applied, verification-SQL results.
- Browser/console screenshots or notes for each failed or ambiguous step.
- Before/after HP, Mana, Gold, EXP, encounter ID, and lifecycle status for the victory and defeat runs.
