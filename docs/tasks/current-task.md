# ASCII Quest II - Current Development Task

## Status

TASK 17 ITEM SYSTEM DESIGN

READY FOR REVIEW

Task 17 defines the authoritative persistent item, physical-drop, inventory,
equipment, security, migration, and combat-integration rules for Tasks 18–20.
It changes no production code or database state.

Design specification:
`docs/superpowers/specs/2026-10-02-item-system-design.md`

Implementation plan:
`docs/superpowers/plans/2026-10-02-item-system-plan.md`

## Prior Milestone Final Verification

- Server PHP suite: 251 passed, 0 failed.
- Combat HUD suite: 29 passed, 0 failed.
- Exploration HUD suite: 30 passed, 0 failed.
- Migration `003_combat_foundation` is applied.
- Migration `004_combat_enemy_ai_initialization` is applied.
- The live runtime was reconciled to accepted `main` after stale copied PHP
  files were found being served initially.
- Live victory, permanent death, Potion, Block, Flame Strike, exact victory
  reward display, and persistent `victory_loot` behavior were verified.
- Combat action colours were verified: red means ready, gold means temporary
  standby, and gray means unavailable.

The complete closeout record is in
`docs/testing/combat-milestone-1-completion.md`. The reusable deployment and
browser procedure remains in
`docs/testing/combat-milestone-1-live-checklist.md`.

## Next Roadmap

Task 17: Item System Design

Task 18: Persistent Inventory Foundation

Task 19: Item Generation + Physical Drops

Task 20: Equipment System

The next gameplay-loop goal is:

```text
fight
-> victory
-> physical item drop
-> inventory
-> equip item
-> equipment affects future combat
```

Task 17 is ready for review. Tasks 18–20 are planned but not implemented.

## Intentionally Deferred

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

## Authoritative Milestone Documents

- Design specification:
  `docs/superpowers/specs/2026-08-31-combat-milestone-1-design.md`
- Implementation plan:
  `docs/superpowers/plans/2026-08-31-combat-milestone-1.md`
- Completion record:
  `docs/testing/combat-milestone-1-completion.md`
- Live verification checklist:
  `docs/testing/combat-milestone-1-live-checklist.md`
