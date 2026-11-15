/* READ-ONLY verification for Migration 008. */
USE ascii_quest;

SELECT migration_id, applied_at FROM schema_migrations
WHERE migration_id = '008_combat_elemental_snapshots';

SELECT TABLE_NAME, COLUMN_NAME, COLUMN_TYPE, IS_NULLABLE, COLUMN_DEFAULT
FROM information_schema.COLUMNS
WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'combat_actions'
  AND COLUMN_NAME IN ('snapshot_fire_damage', 'snapshot_lightning_damage',
                      'snapshot_cold_damage', 'snapshot_poison_damage')
ORDER BY ORDINAL_POSITION;
