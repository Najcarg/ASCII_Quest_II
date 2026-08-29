# ASCII Quest II - Current Development Task

## Status

ACTIVE

## Task

Build the exploration HUD foundation.

This task establishes the new in-game exploration layout and tab structure
without introducing new gameplay systems.

The HUD will become the main shell for future:

- character stats
- stat allocation
- warp
- equipment
- inventory
- skills
- passive tree
- exploration information

Do not implement those backend systems in this task unless explicitly listed
below.

## Read First

Read `/AGENTS.md`.

Inspect the current implementation before making changes, especially:

- `ascii-quest/game.php`
- `ascii-quest/css/style.css`
- `ascii-quest/js/game_controls.js`
- existing map rendering/movement code
- existing Champion/stat calculation usage

Preserve all existing working exploration behaviour.

## Target Layout

Build a three-column exploration HUD.

Approximate structure:

ASCII Quest header
---------------------------------------------------------
Left Panel       | Center Map       | Right Panel
---------------------------------------------------------
Main             |                  | Items
Details          |       Map        | Skill Tree
Warp             |                  | Passive Tree
                 |                  |
Champion         |                  | Equipment
HP               |                  | Loadout
Mana             |                  | Inventory
XP               |                  |
Gold             |                  |
---------------------------------------------------------
Bottom non-combat information area
---------------------------------------------------------

The result should visually move toward the approved ASCII Quest HUD concept,
but this task is only the structural foundation.

## Header

The top area should include:

- ASCII Quest branding
- current area/map name
- Change Character
- Main Menu

Preserve the existing actions of those buttons.

## Left Panel Tabs

Create these tabs:

- Main
- Details
- Warp

### Main

Main is selected by default.

Show real Champion data already available in the current game:

- Champion glyph/portrait
- character name
- class
- level
- HP
- Mana
- XP
- Gold

HP, Mana and XP should be displayed as visual horizontal bars.

Use existing real data.

Do not invent fake percentages or placeholder numbers.

For HP/Mana, use the existing current values and calculated maximum values.

### Details

For this task, Details can contain a clearly marked placeholder such as:

`Detailed Champion statistics will appear here.`

Do not integrate stat allocation yet.

That will be the next task.

### Warp

For this task, Warp can contain a clearly marked placeholder such as:

`Discovered warp destinations will appear here.`

Do not create warp mechanics or database changes.

## Center Panel

Preserve the existing exploration map and all existing map behaviour.

The center panel should contain:

- current map/area title
- existing viewport/map
- existing character glyph
- existing map tiles
- existing movement
- existing map interactions
- existing message/event behaviour if currently required by exploration

Do not rewrite map logic unnecessarily.

Movement must continue to work exactly as before.

Preserve:

- normal movement
- walls/collision
- traps
- chests
- stairs
- map transitions
- character map overrides
- current position persistence

## Right Panel Tabs

Create:

- Items
- Skill Tree
- Passive Tree

Items is selected by default.

### Items

Create the visual shell only.

Equipment slots:

Left group:
- Helm
- Chest
- Gloves
- Belt
- Boots

Right group:
- Weapon
- Off-Hand
- Ring
- Amulet
- Charm

There is exactly:

- 1 Ring slot
- 1 Charm slot

Do not implement equipment backend behaviour yet.

Empty equipment slots should be visibly identifiable.

### Loadout

Below Equipment create five loadout slots:

- Skill Slot 1
- Skill Slot 2
- Skill Slot 3
- Ultimate Slot
- Potion

These are visual placeholders only.

Do not implement skill mechanics.

Combat is pointer/mouse-first.

Do not introduce Q/W/E/R or number-key combat shortcuts.

### Inventory

Create an empty visual inventory grid.

This task does NOT create:

- inventory database tables
- item storage
- drag/drop
- item tooltips
- equipment actions

Use clear empty slots.

### Skill Tree

Placeholder only.

Example:

`Skill Tree will be implemented in a later milestone.`

### Passive Tree

Placeholder only.

Example:

`Passive Tree will be implemented in a later milestone.`

## Bottom Panel

Create a non-combat lower information area.

Do not create a combat/fighting log.

Combat will use a dedicated battle scene later.

The bottom panel may contain shell tabs such as:

- Server Info
- Chat
- Information

These may be placeholders in this task.

Do not invent server systems, chat backend, player counts or fake online data.

Do not display fake values such as:

- fake uptime
- fake players online
- fake world boss timers
- fake server events

Placeholder labels are acceptable.

## Tabs

Left and right panel tabs must work in the browser without page reload.

Use lightweight vanilla JavaScript.

Do not introduce:

- React
- Vue
- Alpine
- jQuery
- npm dependencies

Default active state:

Left:
- Main

Right:
- Items

Tabs only need to persist for the current page session in this task.

Do not add database persistence for selected tabs.

## Styling

Keep the existing ASCII Quest visual identity:

- dark/black background
- gold/brown borders
- parchment/gold typography
- restrained glow
- fantasy/dungeon aesthetic

Avoid modern dashboard styling that looks unrelated to the existing game.

The page should use the available browser width more effectively than the
current narrow exploration layout.

The map should remain the primary visual focus.

Do not sacrifice map usability to make side panels excessively large.

Aim for a practical desktop layout first.

Responsive/mobile redesign is not required in this task.

## Existing Behaviour To Protect

This task must NOT break:

- login
- sessions
- Character Selection
- Change Character
- Main Menu
- map loading
- movement
- collision
- traps
- trap HP damage
- HP persistence
- Mana persistence
- chests
- gold rewards
- stairs
- map transitions
- map overrides
- Champion position persistence
- current CharacterStats calculations

## Stat Allocation

The `feature/stat-allocation` implementation is already the base of this
branch.

Do not remove:

- `ascii-quest/character_stats.php`
- `ascii-quest/allocate_stat.php`
- `ascii-quest/lib/CharacterStatAllocator.php`

Do not redesign or integrate that feature yet.

The next HUD task will move stat allocation into the Details tab.

The temporary standalone stat allocation page may remain accessible for now.

## Database

No database changes.

No migration.

Do not modify schema.

## Scope Exclusions

Do NOT implement in this task:

- Details stat allocation
- warp backend
- discovered locations
- item backend
- inventory backend
- equipment backend
- drag/drop
- item generation
- active skills
- passive skills
- combat
- battle scene
- monsters
- XP/level-up system
- server-status backend
- chat backend
- fake server information

## Testing

Use test-driven development where behaviour can be tested meaningfully.

Existing automated tests must continue passing:

`php tests/run.php`

Run syntax checking for every changed PHP file:

`php -l <file>`

If JavaScript is changed:

`node --check <file>`

Run:

`git diff --check`

Run:

`git status`

Do not commit.

Do not push.

## Manual Test Checklist

Before reporting completion, provide a browser-test checklist covering:

1. Main tab shown initially
2. Items tab shown initially
3. Left tab switching works
4. Right tab switching works
5. Champion information is correct
6. HP bar/value is correct
7. Mana bar/value is correct
8. XP bar/value is correct
9. Gold value is correct
10. Map renders
11. Movement works
12. Trap damage works
13. Chest interaction works
14. Gold updates
15. Stairs/map transitions work
16. Refresh preserves position/resources as before
17. Change Character works
18. Main Menu works

## Completion Report

Report:

- files created
- files modified
- implementation summary
- tests added or changed
- exact commands executed
- test results
- manual browser testing required
- any known visual compromises

Finish with:

`READY FOR TESTING`

Then stop.

Do not commit.
Do not push.
