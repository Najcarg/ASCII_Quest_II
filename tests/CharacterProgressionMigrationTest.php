<?php
declare(strict_types=1);

require_once __DIR__ . '/../scripts/report_character_progression_impact.php';

return [
    'Task34A migration012 preflights 011 absence types constraints and partial install' => function (): void {
        $sql = file_get_contents(__DIR__ . '/../database/migrations/012_character_level_progression.sql');
        foreach (["migration_id = '011_test_item_provenance'", "migration_id = '012_character_level_progression'", 'v_count <> 0', "COLUMN_NAME = 'reward_progression'", 'check_constraint_checks', 'foreign_key_checks', 'GET_LOCK', 'RELEASE_LOCK', 'RESIGNAL', "ENGINE = 'InnoDB'", "DATA_TYPE IN ('int', 'bigint')", 'experience < 0', 'life_state NOT IN', 'JSON_VALID', 'START TRANSACTION', 'ROLLBACK', 'COMMIT'] as $fragment) {
            assertSameValue(true, str_contains($sql, $fragment), 'Preflight/guard: ' . $fragment);
        }
        assertSameValue(1, substr_count($sql, 'ALTER TABLE'), 'One DDL operation.');
        assertSameValue(false, str_contains($sql, 'CREATE TABLE'), 'No redundant XP/skill table.');
        assertSameValue(true, strpos($sql, 'ALTER TABLE') < strpos($sql, 'START TRANSACTION'), 'DDL implicit-commit boundary explicit.');
        assertSameValue(true, strpos($sql, 'START TRANSACTION') < strpos($sql, 'UPDATE characters'), 'Data reconciliation is transactional.');
    },
    'Task34A migration and read-only verifier match every configured threshold' => function (): void {
        $table = require __DIR__ . '/../ascii-quest/config/character_progression.php';
        foreach (['012_character_level_progression.sql', '012_character_level_progression_verify.sql'] as $name) {
            $sql = file_get_contents(__DIR__ . '/../database/migrations/' . $name);
            preg_match_all('/WHEN experience >= (\d+) THEN (\d+)/', $sql, $matches, PREG_SET_ORDER);
            assertSameValue(49, count($matches), 'Every post-Level1 threshold.');
            $last = PHP_INT_MAX;
            foreach ($matches as $match) {
                $xp = (int) $match[1]; $level = (int) $match[2];
                assertSameValue($table[$level], $xp, 'PHP/SQL parity.');
                assertSameValue(true, $xp < $last, 'Descending CASE order.');
                $last = $xp;
            }
            assertSameValue(true, str_contains($sql, "life_state = 'alive'"), 'Dead exemption.');
        }
        $verify = file_get_contents(__DIR__ . '/../database/migrations/012_character_level_progression_verify.sql');
        $verify = preg_replace('~/\*.*?\*/|--[^\n]*~s', '', $verify);
        assertSameValue(0, preg_match('/\b(UPDATE|INSERT|DELETE|ALTER|CREATE|DROP|CALL|TRUNCATE|GRANT|SET)\b/i', $verify), 'Verifier is read only.');
        assertSameValue(0, preg_match('/\bREPLACE\b(?!\s*\()/i', $verify), 'No REPLACE statement.');
        assertSameValue(true, str_contains($verify, 'CHECK_CONSTRAINTS'), 'CHECK shape verified.');
        assertSameValue(true, str_contains($verify, 'IS_NULLABLE'), 'Column shape verified.');
    },
    'Task34A reconciliation changes only alive level caches and preserves history XP stats resources' => function (): void {
        $sql = file_get_contents(__DIR__ . '/../database/migrations/012_character_level_progression.sql');
        preg_match('/UPDATE characters\s+SET(.*?)WHERE life_state = \'alive\'/s', $sql, $match);
        assertSameValue(true, isset($match[1]), 'Only alive update.');
        assertSameValue(1, preg_match_all('/\b\w+\s*=(?!=)/', $match[1]), 'Exactly one assignment.');
        assertSameValue(true, str_starts_with(trim($match[1]), 'level ='), 'Only level cache changes.');
        assertSameValue(1, substr_count($sql, 'UPDATE characters'), 'No backfill/history update.');
        assertSameValue(false, str_contains($sql, 'UPDATE combat_'), 'Old rewards/actions untouched.');
        assertSameValue(true, str_contains($sql, 'reward_progression LONGTEXT'), 'Nullable new receipt field.');
    },
    'Task34A offline production-impact calculator reports all no-change changed dead and large rows' => function (): void {
        $rows = [
            ['id' => 1, 'character_name' => 'Synthetic no-change', 'level' => 1, 'experience' => 0, 'life_state' => 'alive'],
            ['id' => 2, 'character_name' => 'Synthetic increase', 'level' => 1, 'experience' => 10000, 'life_state' => 'alive'],
            ['id' => 3, 'character_name' => 'Synthetic stale cache', 'level' => 17, 'experience' => 120, 'life_state' => 'alive'],
            ['id' => 4, 'character_name' => 'Synthetic dead', 'level' => 1, 'experience' => 240100, 'life_state' => 'dead'],
        ];
        $report = characterProgressionImpact($rows);
        assertSameValue(4, count($report), 'No omitted unchanged/dead rows.');
        assertSameValue([1, 11, 2, 1], array_column($report, 'proposed_level'), 'Deterministic reconciliation/dead policy.');
        assertSameValue([0, 10, -15, 0], array_column($report, 'level_delta'), 'Signed deltas.');
        assertSameValue([false, true, true, false], array_column($report, 'large_change'), 'Large changes explicit.');
        assertSameValue('dead_history_unchanged', $report[3]['policy'], 'Dead exception.');
        assertSameValue([0, 10000, 120, 240100], array_column($report, 'total_xp'), 'XP unchanged.');
        $source = file_get_contents(__DIR__ . '/../scripts/report_character_progression_impact.php');
        assertSameValue(false, str_contains($source, 'getDb('), 'Offline helper has no database access.');
    },
    'Task34A immutable reward receipt rejects malformed shape or XP-level accounting' => function (): void {
        assertSameValue(null, CharacterProgression::rewardSnapshot(null), 'Legacy NULL.');
        $valid = CharacterProgression::victoryReward(['experience' => 80, 'life_state' => 'alive'], 40);
        assertSameValue($valid, CharacterProgression::rewardSnapshot(json_encode($valid)), 'Roundtrip immutable receipt.');
        foreach ([['new_level', 50], ['total_xp_after', 9999], ['old_level', 4]] as [$key, $value]) {
            $bad = $valid; $bad[$key] = $value;
            task34aAssertRejected(fn () => CharacterProgression::rewardSnapshot(json_encode($bad)), UnexpectedValueException::class);
        }
        task34aAssertRejected(fn () => CharacterProgression::rewardSnapshot('{"level":50}'), UnexpectedValueException::class);
        task34aAssertRejected(fn () => CharacterProgression::rewardSnapshot('{invalid'), JsonException::class);
    },
];
