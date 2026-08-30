# ASCII Quest II - Current Development Task

## Status

ACTIVE

# Warp Milestone 1

## Goal

Implement Champion-specific warp discovery and paid fast travel between:

- deep_cave.json
- forgotten_cave.json

Each map may contain ZERO OR ONE warp.

Unlocked warps belong to the individual Champion and remain permanently unlocked.

## Approved Map Format

Each map may contain one object named:

warp

Example structure:

    "warp": {
      "id": "deep_cave",
      "name": "Deep Cave",
      "x": 10,
      "y": 10,
      "arrival_x": 10,
      "arrival_y": 11,
      "cost": 5,
      "glyph": "◈"
    }

Fields:

- id = permanent unique warp identifier
- name = displayed in Warp menu
- x/y = physical warp object position
- arrival_x/arrival_y = Champion arrival position
- cost = Gold cost to travel TO this destination
- glyph = ◈

Do NOT use a warps array.

Maximum one warp per map.

Map JSON is authoritative for:

- warp ID
- name
- map
- location
- arrival location
- cost

Database stores only which warp IDs each Champion has unlocked.

## Test Maps

Use:

- deep_cave.json
- forgotten_cave.json

Add one warp to each.

Test prices:

- Deep Cave = 5 Gold
- Forgotten Cave = 10 Gold

Inspect the maps and choose appropriate coordinates.

Warp x/y must:

- be reachable
- have at least one valid direct-adjacent interaction position

arrival_x/arrival_y must:

- be walkable
- not be the warp tile itself
- not be a wall
- not be a chest
- not be a trap
- not be stairs
- not trigger a map transition
- not be another harmful/special tile

Do not redesign the maps.

Report the exact coordinates selected.

## Warp Glyph

Render warp on the ASCII map as:

◈

The warp remains visible after discovery.

## Discovery Interaction

Use exactly the same 4-direction interaction distance as chests.

Allowed:

- directly above
- directly below
- directly left
- directly right

Rejected:

- diagonal
- same tile
- more than one tile away

Champion must deliberately CLICK the ◈ glyph.

Walking beside it must NOT automatically unlock it.

Walking onto it must NOT unlock it.

Unlocking does NOT teleport the Champion.

## Unlock Server Validation

Server must validate:

- authenticated session
- selected Champion ownership
- Champion authoritative current map
- Champion authoritative current x/y
- current map contains the requested warp
- requested warp ID matches the map warp
- Manhattan distance to warp is exactly 1
- CSRF
- prepared SQL statements

Do NOT trust browser-supplied:

- map
- warp coordinates
- warp name
- cost
- arrival coordinates

Client should send only the minimum identifier necessary.

## Successful Discovery

First successful discovery should show an exploration information message:

Warp unlocked: Deep Cave

or the relevant destination name.

Unlocking:

- costs 0 Gold
- does not teleport
- does not change position
- does not heal HP
- does not restore Mana
- does not change XP

Warp tab must update immediately without page reload.

Repeated clicking must be idempotent.

No duplicate DB rows.

## Database

Create a version-controlled migration following existing migration conventions.

Create a table conceptually equivalent to:

    character_warps

    character_id
    warp_id
    unlocked_at

Requirements:

- Champion-specific
- unique combination of character_id + warp_id
- no duplicate unlocks
- use existing foreign-key/cascade conventions where appropriate
- deleting a Champion must not leave orphan warp rows

Do NOT store:

- warp name
- cost
- map
- x/y
- arrival_x/arrival_y

Those remain authoritative in map JSON.

Do NOT apply the migration to the live database yet.

## Warp Tab

Implement the existing left-side Warp tab.

Only unlocked destinations for the CURRENT Champion appear.

Undiscovered destinations remain completely hidden.

Do NOT display ??? placeholders.

Example:

    WARP

    Deep Cave
    Cost: 5 Gold
    [ WARP ]

    Forgotten Cave
    Cost: 10 Gold
    [ WARP ]

