# ASCII Quest II - Current Development Task

## Status

ACTIVE

## Task

Clean up obsolete exploration/character-selection UI after acceptance of the
in-game Main + Details stat-allocation HUD.

This task removes redundant labels and the temporary standalone stat-allocation
entry point.

Do not add new gameplay systems.

## Read First

Read:

- `/AGENTS.md`
- `ascii-quest/game.php`
- `ascii-quest/character_select.php`
- `ascii-quest/character_stats.php`
- `ascii-quest/allocate_stat.php`
- existing HUD tests
- existing PHP tests

Inspect references before deleting anything.

## 1. Simplify Exploration Header

In `game.php` remove the redundant subtitle:

`Exploration`

under the main:

`ASCII Quest`

title.

The main title remains.

## 2. Remove Duplicate Current Area Block

Remove the header block that currently shows:

`CURRENT AREA`
`Deep Cave`

or equivalent current map name.

The map name must continue to appear above the center map viewport.

There should be only one visible map/area title in the exploration HUD.

Do not remove the map title above the map.

Do not change map-loading logic.

## 3. Remove Character Selection Allocate Stats Entry

The in-game Main tab is now the accepted stat-allocation UI.

Remove the Character Selection button/link that displays:

`Allocate Stats (X available)`

or equivalent.

Character Selection must continue showing:

- Champion identity
- class
- level
- HP
- Mana
- main stats
- XP
- Gold
- Enter Dungeon
- Delete Character
- Create Another Character
- Back to Main Menu

Do not remove stat values from Character Selection.

## 4. Remove Temporary Standalone Stat Page

The temporary:

`ascii-quest/character_stats.php`

page is no longer required if no remaining application code depends on it.

Before deleting it:

- search the repository for references to `character_stats.php`
- confirm nothing except the obsolete Character Selection link depends on it

If safe, delete:

`ascii-quest/character_stats.php`

Do NOT delete:

`ascii-quest/lib/CharacterStats.php`

These are completely different files.

The CharacterStats calculation service is core game logic and must remain.

## 5. Keep Allocation Backend

KEEP:

`ascii-quest/allocate_stat.php`

The in-game HUD still uses this endpoint.

Do not remove or weaken:

- authentication
- CSRF
- ownership checking
- transaction locking
- whitelist validation
- JSON response mode
- standalone redirect compatibility unless it becomes unreachable naturally

Do not redesign the endpoint in this cleanup.

## 6. Preserve Current HUD

Do not redesign:

- Main
- Details
- Warp
- Items
- Skill Tree
- Passive Tree
- paper doll
- Gold
- Loadout
- Inventory
- map
- Information/Server Info/Chat

This is only header/obsolete-page cleanup.

## 7. Preserve Gameplay

Do not break:

- login
- sessions
- Character Selection
- Enter Dungeon
- Change Character
- Main Menu
- movement
- traps
- chests
- stairs
- map transitions
- HP/Mana persistence
- Gold updates
- stat allocation in Main
- Details derived statistics

## Database

No database changes.

No migration.

## Testing

Update tests only where existing assertions refer to removed UI.

Run:

php tests/run.php
node tests/ExplorationHudTest.js

Run PHP syntax checks for every changed PHP file.

If `character_stats.php` is deleted, confirm there are no repository references
remaining to that page.

Run:

git grep -n "character_stats.php" -- . ':!docs' || true

Run:

git diff --check
git status --short --branch

## Manual Browser Checklist

Report testing required for:

1. Exploration header shows ASCII Quest without `Exploration`.
2. Header no longer shows duplicate Current Area/map name.
3. Map name remains visible above map.
4. Character Selection no longer shows Allocate Stats.
5. Character Selection Champion information remains correct.
6. Enter Dungeon works.
7. Main in-game stat allocation still works.
8. Details still works.
9. Change Character works.
10. Main Menu works.
11. No broken link to character_stats.php exists.

## Completion Report

Report:

- files modified
- files deleted
- repository reference check result
- tests/results
- manual browser testing required
- known compromises

Do not commit.

Do not push.

Finish with:

READY FOR TESTING
