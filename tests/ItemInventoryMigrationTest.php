<?php
declare(strict_types=1);

function itemInventorySql(string $name): string
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

function assertItemSqlContains(string $sql, string $fragment, string $message): void
{
    if (!str_contains($sql, strtolower($fragment))) {
        throw new RuntimeException($message . ' Missing SQL fragment: ' . $fragment);
    }
}

return [
    'Migration 005 requires 004 and rejects rerun or partial target tables' => function (): void {
        $sql = itemInventorySql('005_item_inventory_foundation.sql');
        assertItemSqlContains($sql, "migration_id = '004_combat_enemy_ai_initialization'", 'Prerequisite.');
        assertItemSqlContains($sql, "migration_id = '005_item_inventory_foundation'", 'Rerun rejection.');
        assertItemSqlContains($sql, "table_name in ('item_definitions', 'character_items', 'item_mutation_requests')", 'Partial install detection.');
        assertItemSqlContains($sql, 'if v_count <> 0 then', 'Any partial target set must fail.');
        assertItemSqlContains($sql, "table_name = 'characters'", 'Parent table preflight.');
        assertItemSqlContains($sql, "column_name = 'id'", 'Parent key preflight.');
        assertItemSqlContains($sql, "data_type = 'int'", 'Parent key family.');
        if (preg_match("/column_type\\s+regexp\\s+'[^']*int[^']*unsigned[^']*'/", $sql) !== 1) {
            throw new RuntimeException('Migration 005 must require characters.id INT UNSIGNED.');
        }
    },

    'Migration 005 creates the inventory foundation with exact ownership guards' => function (): void {
        $sql = itemInventorySql('005_item_inventory_foundation.sql');
        foreach (['item_definitions', 'character_items', 'item_mutation_requests'] as $table) {
            assertItemSqlContains($sql, 'create table ' . $table, $table . ' table.');
        }
        if (substr_count($sql, 'engine=innodb') !== 3) {
            throw new RuntimeException('Exactly three InnoDB tables are required.');
        }
        assertItemSqlContains($sql, 'character_id int unsigned null', 'Unclaimed item owner nullability.');
        assertItemSqlContains($sql, 'foreign key (character_id) references characters (id) on delete cascade', 'Champion ownership FK.');
        assertItemSqlContains($sql, 'foreign key (definition_key) references item_definitions (definition_key) on delete restrict', 'Definition FK.');
        assertItemSqlContains($sql, 'unique key uq_character_items_id_owner (id, character_id)', 'Future ownership FK support.');
        assertItemSqlContains($sql, 'key idx_character_items_inventory (character_id, claimed_at, id)', 'Stable inventory ordering.');
        assertItemSqlContains($sql, 'check (item_level > 0)', 'Positive item level.');
        assertItemSqlContains($sql, "check (rarity in ('normal', 'magic', 'rare'))", 'Rarity allowlist.');
        assertItemSqlContains($sql, '(character_id is null and claimed_at is null)', 'Unclaimed invariant.');
        assertItemSqlContains($sql, '(character_id is not null and claimed_at is not null)', 'Claimed invariant.');
    },

    'Migration 005 preserves immutable provenance and request token uniqueness' => function (): void {
        $sql = itemInventorySql('005_item_inventory_foundation.sql');
        assertItemSqlContains($sql, 'source_type varchar(24) character set ascii collate ascii_bin not null', 'Source type identity.');
        assertItemSqlContains($sql, 'source_key varchar(128) character set ascii collate ascii_bin not null', 'Source key identity.');
        assertItemSqlContains($sql, "check (source_type in ('starter', 'combat_drop'))", 'Exact source allowlist.');
        assertItemSqlContains($sql, 'unique key uq_character_items_source (character_id, source_type, source_key)', 'Exactly-once starter provenance.');
        assertItemSqlContains($sql, 'request_token char(36) character set ascii collate ascii_bin not null', 'UUID token storage.');
        assertItemSqlContains($sql, 'request_fingerprint char(64) character set ascii collate ascii_bin not null', 'Request fingerprint.');
        assertItemSqlContains($sql, "check (command_type in ('claim', 'equip', 'unequip', 'swap'))", 'Future command allowlist.');
        assertItemSqlContains($sql, 'unique key uq_item_mutation_requests_token (character_id, request_token)', 'Per-Champion token uniqueness.');
        if (preg_match('/source_(type|key)[^;]*(display_name|name)/', $sql) === 1) {
            throw new RuntimeException('Provenance must never be derived from display text.');
        }
    },

    'Migration 005 seeds only the four stable starter catalogue definitions' => function (): void {
        $sql = itemInventorySql('005_item_inventory_foundation.sql');
        foreach (['basic_sword', 'basic_wand', 'basic_dagger', 'basic_mace'] as $key) {
            if (substr_count($sql, "'" . $key . "'") !== 1) {
                throw new RuntimeException($key . ' must be seeded exactly once.');
            }
        }
        assertItemSqlContains($sql, "'weapon'", 'Starter category and equipment slot.');
        assertItemSqlContains($sql, "'normal'", 'Normal-capable starter rarity.');
        if (preg_match('/insert\s+into\s+character_items/', $sql) === 1) {
            throw new RuntimeException('Migration 005 must not grant starter instances.');
        }
    },

    'Migration 005 is additive and records its identity once' => function (): void {
        $sql = itemInventorySql('005_item_inventory_foundation.sql');
        foreach (['/\bupdate\s+characters\b/', '/\bdelete\s+from\b/', '/\btruncate\b/', '/\bdrop\s+table\b/', '/\b(current_hp|current_mana)\b/'] as $pattern) {
            if (preg_match($pattern, $sql) === 1) {
                throw new RuntimeException('Migration 005 must not mutate Champion resources or existing rows.');
            }
        }
        if (substr_count($sql, "values ('005_item_inventory_foundation')") !== 1) {
            throw new RuntimeException('Migration 005 must record one migration identity.');
        }
    },

    'Migration 005 verification SQL is read only and inspects structure and counts' => function (): void {
        $sql = itemInventorySql('005_item_inventory_foundation_verify.sql');
        assertItemSqlContains($sql, "migration_id = '005_item_inventory_foundation'", 'Migration record verification.');
        assertItemSqlContains($sql, "table_name in ('item_definitions', 'character_items', 'item_mutation_requests')", 'Table verification.');
        assertItemSqlContains($sql, 'starter_definition_count', 'Starter catalogue count.');
        assertItemSqlContains($sql, 'character_item_count', 'Owned item count.');
        if (preg_match('/\b(insert|update|delete|alter|create|drop|truncate|call)\b/', $sql) === 1) {
            throw new RuntimeException('Migration 005 verification SQL must be read only.');
        }
    },
];