Different Champions have independent unlock lists.

## Current Location

If the Champion is currently on the destination's map:

    Deep Cave
    CURRENT LOCATION

Do not show a travel button.

Do not charge Gold.

## Insufficient Gold

If Champion Gold is below the destination cost:

    Forgotten Cave
    Cost: 10 Gold
    [ NOT ENOUGH GOLD ]

Disable the action visually.

Server must still validate Gold independently.

## Where Warp Can Be Used

After a destination has been unlocked, the Champion may warp to it from ANYWHERE during normal exploration.

The Champion does NOT need to return to a ◈ warp object.

## Warp Confirmation

Clicking a WARP destination must NOT immediately travel.

Show an in-HUD confirmation such as:

    Warp to Forgotten Cave for 10 Gold?

    [ WARP ] [ CANCEL ]

Prefer custom HUD UI.

Do not use browser confirm() unless genuinely necessary.

Cancel:

- spends nothing
- changes nothing

Confirm:

- sends actual travel request

Prevent double submission while request is pending.

## Travel Server Validation

On confirmed travel server must re-check:

- authenticated session
- Champion ownership
- CSRF
- requested warp ID
- Champion has unlocked that warp
- authoritative warp definition exists
- authoritative map comes from server/map JSON
- authoritative cost comes from JSON
- Champion has enough Gold
- authoritative arrival coordinates
- arrival tile is still valid and safe

Never trust client-supplied:

- Gold cost
- destination map
- destination coordinates

## Atomic Travel

Gold deduction and map/position change must be one atomic transaction.

Conceptually:

    BEGIN
    lock Champion row
    validate Champion ownership
    validate warp unlock
    load authoritative destination
    validate Gold
    deduct Gold
    update current map
    update Champion x/y
    COMMIT

On failure:

    ROLLBACK

Never allow:

- Gold deducted but no travel
- travel without Gold deduction
- duplicate Gold charge caused by double click

## HP And Mana

Warp does NOT heal.

Preserve exactly:

- current HP
- current Mana

Do not change:

- Maximum Life
- Maximum Mana
- CharacterStats formulas

## Successful Travel

After travel display:

- destination map
- destination map title
- Champion at arrival_x/arrival_y
- correct viewport
- correct position display
- updated Gold

Keep existing:

id="playerGold"

Reuse existing map/movement/map-transition rendering and state synchronization where possible.

Do NOT implement a second independent map renderer.

## Server Architecture

Inspect existing architecture first.

Prefer focused Warp code instead of placing all logic directly into game.php.

A focused Warp service/repository is acceptable for:

- reading map warp definitions
- resolving warp_id to map
- validating definitions
- adjacency validation
- loading Champion unlocks
- unlocking
- travel transaction

Follow existing repository conventions.

Use only:

- PHP
- MariaDB
- vanilla JavaScript

Do NOT add:

- frameworks
- ORM
- new dependencies

There are only two test maps, so simple authoritative map scanning is acceptable.

Do not build unnecessary caching or registry systems.

## Warp Definition Validation

Safely validate:

- zero or one warp per map
- required fields exist
- warp ID is non-empty
- warp IDs are unique across maps
- name is valid
- x/y are valid integers
- arrival_x/arrival_y are valid integers
- cost is a non-negative integer

A map without a warp remains valid.

Duplicate warp IDs across maps must be detected/rejected.

## Security

Every unlock/travel write requires:

- authentication
- Champion ownership validation
- CSRF
- prepared SQL
- authoritative server validation
- safe generic errors

Do not expose SQL/database exceptions.

Browser must not be able to:

- unlock remotely
- unlock another Champion's warp
- invent a warp
- forge a cheaper cost
- select arbitrary map
- select arbitrary arrival coordinates

## Preserve Existing Systems

Do not break:

