# ASCII Quest II - Current Development Task

## Status

TASK 18 PERSISTENT INVENTORY FOUNDATION

READY FOR REVIEW

Task 18 adds the unapplied Migration 005 schema and starter catalogue,
Champion-owned immutable item instances, mutation-request receipts, a strictly
owner-scoped read service and endpoint, and the real 25-item paged Inventory
HUD. It does not grant starter items, generate drops, or equip items.

Migration:
`005_item_inventory_foundation`

Migration status:
`NOT APPLIED`

Authoritative design:
`docs/superpowers/specs/2026-10-02-item-system-design.md`

Implementation plan:
`docs/superpowers/plans/2026-10-02-item-system-plan.md`

## Verification

- Server PHP suite: 269 passed, 0 failed.
- Item HUD suite: 10 passed, 0 failed.
- Combat HUD suite: 29 passed, 0 failed.
- Exploration HUD suite: 30 passed, 0 failed.
- Migration 005 was inspected as SQL only and was not applied.

## Intentionally Deferred

- Starter item grants and equipment
- Random item generation and affixes
- Physical drops and claims
- Equipment relations and combat effects
- Disposal, finite capacity, vendors, trading, and crafting
