# ASCII Quest II - Current Development Task

## Status

TASK 7 IMPLEMENTED / VERIFIED / READY FOR CHECKPOINT

## Current Phase

Current focus is Combat Milestone 1 — Task 7 bounded Block reaction.

Task 7 now provides one persisted server-created Block opportunity per eligible
enemy action, including a secure token and normalized server-generated popup
coordinates that survive refresh without rerolling. An accepted attempt creates
a separate resolved player Block command linked by `parent_action_id`; its
request UUID replays idempotently, while different second, wrong, expired, and
cross-owner attempts are rejected. Block costs zero Action and does not
interrupt a pending player action.

Player reaction Block and Cave Brute defensive Block use independent
configuration and resolver boundaries. Current Champion defense is fetched at
hit resolution, `BlockResolver` runs exactly once, passive defense is applied
once, and missed, failed, and successful provisional outcomes persist exactly
once. Repeated synchronization cannot reroll or reapply damage.

The sanitized `reaction_prompt` and strict `combat_block.php` POST, session,
CSRF, and input-allowlist boundary expose player intent only and reject
client-authored authoritative combat values.

The foundation-only player Block values are 20% chance, 50% reduction, and
normalized popup safe bounds `0.100`–`0.900`.

Fresh verification passed with 202 PHP tests, 28 Exploration HUD tests, and 10
focused Task 7 tests, all with zero failures.

## Authoritative Documents

- Design specification:
  `docs/superpowers/specs/2026-08-31-combat-milestone-1-design.md`
- Implementation plan:
  `docs/superpowers/plans/2026-08-31-combat-milestone-1.md`
- Focused Task 6 implementation plan:
  `docs/superpowers/plans/2026-09-11-combat-task-6-ai-damage.md`
- Focused Task 7 implementation plan:
  `docs/superpowers/plans/2026-09-12-combat-task-7-block-reaction.md`

The 31 August 2026 Combat Milestone 1 design supersedes older descriptions of
Action as multiple independent concurrent action bars.

## Next Review Gate

Review and approve the Task 7 checkpoint. Combat Milestone 1 Task 8 has NOT
started and must not begin without explicit approval.

Combat entry is automatic when authoritative movement enters the stationary
Cave Brute's one-tile orthogonal fighting range; pressing E is not involved.
Existing adjacent-click movement remains unchanged outside combat.

Real equipment persistence/swapping and functional Chat remain deferred as
recorded in the specification. Their future interface/tab contracts must be
preserved without inventing those backends in Combat Milestone 1.

Potion behavior, combat HUD implementation, rewards/victory, permanent death,
Slayer behavior, and Accuracy/Critical/Dodge resolution remain deferred.

Migration 003 already contained the Task 7 Block persistence fields. No Task 7
migration was required.

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

Combat Milestone 1 Tasks 1–6 are accepted in the current base. Task 7 is the
current local checkpoint awaiting review.
