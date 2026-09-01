<?php
declare(strict_types=1);

function combatMigrationSql(): string
{
    $path = __DIR__ . '/../database/migrations/003_combat_foundation.sql';
    if (!is_file($path)) {
        throw new RuntimeException('Migration 003_combat_foundation must exist.');
    }

    $sql = file_get_contents($path);
    if ($sql === false) {
        throw new RuntimeException('Migration 003_combat_foundation must be readable.');
    }

    return strtolower(preg_replace('/\s+/', ' ', $sql) ?? $sql);
}

function combatVerificationSql(): string
{
    $path = __DIR__ . '/../database/migrations/003_combat_foundation_verify.sql';
    if (!is_file($path)) {
        throw new RuntimeException('Migration 003 read-only verification must exist.');
    }

    $sql = file_get_contents($path);
    if ($sql === false) {
        throw new RuntimeException('Migration 003 verification must be readable.');
    }

    return strtolower(preg_replace('/\s+/', ' ', $sql) ?? $sql);
}

function assertCombatSqlContains(string $sql, string $fragment, string $message): void
{
    if (!str_contains($sql, strtolower($fragment))) {
        throw new RuntimeException($message . ' Missing SQL fragment: ' . $fragment);
    }
}

function assertCombatSqlMatches(string $sql, string $pattern, string $message): void
{
    if (preg_match($pattern, $sql) !== 1) {
        throw new RuntimeException($message);
    }
}

