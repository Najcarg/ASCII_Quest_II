# ASCII Quest II - Current Development Task

## Status

ACTIVE

## Task

Refine the accepted exploration HUD after successful live testing of in-game
stat allocation.

This task is primarily presentation/layout work.

Do not create new gameplay systems.

## Read First

Read:

- `/AGENTS.md`
- `ascii-quest/game.php`
- `ascii-quest/css/style.css`
- `ascii-quest/js/exploration_hud.js`
- `ascii-quest/js/game_controls.js`
- `ascii-quest/allocate_stat.php`
- existing HUD tests

Preserve all currently working stat-allocation behavior.

## 1. Move Main Stats And Allocation To Main Tab

The five main stats no longer belong in Details.

Move these to the Main tab:

- Strength
- Dexterity
- Vitality
- Energy
- Fate

The Main tab should contain, in compact form:

- Champion glyph
- Champion name
- class
- level
- HP bar + current/max
- Mana bar + current/max
- XP bar + stored XP
- available Stat Points
- STR value + compact `+`
- DEX value + compact `+`
- VIT value + compact `+`
- ENE value + compact `+`
- FATE value + compact `+`

Stat allocation must continue working from Main without page reload.

Reuse the current allocation implementation.

Do not duplicate allocator logic.

When stat_points reaches zero:

- disable every +
- do not send allocation requests

The Main tab must stay compact and visually consistent with the existing HUD.

## 2. Details Becomes Derived Statistics Only

Remove from Details:

- Champion Details heading if no longer useful
- Stat Points
- STR
- DEX
- VIT
- ENE
- FATE
- allocation + buttons

Details should begin directly with derived CharacterStats information.

Keep the current groups:

### Core

- Maximum Life
- Maximum Mana
- Melee Damage
- Toughness
- Spell Power
- Action

### Combat / Chances / Rates

- Dodging
- Accuracy
- Critical Damage
- Critical Chance
- Attack Rate
- Cast Rate
- Block Rate

### Resistances

Keep every currently supported resistance.

### Utility / Recovery / Status

Keep all remaining currently supported CharacterStats values.

Do not change formulas or formatting rules.

Critical Damage remains a flat number, not a percentage.

## 3. Remove Successful Allocation Message

Do not show:

`Stat point allocated.`

A successful click already provides visible feedback because:

- stat value changes
- Stat Points changes
- derived values change
- HP/Mana maximum may change

Successful allocation therefore needs no message.

The allocation message/error area should:

- remain hidden/empty after success
- appear only when there is an actual error

Examples:

- no points available
- invalid request
- CSRF/session problem
- server failure

Do not remove useful error handling.

## 4. Increase Left Panel Useful Height

The Details panel currently stops significantly above the bottom of the
map + Information/Chat region.

Extend the usable left-side tab content vertically so its bottom aligns
approximately with the bottom of the center Information/Chat panel.

Goal:

Left HUD vertical extent approximately matches:

- center map
- map status line
- Information / Server Info / Chat panel

Do not make Details expand the whole page downward.

Do not add artificial blank space.

Details should use the available taller left panel and retain internal scrolling
when its content exceeds that height.

Main and Warp should use the same overall left-panel height so switching tabs
does not resize the HUD.

Preserve the accepted three-column layout.

## 5. Add One Visual Inventory Row

The current inventory grid is still only a visual placeholder.

Add exactly one additional visible row to the inventory grid.

This is for visual balance only.

IMPORTANT:

This does NOT define final inventory capacity.

Do not add:

- database inventory slots
- item records
- drag/drop
- item interaction
- inventory backend logic

Update markup/CSS only as needed.

## 6. Preserve Existing Right HUD

Do not redesign:

- paper-doll equipment
- Gold placement
- equipment slots
- loadout
- Items / Skill Tree / Passive Tree tabs

Still exactly:

- 1 Ring
- 1 Charm

Gold must retain:

`id="playerGold"`

and chest rewards must continue updating it live.

## 7. Preserve Stat Allocation Rules

Do not change:

- CharacterStatAllocator
- ownership security
- CSRF
- transaction locking
- whitelist
- prepared statements
- JSON response behavior
- no-free-healing rule
- CharacterStats formulas

Vitality:

current HP remains unchanged while Maximum Life may increase.

Energy:

current Mana remains unchanged while Maximum Mana may increase.

## 8. Preserve Existing Gameplay

Do not break:

- map
- movement
- collision
- traps
- trap HP/bar updates
- chests
- Gold updates
- stairs
- map transitions
- position persistence
- HP persistence
- Mana persistence
- Change Character
- Main Menu

## 9. Temporary Standalone Allocation Page

Keep for now:

- `character_stats.php`
- Character Selection Allocate Stats button/link

Do not remove them in this task.

That cleanup will happen only after this revised Main-tab allocation layout is
accepted live.

## Database

No database changes.

No migration.

## Testing

Use TDD where useful.

Update HUD tests to prove:

- allocation controls are now rendered/managed from Main
- Details still receives derived-stat updates
- successful allocation does not display a success message
- actual errors still display
- zero points disable all allocation buttons
- HP/Mana synchronization remains unchanged
- the extra inventory row is present if practical to test structurally

Run:

php tests/run.php
node tests/ExplorationHudTest.js

Run PHP syntax checks for changed PHP files.

Run JavaScript syntax checks for changed JS files.

Run:

git diff --check
git status --short --branch

## Manual Browser Checklist

Report testing required for:

1. Main opens initially.
2. Champion information remains correct.
3. STR/DEX/VIT/ENE/FATE are visible on Main.
4. Stat Points are visible on Main.
5. + buttons work from Main.
6. Successful allocation shows no success banner.
7. Errors still display if one occurs.
8. Details contains derived stats only.
9. Details has increased useful vertical height.
10. Details internal scrolling works.
11. Left panel does not resize when switching tabs.
12. Vitality updates max HP without healing.
13. Energy updates max Mana without restoring Mana.
14. Zero points disables + buttons.
15. Main HP/Mana bars remain correct.
16. Inventory shows one additional visual row.
17. Paper doll remains unchanged.
18. Gold/chests still work.
19. Movement/traps/stairs/transitions still work.
20. Refresh preserves state.

## Completion Report

Report:

- files modified
- implementation summary
- tests changed
- exact verification results
- manual browser testing required
- known compromises

Do not commit.

Do not push.

Finish with:

READY FOR TESTING
