# ASCII Quest II

Enter a world built from symbols.

Explore forgotten caves, survive traps, discover treasure, unlock ancient Warp points, and grow your Champion.

Every step matters.

## What Is ASCII Quest II?

ASCII Quest II is a browser-based dungeon RPG where text symbols and Unicode glyphs form the world around you. Dark passages, hazards, treasure, and routes between distant places are presented as a living ASCII map.

It is an evolving playable prototype: exploration is already taking shape, while combat and deeper character systems are still being forged.

## Become a Champion

Choose one of four classes:

- Warrior
- Mage
- Rogue
- Cleric

Every Champion is shaped by five main attributes:

- Strength
- Dexterity
- Vitality
- Energy
- Fate

These attributes influence the Champion's combat potential, resilience, resources, and fortune. Available stat points can be assigned from the exploration HUD.

## Explore the World

Travel through ASCII dungeon maps using the keyboard. Walls and water shape your path; traps threaten your HP; treasure chests reward Gold; and stairs lead between connected areas.

Your Champion's map position, HP, Mana, Gold, attributes, and other important state persist between visits. Broader character and XP progression are still under development.

## Learn the Language of the Dungeon

- `Ω` — Champion
- `⬡` — Warp point
- `◈` — treasure chest
- `▲` — active trap
- `~` — water
- `<` / `>` — stairs and map transitions
- `#` — wall

## Controls

Exploration is keyboard-driven:

- `WASD` or Arrow Keys — move
- `E` — interact

Combat is still under development. Its direction is a dedicated battle scene built around mouse/pointer-only decisions and time pressure, without exploration keys or combat hotkeys.

## Discover Warp Points

When you find a `⬡` Warp point, stand directly above, below, left, or right of it and press `E`.

The destination becomes permanently unlocked for that Champion and appears in the Warp tab. Once discovered, it can be selected from anywhere during exploration. Warp travel costs Gold and asks for confirmation before the journey begins. Each Champion keeps a separate collection of unlocked destinations.

## Your Champion

The exploration HUD keeps the dungeon and your Champion's state close at hand:

- **Main** — quick Champion status, resources, and main attributes
- **Details** — deeper derived statistics
- **Warp** — discovered fast-travel destinations
- **Items** — the visual equipment, loadout, and inventory foundation

Skill Tree and Passive Tree panels are present as placeholders for later development. Equipment, loadout, and inventory management are not yet active gameplay systems.

## Current Adventure

Playable systems currently include:

- Character creation and selection
- Warrior, Mage, Rogue, and Cleric Champions
- Persistent Champion attributes, HP, Mana, Gold, and map position
- In-game stat allocation
- ASCII map exploration with movement and collision
- Traps, treasure chests, and Gold rewards
- Stairs and transitions between maps
- The exploration HUD and derived-stat display
- Champion-specific Warp discovery and confirmed, paid fast travel

## The Road Ahead

The world is intended to grow through combat, enemies, loot, functional equipment and inventory, active skills, passive progression, and more maps and areas.

These systems remain works in progress, with no promised order or timeline.

## Project Status

ASCII Quest II is actively being developed as an evolving playable prototype. Existing systems may continue to change as the adventure expands.

## Technical Note

Built with PHP · MariaDB · Vanilla JavaScript · CSS · JSON maps