return [
    'Combat migration requires prior migrations and independently verifies unsigned character IDs' => function (): void {
        $sql = combatMigrationSql();

        assertCombatSqlContains($sql, "migration_id = '003_combat_foundation'", 'Migration identity.');
        assertCombatSqlContains($sql, "migration_id in ('001_character_stat_system', '002_character_warps')", 'Migration dependencies.');
        assertCombatSqlContains($sql, 'if v_count <> 2 then', 'Both migration dependencies must be present.');
        assertCombatSqlContains($sql, "table_name = 'characters'", 'Characters table preflight.');
        assertCombatSqlContains($sql, "column_name = 'id'", 'Characters ID preflight.');
        assertCombatSqlContains($sql, "data_type = 'int'", 'Characters ID must reject non-INT types.');
        assertCombatSqlMatches(
            $sql,
            "/column_type\s+regexp\s+'[^']*int[^']*unsigned[^']*'/",
            'Characters ID preflight must accept optional display width but require UNSIGNED.',
        );
    },

    'Combat migration creates matching InnoDB aggregate tables and lifecycle columns' => function (): void {
        $sql = combatMigrationSql();

        foreach (['combat_encounters', 'combat_actions', 'combat_events'] as $table) {
            assertCombatSqlContains($sql, 'create table ' . $table, $table . ' table.');
        }
        if (substr_count($sql, 'engine=innodb') < 3) {
            throw new RuntimeException('Every combat table must use InnoDB.');
        }

        assertCombatSqlContains($sql, "add column life_state varchar(16) character set ascii collate ascii_bin not null default 'alive'", 'Champion life state.');
        assertCombatSqlContains($sql, 'add column died_at datetime(6) null', 'Champion death timestamp.');
        assertCombatSqlContains($sql, "check (life_state in ('alive', 'dead'))", 'Champion life-state constraint.');
        assertCombatSqlContains($sql, 'character_id int unsigned not null', 'Encounter Champion key type.');
        assertCombatSqlContains($sql, 'encounter_id int unsigned not null', 'Action/event encounter key type.');
        assertCombatSqlContains($sql, 'parent_action_id int unsigned null', 'Action parent key type.');
        assertCombatSqlContains($sql, 'references characters (id)', 'Champion foreign key.');
        assertCombatSqlContains($sql, 'references combat_encounters (id)', 'Encounter foreign keys.');
        assertCombatSqlContains($sql, 'references combat_actions (id)', 'Action self foreign key.');
    },

    'Combat migration persists only logical gameplay positions and allowed wall timestamps' => function (): void {
        $sql = combatMigrationSql();

        foreach ([
            'timeline_elapsed_ms bigint unsigned',
            'last_synchronized_at datetime(6)',
            'turn_number int unsigned',
            'turn_started_timeline_ms bigint unsigned',
            'next_enemy_decision_timeline_ms bigint unsigned',
            'started_timeline_ms bigint unsigned',
            'resolves_timeline_ms bigint unsigned',
            'cooldown_ready_timeline_ms bigint unsigned',
            'completed_timeline_ms bigint unsigned',
            'block_expires_timeline_ms bigint unsigned',
            'block_attempted_timeline_ms bigint unsigned',
        ] as $field) {
            assertCombatSqlContains($sql, $field, 'Logical combat timeline field.');
        }

        foreach (['turn_ends_at', 'resolves_at', 'cooldown_ready_at', 'block_expires_at', 'next_enemy_decision_at'] as $staleField) {
            if (str_contains($sql, $staleField)) {
                throw new RuntimeException('Gameplay scheduling must not use absolute deadline field ' . $staleField . '.');
            }
        }
    },

    'Closed combat history does not permanently block ordinary Champion deletion' => function (): void {
        $sql = combatMigrationSql();

        assertCombatSqlMatches(
            $sql,
            '/foreign key\s*\(character_id\)\s*references characters\s*\(id\)\s*on delete cascade/',
            'The Champion foreign key must cascade combat history after runtime guards allow deletion.',
        );
    },

    'Combat migration enforces active action request and event uniqueness with state invariants' => function (): void {
        $sql = combatMigrationSql();

        assertCombatSqlContains($sql, 'unique key uq_combat_encounters_active (character_id, active_slot)', 'One non-closed encounter guard.');
        assertCombatSqlContains($sql, 'unique key uq_combat_actions_request (encounter_id, request_token)', 'Request replay guard.');
        assertCombatSqlContains($sql, 'unique key uq_combat_actions_actor_active (encounter_id, actor, active_slot)', 'One unresolved action per actor guard.');
        assertCombatSqlContains($sql, 'unique key uq_combat_actions_block_token (block_token)', 'Block token guard.');
        assertCombatSqlContains($sql, 'unique key uq_combat_events_sequence (encounter_id, sequence_number)', 'Battle Info sequence guard.');
        assertCombatSqlContains($sql, "check (status in ('active', 'victory_loot', 'defeated', 'closed'))", 'Encounter status constraint.');
        assertCombatSqlContains($sql, "check (actor in ('player', 'enemy', 'system'))", 'Action actor constraint.');
        assertCombatSqlContains($sql, "check (action_kind in ('attack', 'weapon', 'skill', 'block', 'potion'))", 'Action kind constraint.');
        assertCombatSqlContains($sql, "check (state in ('pending', 'resolved', 'cancelled', 'rejected'))", 'Action state constraint.');
        assertCombatSqlContains($sql, 'check (enemy_current_hp <= enemy_max_hp)', 'Enemy HP bound.');
        assertCombatSqlContains($sql, 'check (potion_charges_remaining <= potion_charge_allowance)', 'Potion charge bound.');
        assertCombatSqlContains($sql, 'check (block_prompt_x between 0.000 and 1.000)', 'Block X bound.');
        assertCombatSqlContains($sql, 'check (block_prompt_y between 0.000 and 1.000)', 'Block Y bound.');
    },

    'Combat migration is additive and its companion verification is read only' => function (): void {
        $migration = combatMigrationSql();
        $verification = combatVerificationSql();

        foreach ([
            '/\bdelete\s+from\s+characters\b/',
            '/\btruncate\b/',
            '/\bdrop\s+table\b/',
            '/\bupdate\s+characters\b/',
            '/\binsert\s+into\s+combat_encounters\b/',
        ] as $destructivePattern) {
            if (preg_match($destructivePattern, $migration) === 1) {
                throw new RuntimeException('Migration 003 must not reset Champions or create default encounters.');
            }
        }

        foreach (['current_hp', 'current_mana'] as $resourceColumn) {
            if (preg_match('/alter\s+table\s+characters[^;]*\b' . $resourceColumn . '\b/', $migration) === 1) {
                throw new RuntimeException('Migration 003 must not alter ' . $resourceColumn . '.');
            }
        }

        assertCombatSqlContains($verification, "migration_id = '003_combat_foundation'", 'Verification migration record query.');
        assertCombatSqlContains($verification, "table_name in ('combat_encounters', 'combat_actions', 'combat_events')", 'Verification table query.');
        if (preg_match('/\b(insert|update|delete|alter|create|drop|truncate|call)\b/', $verification) === 1) {
            throw new RuntimeException('Migration verification SQL must be read only.');
        }
    },
];
