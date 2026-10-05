# ASCII Quest II - Current Development Task

## Status

TASK 19 ITEM GENERATION + PHYSICAL DROPS

READY FOR REVIEW

Task 19 adds the unapplied Migration 006 schema, source-level immutable item
generation, durable physical drop and no-drop outcomes, explicit idempotent
claim, Close/Continue auto-claim, safe victory projection, and the physical
drop HUD. It does not grant starter items or equip/apply items.

Migration:
`006_item_generation_and_drops`

Migration status:
`NOT APPLIED`

Authoritative design:
`docs/superpowers/specs/2026-10-02-item-system-design.md`

Implementation plan:
`docs/superpowers/plans/2026-10-02-item-system-plan.md`

## Verification

- Server PHP suite: 293 passed, 0 failed.
- Item HUD suite: 10 passed, 0 failed.
- Combat HUD suite: 31 passed, 0 failed.
- Exploration HUD suite: 30 passed, 0 failed.
- Migration 006 was inspected as SQL only and was not applied.

## Intentionally Deferred

- Starter item grants
- Equipment relations and combat effects
- Disposal, finite capacity, vendors, trading, and crafting
