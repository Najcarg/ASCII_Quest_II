# ASCII Quest II - Current Development Task

## Status

ACTIVE

## Task

Integrate Champion stat allocation and calculated statistics into the
in-game exploration HUD Details tab.

The stat-allocation backend already exists and has been live-tested.

This task must reuse that implementation rather than creating a second
stat system.

The player must be able to spend stat points while remaining inside the
dungeon.

## Read First

Read:

- `/AGENTS.md`
- current `ascii-quest/game.php`
- `ascii-quest/character_stats.php`
- `ascii-quest/allocate_stat.php`
- `ascii-quest/lib/CharacterStatAllocator.php`
- `ascii-quest/lib/CharacterStats.php`
- `ascii-quest/config/character_stats.php`
- `ascii-quest/js/exploration_hud.js`
- `ascii-quest/js/game_controls.js`
- existing stat/allocation tests
- existing HUD tests

Inspect the existing implementation before changing anything.

## Existing Rules To Preserve

Main stats:

- Strength
- Dexterity
- Vitality
- Energy
- Fate

Stat allocation rules already established:

- spending one point increases exactly one selected main stat by 1
- stat_points decreases by exactly 1
- stat_points must never become negative
- allocation is server-authoritative
- player may modify only their own Champion
- stat names are whitelisted
- transaction/locking behavior remains intact
- CSRF protection remains intact
- prepared statements remain in use

Do not duplicate or weaken CharacterStatAllocator security.

## No-Free-Healing Rule

Current HP and current Mana are stored resources.

Increasing maximum Life or Mana must NOT restore the current resource.

Example:

Before:

HP 145 / 220

Spend +1 Vitality.

After:

HP 145 / 230

NOT:

HP 230 / 230

Same rule for Energy / Mana.

CharacterStats remains authoritative for calculated maximums.

JavaScript must never calculate Champion stat formulas.

## Details Tab Layout

Replace the current Details placeholder inside the LEFT exploration HUD.

The Details tab remains inside the existing narrow left-side panel.

Do not resize or redesign the accepted three-column HUD.

If the Details content is taller than the available left panel, use
INTERNAL scrolling for the Details content.

Do not increase the overall page height simply to fit all statistics.

## Details Header

At the top show:

Champion Details

Stat Points: X

The real available stat_points value must be displayed.

## Main Stat Allocation

Display five compact rows:

STR   <value>   [+]
DEX   <value>   [+]
VIT   <value>   [+]
ENE   <value>   [+]
FATE  <value>   [+]

Use the existing ASCII Quest dark/gold visual style.

The + controls should be compact HUD controls rather than large page buttons.

When stat_points is zero:

- all + buttons must be disabled
- allocation requests must not be sent

While an allocation request is in progress:

- prevent duplicate clicks for that request
- avoid double-spending caused by rapid repeated clicks

The server remains the final authority regardless of client state.

## In-Game Allocation Behavior

Allocation must happen without leaving or reloading the dungeon.

Use lightweight vanilla JavaScript / fetch.

Do NOT introduce:

- React
- Vue
- jQuery
- npm packages
- frontend frameworks

Reuse the existing allocation backend and CharacterStatAllocator.

Do not implement a second SQL allocation path.

If `allocate_stat.php` currently only supports form POST + redirect, extend it
carefully so the existing standalone page continues working while the HUD can
request a machine-readable JSON response.

Preserve backward compatibility with the existing stat-allocation test page.

Do not expose raw database exceptions to the browser.

## JSON / Server Response

After a successful HUD allocation, the browser must receive authoritative
server values sufficient to refresh the HUD.

The response should contain the authoritative post-allocation state required
by the UI, including:

- remaining stat_points
- Strength
- Dexterity
- Vitality
- Energy
- Fate
- current HP
- maximum Life
- current Mana
- maximum Mana
- calculated CharacterStats values used by Details

Do not return formulas.

Do not make JavaScript reconstruct derived statistics.

CharacterStats must calculate them on the server.

## HUD Synchronization

After a successful point allocation:

1. Update Stat Points.
2. Update all five main stat values.
3. Update all displayed Details derived statistics.
4. If maximum Life changed:
   - update Main-tab HP current/max text
   - update HP bar width using the existing HUD resource synchronization
   - current HP remains unchanged
5. If maximum Mana changed:
   - update Main-tab Mana current/max text
   - update Mana bar width
   - current Mana remains unchanged
6. Keep the Details tab selected.
7. Do not reload game.php.
8. Do not reset map position.
9. Do not interrupt exploration state.

Reuse existing HUD resource synchronization instead of creating another
HP/Mana bar implementation.

## Calculated Statistics

Display the real calculated values already supported by CharacterStats.

Do not invent statistics or formulas.

Inspect the CharacterStats output and display its supported values in compact
groups.

Suggested presentation groups are:

### Core

Examples only where already supported by CharacterStats:

