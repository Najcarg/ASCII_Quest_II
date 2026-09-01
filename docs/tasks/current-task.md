# ASCII Quest II - Current Development Task

## Status

ACTIVE — COMBAT MILESTONE 1: FOUNDATION

## Current Phase

Tasks 1–2 are implemented and have passed checkpoint review. The centralized
combat definitions, pure encounter/Turn/Action domain, reviewed Migration 003,
and `CombatRepository` foundation now exist.

Task 3 has NOT started. Migration 003 has been created and reviewed but HAS NOT
BEEN APPLIED. No live combat integration exists yet: exploration movement,
runtime endpoints, maps, HUD JavaScript, and CSS do not start or render combat.

## Authoritative Documents

- Design specification:
  `docs/superpowers/specs/2026-08-31-combat-milestone-1-design.md`
- Implementation plan:
  `docs/superpowers/plans/2026-08-31-combat-milestone-1.md`

The 31 August 2026 Combat Milestone 1 design supersedes older descriptions of
Action as multiple independent concurrent action bars.

## Next Review Gate

Do not begin Task 3 without explicit approval. Do not apply Migration 003
without separate explicit approval and the required live schema/backup
preflight.

Combat entry is automatic when authoritative movement enters the stationary
Cave Brute's one-tile orthogonal fighting range; pressing E is not involved.
Existing adjacent-click movement remains unchanged outside combat.

Real equipment persistence/swapping and functional Chat remain deferred as
recorded in the specification. Their future interface/tab contracts must be
preserved without inventing those backends in Combat Milestone 1.

Disconnected catch-up is capped by one authoritative configuration value at
five seconds. Relevant transactions lock Champion, then active encounter,
then action/event rows. Character creation remains available during combat,
but another Champion cannot Enter Dungeon until the unresolved encounter
closes; Resume Battle remains available for the fighting Champion.

## Last Accepted Milestone

Warp Milestone 1 was accepted after live browser testing.
