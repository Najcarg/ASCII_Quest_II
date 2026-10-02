# Combat Milestone 1 Completion Record

**Status:** Complete

**Closeout date:** 2 October 2026

**Accepted closeout base:**
`465494cf3d6f9dd4843dfe87e54f73b4a8c58e18`

Combat Milestone 1 is complete after automated verification, migration
application, live runtime reconciliation, and browser gameplay verification.
This record closes the milestone; it does not introduce gameplay behavior.

## Automated Verification

The server verification completed with zero failures:

| Suite | Passed | Failed |
|---|---:|---:|
| Permanent PHP | 251 | 0 |
| Combat HUD | 29 | 0 |
| Exploration HUD | 30 | 0 |

## Database State

- Migration `003_combat_foundation` is applied.
- Migration `004_combat_enemy_ai_initialization` is applied.
- No migration is introduced by this closeout task.

## Runtime Reconciliation

Live verification initially found that stale copied PHP files were being
served even though the repository checkout represented accepted work. The
served runtime was reconciled to accepted `main`, and verification was repeated
against the corrected runtime. This distinction between repository state and
served files must remain part of future deployment checks.

## Live Gameplay Verification

The following Milestone 1 behavior was verified in the corrected live runtime:

- Victory completes and displays the exact configured reward: 25 Gold and 40
  raw EXP.
- Victory remains in the persistent `victory_loot` phase until explicitly
  closed.
- Permanent death persists zero HP and the DEAD lifecycle state.
- Potion use heals through the existing authoritative, charge-limited path.
- Block uses the bounded server-issued reaction opportunity.
- Flame Strike starts, resolves, and presents its cooldown/effect behavior.
- Combat action presentation colours match their meanings:
  - **Red — Ready:** the command can be used immediately.
  - **Gold — Standby:** cooldown, actor busy, no Action, or insufficient Turn
    time; the command remains a valid combat ability that may become usable.
  - **Gray — Unavailable:** empty, inactive, dead, target unavailable, or
    terminal states.

The reusable deployment and browser procedure is retained in
`docs/testing/combat-milestone-1-live-checklist.md`.

## Intentionally Deferred Systems

Combat Milestone 1 intentionally does not include:

- Full Slayer gameplay
- Slayer titles and bounties
- World bosses
- PvP
- Functional Chat backend
- Escape system
- Skill tree expansion
- Passive tree
- Final balance
- Final Accuracy, Critical, and Dodge formulas
- Resurrection

These omissions do not prevent Milestone 1 from being complete.

## Approved Next Roadmap

1. **Task 17 — Item System Design**
2. **Task 18 — Persistent Inventory Foundation**
3. **Task 19 — Item Generation + Physical Drops**
4. **Task 20 — Equipment System**

The next gameplay-loop goal is:

```text
fight
-> victory
-> physical item drop
-> inventory
-> equip item
-> equipment affects future combat
```

Task 17 is the next planned design task and is not part of this closeout.
