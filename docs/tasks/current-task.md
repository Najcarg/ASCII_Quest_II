# ASCII Quest II - Current Development Task

## Status

ACTIVE

## Task

Implement Champion stat-point allocation.

A Champion can spend stored `stat_points` on exactly one of these permanent
main stats:

- strength
- dexterity
- vitality
- energy
- fate

Do not implement XP gain or level-up logic in this task.

## Existing Rules

Read `/AGENTS.md` before making changes.

Use the existing character-stat system:

- `ascii-quest/config/character_stats.php`
- `ascii-quest/lib/CharacterStats.php`

Do not duplicate derived-stat formulas.

Increasing Vitality or Energy changes maximum resources but must NOT restore
current HP or current Mana.

Example:

Before:

- current_hp = 180
- max_life = 200
- vitality = 10

After spending one point on Vitality:

- vitality = 11
- max_life = 210
- current_hp remains 180

Result:

`180 / 210 HP`

## Required User Flow

From Character Selection, the player must be able to open a stat allocation
page for one of their own Champions.

The page must display:

- Champion name
- class
- level
- Strength
- Dexterity
- Vitality
- Energy
- Fate
- available stat points

Display the Champion's calculated values useful for verifying allocation,
including at minimum:

- current / maximum Life
- current / maximum Mana
- Melee Damage
- Toughness
- Spell Power
- Critical Chance
- Loot Chance
- Gold Find

Each main stat must have a `+` control.

If no stat points remain, allocation controls must be disabled or unavailable.

## Server-Side Allocation

Create a dedicated server-side allocation action.

Recommended files:

Create:

- `ascii-quest/character_stats.php`
- `ascii-quest/allocate_stat.php`

Modify:

- `ascii-quest/character_select.php`

You may adjust file boundaries if the existing code clearly requires it, but
do not refactor unrelated code.

Allocation must be server-authoritative.

For every allocation request:

1. Require a logged-in user.
2. Validate the character ID.
3. Validate that the Champion belongs to the logged-in user.
4. Accept only:
   - `strength`
   - `dexterity`
   - `vitality`
   - `energy`
   - `fate`
5. Start a MariaDB transaction.
6. Lock/re-read the Champion row as necessary to prevent double spending.
7. Verify `stat_points > 0`.
8. Increment exactly one selected stat by 1.
9. Decrement `stat_points` by 1.
10. Commit.
11. Roll back on failure.
12. Redirect safely back to the stat page.

Never trust a stat value supplied by the browser.

The browser may identify which stat to increment, but it must never submit the
new value.

## Security Requirements

A user must not be able to:

- allocate points to another user's Champion
- allocate an unknown/arbitrary database column
- spend a point when `stat_points = 0`
- make `stat_points` negative
- manipulate current HP/Mana by spending stats
- bypass the allowed-stat list

Use prepared statements.

Do not expose database errors or credentials in the browser.

## Database

No schema migration is required.

The existing `characters` table already contains:

- `stat_points`
- `strength`
- `dexterity`
- `vitality`
- `energy`
- `fate`

Do not alter the database schema.

## Testing

Use test-driven development.

Add automated tests for the allocation behaviour where practical.

At minimum verify:

1. valid Strength allocation spends exactly one point
2. valid Vitality allocation raises calculated Maximum Life
3. current HP does not increase when Vitality increases
4. valid Energy allocation raises calculated Maximum Mana
5. current Mana does not increase when Energy increases
6. invalid stat names are rejected
7. allocation with zero points is rejected
8. another user's Champion cannot be modified
9. stat_points cannot become negative

Run:

`php tests/run.php`

Run PHP syntax checks for every changed PHP file.

Run:

`git diff --check`

Run:

`git status`

Do not commit.

Do not push.

## Scope

Do NOT implement:

- XP rewards
- automatic level-up
- combat
- equipment
- inventory
- passive tree
- active skills
- stat respec
- HUD redesign

This task is only permanent main-stat allocation.

## Completion Report

Report:

- files created
- files modified
- implementation summary
- tests added
- exact test commands run
- results
- manual browser testing required

Finish with:

`READY FOR TESTING`

Then stop and wait for user approval.
