# ASCII Quest II - Current Development Task

## Status

TASK 6 IMPLEMENTED — AWAITING CHECKPOINT REVIEW

## Current Phase

Combat Milestone 1 Tasks 1–5 are live-integrated. Migration 003 is applied and
verified.

Focused Task 6 implementation Tasks 1–8 are locally committed. The
implementation has not been pushed or deployed. Migration 004 has been created
and reviewed locally but has NOT been applied. No live database or server
access occurred during Task 9.

Fresh Task 9 verification passed with 192 PHP tests and 28 Exploration HUD
tests, both with zero failures.

## Authoritative Documents

- Design specification:
  `docs/superpowers/specs/2026-08-31-combat-milestone-1-design.md`
- Implementation plan:
  `docs/superpowers/plans/2026-08-31-combat-milestone-1.md`
- Focused Task 6 implementation plan:
  `docs/superpowers/plans/2026-09-11-combat-task-6-ai-damage.md`

The 31 August 2026 Combat Milestone 1 design supersedes older descriptions of
Action as multiple independent concurrent action bars.

## Next Review Gate

Review and approve the locally committed focused Task 6 implementation and
this Task 9 tracker checkpoint. Combat Milestone 1 Task 7 (player reaction
Block) has NOT started and must not begin without explicit approval.

Focused-plan Task 10 is an approval-gated live-integration runbook only. Do not
push, access the live server/database, apply Migration 004, or deploy Task 6
without separate explicit approval.

Combat entry is automatic when authoritative movement enters the stationary
Cave Brute's one-tile orthogonal fighting range; pressing E is not involved.
Existing adjacent-click movement remains unchanged outside combat.

Real equipment persistence/swapping and functional Chat remain deferred as
recorded in the specification. Their future interface/tab contracts must be
preserved without inventing those backends in Combat Milestone 1.

Potion behavior, combat HUD implementation, rewards/victory, permanent death,
Slayer behavior, and Accuracy/Critical/Dodge resolution remain deferred.

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

Combat Milestone 1 Task 5 was accepted after live API and database testing.
Manual weapon start, offensive snapshots, cooldown timing, resolution,
idempotent replay, and the no-damage Task 5 boundary were verified live.
