<?php
declare(strict_types=1);

function combatMigration004NormalizedSql(string $path, string $missingMessage): string
{
    if (!is_file($path)) {
        throw new RuntimeException($missingMessage);
    }

    $sql = file_get_contents($path);
    if ($sql === false) {
        throw new RuntimeException($missingMessage);
    }

    return strtolower(preg_replace('/\s+/', ' ', $sql) ?? $sql);
}

function combatMigration004Sql(): string
{
    return combatMigration004NormalizedSql(
        __DIR__ . '/../database/migrations/004_combat_enemy_ai_initialization.sql',
        'Migration 004 combat enemy AI initialization must exist.',
    );
}

function combatMigration004VerificationSql(): string
{
    return combatMigration004NormalizedSql(
        __DIR__ . '/../database/migrations/004_combat_enemy_ai_initialization_verify.sql',
        'Migration 004 read-only verification must exist.',
    );
}

function assertCombat004SqlContains(string $sql, string $fragment, string $message): void
{
    if (!str_contains($sql, strtolower($fragment))) {
        throw new RuntimeException($message . ' Missing SQL fragment: ' . $fragment);
    }
}

function assertCombat004SqlMatches(string $sql, string $pattern, string $message): void
{
    if (preg_match($pattern, $sql) !== 1) {
        throw new RuntimeException($message);
    }
}

return [
    'Migration 004 adds only the nullable enemy AI initialization marker with read-only verification' => function (): void {
        $migration = combatMigration004Sql();
        $verification = combatMigration004VerificationSql();

        assertCombat004SqlContains(
            $migration,
            "migration_id = '003_combat_foundation'",
            'Migration 004 must require Migration 003.',
        );
        assertCombat004SqlContains(
            $migration,
            "migration_id = '004_combat_enemy_ai_initialization'",
            'Migration 004 must reject an existing migration record.',
        );
        assertCombat004SqlContains(
            $migration,
            "table_name = 'combat_encounters'",
            'Migration 004 must preflight the encounter table.',
        );
        assertCombat004SqlContains(
            $migration,
            "engine = 'innodb'",
            'Migration 004 must require an InnoDB encounter table.',
        );
        assertCombat004SqlContains(
            $migration,
            "column_name = 'timeline_elapsed_ms'",
            'Migration 004 must preflight the logical timeline column.',
        );
        assertCombat004SqlContains(
            $migration,
            "data_type = 'bigint'",
            'Migration 004 must reject another timeline integer family.',
        );
        assertCombat004SqlMatches(
            $migration,
            "/column_type\s+regexp\s+'[^']*bigint[^']*unsigned[^']*'/",
            'Migration 004 must require an unsigned BIGINT timeline.',
        );
        assertCombat004SqlContains(
            $migration,
            "is_nullable = 'no'",
            'Migration 004 must require a non-null logical timeline.',
        );
        assertCombat004SqlContains(
            $migration,
            "column_name = 'enemy_ai_initialized_timeline_ms'",
            'Migration 004 must reject a partially installed marker.',
        );

        assertCombat004SqlMatches(
            $migration,
            '/alter\s+table\s+combat_encounters\s+(.*?)\s*;/s',
            'Migration 004 must alter combat_encounters exactly once.',
        );
        preg_match(
            '/alter\s+table\s+combat_encounters\s+(.*?)\s*;/s',
            $migration,
            $alterMatches,
        );
        $alter = $alterMatches[1] ?? '';

        assertCombat004SqlContains(
            $alter,
            'add column enemy_ai_initialized_timeline_ms bigint unsigned null',
            'Migration 004 marker type.',
        );
        assertCombat004SqlContains(
            $alter,
            'constraint chk_combat_encounters_enemy_ai_initialized',
            'Migration 004 marker CHECK.',
        );
        assertCombat004SqlContains(
            $alter,
            'enemy_ai_initialized_timeline_ms is null or enemy_ai_initialized_timeline_ms <= timeline_elapsed_ms',
            'Migration 004 marker must not exceed the logical timeline.',
        );
        if (substr_count($alter, 'add column') !== 1) {
            throw new RuntimeException('Migration 004 may add exactly one column.');
        }
        if (preg_match('/\b(default|modify|change|drop|rename)\b/', $alter) === 1) {
            throw new RuntimeException('Migration 004 must not default, replace, or remove encounter schema.');
        }
        foreach ([
            'current_hp',
            'current_mana',
            'enemy_current_hp',
            'player_actions_remaining',
            'enemy_actions_remaining',
            'turn_number',
            'status',
        ] as $unrelatedColumn) {
            if (str_contains($alter, $unrelatedColumn)) {
                throw new RuntimeException('Migration 004 must not alter ' . $unrelatedColumn . '.');
            }
        }
        if (preg_match('/\bupdate\s+combat_encounters\b/', $migration) === 1) {
            throw new RuntimeException('Migration 004 must leave existing encounter markers NULL.');
        }
        if (substr_count($migration, "values ('004_combat_enemy_ai_initialization')") !== 1) {
            throw new RuntimeException('Migration 004 must record its identity exactly once.');
        }

        assertCombat004SqlContains(
            $verification,
            "migration_id = '004_combat_enemy_ai_initialization'",
            'Verification migration record query.',
        );
        assertCombat004SqlContains(
            $verification,
            "column_name = 'enemy_ai_initialized_timeline_ms'",
            'Verification marker metadata query.',
        );
        foreach (['column_type', 'is_nullable', 'column_default', 'ordinal_position'] as $metadataField) {
            assertCombat004SqlContains(
                $verification,
                $metadataField,
                'Verification marker metadata field.',
            );
        }
        assertCombat004SqlContains(
            $verification,
            "constraint_name = 'chk_combat_encounters_enemy_ai_initialized'",
            'Verification CHECK query.',
        );
        foreach ([
            'count(*) as encounter_count',
            'marker_null_count',
            'marker_zero_count',
            'marker_positive_count',
        ] as $countProjection) {
            assertCombat004SqlContains(
                $verification,
                $countProjection,
                'Verification encounter marker count.',
            );
        }
        if (preg_match('/\b(insert|update|delete|alter|create|drop|truncate|call)\b/', $verification) === 1) {
            throw new RuntimeException('Migration 004 verification SQL must be read only.');
        }
    },
];
