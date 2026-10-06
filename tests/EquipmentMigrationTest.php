<?php
declare(strict_types=1);

function equipmentMigrationSql(string $name): string
{
    $path = __DIR__ . '/../database/migrations/' . $name;
    if (!is_file($path)) {
        throw new RuntimeException($name . ' must exist.');
    }
    $sql = file_get_contents($path);
    if ($sql === false) {
        throw new RuntimeException($name . ' must be readable.');
    }
    return strtolower(preg_replace('/\s+/', ' ', $sql) ?? $sql);
}

function assertEquipmentSqlContains(string $sql, string $fragment, string $message): void
{
    if (!str_contains($sql, strtolower($fragment))) {
        throw new RuntimeException($message . ' Missing SQL fragment: ' . $fragment);
    }
}

return [
    'Migration 007 requires 006 and rejects rerun or partial installation' => function (): void {
        $sql = equipmentMigrationSql('007_character_equipment.sql');
        assertEquipmentSqlContains($sql, "migration_id = '006_item_generation_and_drops'", 'Prerequisite.');
        assertEquipmentSqlContains($sql, "migration_id = '007_character_equipment'", 'Rerun guard.');
        assertEquipmentSqlContains($sql, "table_name = 'character_equipment'", 'Partial table guard.');
        assertEquipmentSqlContains($sql, "column_name = 'snapshot_weapon_item_id'", 'Partial column guard.');
    },

    'Migration 007 creates one ownership-bound item per authoritative slot' => function (): void {
        $sql = equipmentMigrationSql('007_character_equipment.sql');
        assertEquipmentSqlContains($sql, 'create table character_equipment', 'Equipment relation.');
        assertEquipmentSqlContains($sql, 'primary key (character_id, equipment_slot)', 'One item per Champion slot.');
        assertEquipmentSqlContains($sql, 'unique key uq_character_equipment_item (item_id)', 'One slot per item.');
        assertEquipmentSqlContains($sql, 'foreign key (item_id, character_id)', 'Ownership-bound foreign key.');
        assertEquipmentSqlContains($sql, 'references character_items (id, character_id) on delete restrict', 'Equipped item deletion rule.');
        assertEquipmentSqlContains($sql, 'foreign key (character_id) references characters (id) on delete cascade', 'Champion deletion rule.');
        assertEquipmentSqlContains($sql, "check (equipment_slot in ('helm', 'gloves', 'chest', 'ring', 'weapon', 'off-hand', 'amulet', 'belt', 'charm', 'boots'))", 'Exact slot allowlist.');
    },

    'Migration 007 adds a restrictive nullable weapon snapshot reference' => function (): void {
        $sql = equipmentMigrationSql('007_character_equipment.sql');
        assertEquipmentSqlContains($sql, 'snapshot_weapon_item_id int unsigned null', 'Nullable historical snapshot.');
        assertEquipmentSqlContains($sql, 'key idx_combat_actions_snapshot_weapon_item (snapshot_weapon_item_id)', 'Snapshot lookup index.');
        assertEquipmentSqlContains($sql, 'foreign key (snapshot_weapon_item_id) references character_items (id) on delete restrict', 'Historical item retention.');
    },

    'Migration 007 creates no gameplay rows and changes no resources' => function (): void {
        $sql = equipmentMigrationSql('007_character_equipment.sql');
        if (preg_match('/insert\s+into\s+(character_items|character_equipment)/', $sql) === 1) {
            throw new RuntimeException('Migration 007 must not manufacture starter items or equipment.');
        }
        if (preg_match('/\bupdate\s+characters\b/', $sql) === 1 || preg_match('/\b(current_hp|current_mana)\b/', $sql) === 1) {
            throw new RuntimeException('Migration 007 must not alter Champion resources.');
        }
        if (substr_count($sql, "values ('007_character_equipment')") !== 1) {
            throw new RuntimeException('Migration identity must be recorded once.');
        }
    },

    'Migration 007 verification SQL is read only' => function (): void {
        $sql = equipmentMigrationSql('007_character_equipment_verify.sql');
        assertEquipmentSqlContains($sql, "migration_id = '007_character_equipment'", 'Migration record.');
        assertEquipmentSqlContains($sql, 'character_equipment', 'Equipment inspection.');
        assertEquipmentSqlContains($sql, 'snapshot_weapon_item_id', 'Snapshot inspection.');
        if (preg_match('/\b(insert|update|delete|alter|create|drop|truncate|call)\b/', $sql) === 1) {
            throw new RuntimeException('Migration 007 verification SQL must be read only.');
        }
    },
];