- Life
- Mana
- Melee Damage
- Toughness
- Spell Power
- Action

### Combat / Chances / Rates

Display existing supported values such as:

- Dodging
- Accuracy
- Critical Damage
- Critical Chance
- Attack Rate
- Cast Rate
- Block Rate

Only show fields actually supported by CharacterStats.

### Resistances

Display every resistance currently produced by CharacterStats.

Do not invent missing resistance types.

### Utility / Recovery / Status

Display the remaining supported CharacterStats values, including existing
utility, recovery, elemental/status-related values where they are already part
of the current CharacterStats result.

Do not create formulas or new game mechanics merely to populate this section.

Use sensible user-facing labels.

Percentages must be displayed as percentages where CharacterStats represents
them as percentages.

Rates must retain the existing rate meaning/precision.

## Error Handling

Allocation errors should appear inside the Details tab.

Examples:

- no stat points remaining
- invalid request
- invalid stat
- stale/invalid CSRF token
- server failure

Do not display raw SQL/database exception text.

Do not navigate away from the dungeon for a normal allocation error.

After an error, controls should return to a usable state when appropriate.

## CSRF

Reuse the existing stat-allocation CSRF mechanism.

The HUD must send the valid token with allocation requests.

Do not weaken CSRF protection because the request uses fetch.

## Temporary Standalone Page

KEEP for this task:

- `ascii-quest/character_stats.php`
- `ascii-quest/allocate_stat.php`
- Character Selection Allocate Stats link/button

The standalone page remains a fallback/test harness until this in-game
implementation passes live browser testing.

Do NOT remove it yet.

Removal will be a separate small cleanup task after acceptance.

## Existing HUD To Preserve

Do not redesign the accepted HUD.

Preserve:

- Main / Details / Warp tabs
- Items / Skill Tree / Passive Tree tabs
- paper-doll Equipment
- Gold in Items
- Loadout
- Inventory shell
- center map
- map title
- Information / Server Info / Chat panel beneath map
- responsive behavior currently accepted
- automatic asset cache-busting

## Existing Gameplay To Protect

Do not break:

- login
- sessions
- Character Selection
- map rendering
- movement
- collision
- position persistence
- traps
- trap HP updates
- HP bar updates
- chests
- Gold updates
- stairs
- map transitions
- map overrides
- current HP persistence
- current Mana persistence
- CharacterStats calculations

## Database

No schema changes.

No migration.

Use the existing:

- stat_points
- strength
- dexterity
- vitality
- energy
- fate
- current_hp
- current_mana

Do not add new columns.

## Scope Exclusions

Do NOT implement in this task:

- XP progression
- level-up mechanics
- automatic level rewards
- respec
- equipment backend
- inventory backend
- item stat bonuses
- skill tree
- passive tree
- warp backend
- combat
- monsters
- chat backend
- new CharacterStats formulas
- database migrations

## Testing

Use TDD for the new interactive HUD behavior where meaningful.

At minimum cover the client-side behavior that applies an authoritative
successful allocation response.

Tests should prove that:

- stat_points updates
- selected main stat updates
- derived stat display updates
- HP maximum can change while current HP remains unchanged
- Mana maximum can change while current Mana remains unchanged
- HP/Mana bar synchronization uses server-supplied current/max values
- buttons disable when stat_points reaches zero
- allocation UI remains in Details without page reload

Do not replace the existing server-side CharacterStatAllocator tests.

Run:

php tests/run.php

node tests/ExplorationHudTest.js

Run PHP syntax checks for every changed PHP file.

Run JavaScript syntax checks for every changed JS file.

Run:

git diff --check
git status --short --branch

## Manual Browser Test Checklist

Report a checklist covering:

1. Details tab opens without reload.
2. Correct Stat Points shown.
3. Correct STR/DEX/VIT/ENE/FATE shown.
4. Derived CharacterStats values are correct.
5. +Strength spends exactly one point.
6. +Dexterity spends exactly one point.
7. +Vitality increases Maximum Life.
8. Vitality does not restore current HP.
9. +Energy increases Maximum Mana.
10. Energy does not restore current Mana.
11. +Fate updates its derived values.
12. Remaining Stat Points update immediately.
13. Main-tab HP text/bar update after Vitality allocation.
14. Main-tab Mana text/bar update after Energy allocation.
15. Zero remaining points disables all + controls.
16. Rapid clicking cannot spend more points than available.
17. Refresh preserves allocated stats.
18. Map position remains unchanged.
19. Movement still works afterward.
20. Trap HP updates still work.
21. Chest Gold updates still work.
22. Standalone Character Selection Allocate Stats page still works.

## Completion Report

Report:

- files created
- files modified
- implementation summary
- whether allocate_stat.php required backward-compatible JSON support
- security behavior preserved
- tests added/changed
- exact verification commands
- exact results
- manual browser testing required
- any known compromises

Do not commit.

Do not push.

Finish with:

READY FOR TESTING