- login
- sessions
- character creation
- character selection
- Change Character
- Main Menu
- maps
- keyboard movement
- mouse movement
- collisions
- smooth movement
- chests
- traps
- stairs
- map transitions
- Gold updates
- HP persistence
- Mana persistence
- Main HUD
- stat allocation
- Details
- Items
- paper doll
- inventory shell
- Information
- Server Info
- Chat

Warp is additive.

## Explicit Non-Goals

Do NOT implement:

- multiple warps per map
- account-wide warp unlocks
- warp cooldowns
- dynamic prices
- distance pricing
- quest requirements
- item requirements
- town portals
- return portals
- animations
- sounds
- multiplayer sharing

## TDD

Use test-driven development.

Add server-side tests for Warp definitions:

- map without warp is valid
- map with one valid warp works
- duplicate warp IDs detected
- malformed warp rejected safely

Add adjacency tests:

- up accepted
- down accepted
- left accepted
- right accepted
- diagonal rejected
- distance greater than 1 rejected
- same tile rejected

Reuse chest adjacency behavior if a clean reusable abstraction exists.

Add unlock tests:

- adjacent owner can unlock
- remote unlock rejected
- another Champion rejected
- repeated unlock idempotent
- no Gold spent
- HP unchanged
- Mana unchanged

Add listing tests:

- only unlocked destinations appear
- Champion unlocks are independent
- current location detected
- costs come from JSON

Add travel tests:

- locked warp rejected
- sufficient Gold succeeds
- insufficient Gold rejected
- JSON cost is authoritative
- forged client price cannot work
- correct destination map stored
- correct arrival coordinates stored
- Gold deducted exactly once
- HP unchanged
- Mana unchanged
- current-location travel does not charge

Update/add JavaScript HUD tests:

- unlocked destinations render
- CURRENT LOCATION renders
- insufficient Gold action disabled
- valid WARP action renders
- first click opens confirmation
- Cancel sends no travel request
- Confirm sends travel request
- pending request prevents double click
- successful unlock refreshes Warp tab
- successful travel updates required HUD/map state
- errors display safely

## Migration

Create migration but DO NOT execute it on live database.

Completion report must include:

- migration filename
- exact schema
- exact Ubuntu command we will use later to apply it

## Verification

Run:

php tests/run.php

Run:

node tests/ExplorationHudTest.js

Run any additional Warp tests created.

Run PHP syntax checks for every changed PHP file.

Run JavaScript syntax checks for every changed JS file.

Run:

git diff --check

Run:

git status --short --branch

## Manual Testing Later

After review, commit, deployment and migration we will test:

1. Deep Cave warp is visible.
2. Diagonal interaction cannot unlock it.
3. Direct 4-direction adjacency can unlock it.
4. Warp unlocked message appears.
5. Deep Cave appears immediately in Warp tab.
6. Deep Cave shows CURRENT LOCATION.
7. Naturally travel to Forgotten Cave.
8. Discover Forgotten Cave warp.
9. Both warps remain unlocked.
10. Warp can be initiated from anywhere.
11. Confirmation appears.
12. Cancel spends nothing.
13. Confirm Deep Cave costs exactly 5 Gold.
14. Champion appears at Deep Cave arrival coordinates.
15. HP is unchanged.
16. Mana is unchanged.
17. Warp to Forgotten Cave costs exactly 10 Gold.
18. Refresh preserves both unlocks.
19. Another Champion has separate unlocks.
20. Chests, traps, movement and stairs still work.

## Completion Report

Report:

- files created
- files modified
- migration filename
- exact migration schema
- map JSON changes
- exact warp coordinates for both maps
- server architecture
- endpoints created/changed
- tests added/changed
- exact test counts/results
- security behavior
- live migration command
- deployment steps
- remaining browser tests
- known compromises

Do not commit.

Do not push.

Do not modify the live database.

Finish with:

READY FOR REVIEW
