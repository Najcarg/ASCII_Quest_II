<?php
declare(strict_types=1);

function itemDropSql(string $name): string
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

function assertDropSqlContains(string $sql, string $fragment, string $message): void
{
    if (!str_contains($sql, strtolower($fragment))) {
        throw new RuntimeException($message . ' Missing SQL fragment: ' . $fragment);
    }
}

return [
    'Migration 006 requires 005 and rejects rerun or partial installation' => function (): void {
        $sql = itemDropSql('006_item_generation_and_drops.sql');
        assertDropSqlContains($sql, "migration_id = '005_item_inventory_foundation'", 'Prerequisite.');
        assertDropSqlContains($sql, "migration_id = '006_item_generation_and_drops'", 'Rerun guard.');
        assertDropSqlContains($sql, "table_name in ('item_affix_definitions', 'item_affix_tiers', 'item_affix_category_rules', 'item_definition_affix_families', 'character_item_affixes', 'combat_item_drops')", 'Partial table guard.');
        assertDropSqlContains($sql, "column_name in ('loot_source_level', 'item_drops_generated_at')", 'Partial column guard.');
    },

    'Migration 006 creates normalized immutable affix relations' => function (): void {
        $sql = itemDropSql('006_item_generation_and_drops.sql');
        foreach (['item_affix_definitions', 'item_affix_tiers', 'item_affix_category_rules', 'item_definition_affix_families', 'character_item_affixes'] as $table) {
            assertDropSqlContains($sql, 'create table ' . $table, $table . '.');
        }
        assertDropSqlContains($sql, "check (position in ('prefix', 'suffix', 'special'))", 'Future special position structure.');
        assertDropSqlContains($sql, 'primary key (affix_key, tier)', 'Tier identity.');
        assertDropSqlContains($sql, 'foreign key (affix_key, tier)', 'Rolled tier FK.');
        assertDropSqlContains($sql, 'unique key uq_character_item_affixes_position (item_id, position)', 'One affix per position.');
        assertDropSqlContains($sql, 'unique key uq_character_item_affixes_family (item_id, family_key)', 'Family exclusion.');
    },

    'Migration 006 stores one durable hidden drop outcome per reward slot' => function (): void {
        $sql = itemDropSql('006_item_generation_and_drops.sql');
        assertDropSqlContains($sql, 'create table combat_item_drops', 'Drop table.');
        assertDropSqlContains($sql, 'unique key uq_combat_item_drops_slot (encounter_id, reward_slot)', 'Slot race guard.');
        assertDropSqlContains($sql, 'unique key uq_combat_item_drops_item (item_id)', 'Item linkage.');
        assertDropSqlContains($sql, "check (outcome in ('none', 'unclaimed', 'claimed'))", 'Outcome allowlist.');
        assertDropSqlContains($sql, 'base_chance_bp smallint unsigned not null', 'Hidden base chance.');
        assertDropSqlContains($sql, 'effective_chance_bp smallint unsigned not null', 'Hidden effective chance.');
        assertDropSqlContains($sql, 'chance_roll_bp smallint unsigned not null', 'Hidden durable chance roll.');
        assertDropSqlContains($sql, 'claimed_character_id int unsigned null', 'Claim audit owner.');
        assertDropSqlContains($sql, 'check (effective_chance_bp <= 10000)', 'Basis-point bound.');
        assertDropSqlContains($sql, 'loot_source_level int unsigned null', 'Legacy-null source level.');
        assertDropSqlContains($sql, 'item_drops_generated_at datetime(6) null', 'Generation marker.');
    },

    'Migration 006 does not backfill encounters or item ownership' => function (): void {
        $sql = itemDropSql('006_item_generation_and_drops.sql');
        if (preg_match('/update\s+combat_encounters\s+set\s+(loot_source_level|item_drops_generated_at)/', $sql) === 1) {
            throw new RuntimeException('Legacy encounters must remain NULL and receive no retroactive drops.');
        }
        if (preg_match('/insert\s+into\s+(character_items|combat_item_drops)/', $sql) === 1) {
            throw new RuntimeException('Migration 006 must not manufacture item instances or outcomes.');
        }
        if (substr_count($sql, "values ('006_item_generation_and_drops')") !== 1) {
            throw new RuntimeException('Migration identity must be recorded once.');
        }
    },

    'Migration 006 verification SQL is read only' => function (): void {
        $sql = itemDropSql('006_item_generation_and_drops_verify.sql');
        assertDropSqlContains($sql, "migration_id = '006_item_generation_and_drops'", 'Migration record.');
        assertDropSqlContains($sql, 'combat_item_drops', 'Drop inspection.');
        assertDropSqlContains($sql, 'character_item_affixes', 'Affix inspection.');
        if (preg_match('/\b(insert|update|delete|alter|create|drop|truncate|call)\b/', $sql) === 1) {
            throw new RuntimeException('Migration 006 verification SQL must be read only.');
        }
    },
];
