# ASCII Quest II - Current Development Task

## Status

TASK 3 IMPLEMENTED — AWAITING CHECKPOINT REVIEW

## Current Phase

Tasks 1–2 have passed checkpoint review. Task 3 now implements atomic
movement-triggered combat entry, the shared exploration access guard, durable
combat-mode recognition, selection CSRF, the configured Cave Brute overlay,
and exploration input locking. Task 3 awaits user/checkpoint review.

Migration 003 has been created and reviewed but HAS NOT BEEN APPLIED. No live
database or browser verification has been performed. Task 4 authoritative
timeline synchronization has NOT started.

## Authoritative Documents

- Design specification:
  `docs/superpowers/specs/2026-08-31-combat-milestone-1-design.md`
- Implementation plan:
  `docs/superpowers/plans/2026-08-31-combat-milestone-1.md`

The 31 August 2026 Combat Milestone 1 design supersedes older descriptions of
Action as multiple independent concurrent action bars.

## Next Review Gate

Do not begin Task 4 without explicit approval. Do not apply Migration 003
without separate explicit approval and the required live schema/backup
preflight.

Combat entry is automatic when authoritative movement enters the stationary
Cave Brute's one-tile orthogonal fighting range; pressing E is not involved.
Existing adjacent-click movement remains unchanged outside combat.

Real equipment persistence/swapping and functional Chat remain deferred as
recorded in the specification. Their future interface/tab contracts must be
preserved without inventing those backends in Combat Milestone 1.

Disconnected catch-up is capped by one authoritative configuration value at
five seconds. Account-wide combat exclusion transactions lock Champion, then
the owning Account row (`users.id`) as a narrow combat mutex, then the active
encounter, then action/event rows. This is not a general application locking
policy. Character creation remains available during combat, but another
Champion cannot Enter Dungeon until the unresolved encounter closes; Resume
Battle remains available for the fighting Champion.

`game.php` makes a read-only combat-mode decision and does not hold the Account
mutex while rendering HTML. If that rendered exploration view becomes stale,
every later state-changing request reacquires the atomic Champion → Account →
Encounter guard before mutation, so stale UI cannot change exploration state.

## Last Accepted Milestone

Warp Milestone 1 was accepted after live browser testing.
