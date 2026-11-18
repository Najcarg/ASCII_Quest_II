/* READ ONLY. Zero violation rows = verified. Migration012 NOT APPLIED.
   Historical immutability requires before/after backup comparison; not inferred
   from these current-state queries. Dead levels are deliberately not reconciled. */
USE ascii_quest;
SELECT 'missing prerequisite 011' AS violation
 WHERE (SELECT COUNT(*) FROM schema_migrations WHERE migration_id = '011_test_item_provenance') <> 1
UNION ALL
SELECT 'missing migration 012'
 WHERE (SELECT COUNT(*) FROM schema_migrations WHERE migration_id = '012_character_level_progression') <> 1
UNION ALL
SELECT 'snapshot column mismatch'
 WHERE (SELECT COUNT(*) FROM information_schema.COLUMNS
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'combat_encounters'
           AND COLUMN_NAME = 'reward_progression' AND DATA_TYPE = 'longtext'
           AND CHARACTER_SET_NAME = 'utf8mb4' AND COLLATION_NAME = 'utf8mb4_bin'
           AND IS_NULLABLE = 'YES' AND EXTRA = ''
           AND (COLUMN_DEFAULT IS NULL OR UPPER(COLUMN_DEFAULT) = 'NULL')) <> 1
UNION ALL
SELECT 'snapshot CHECK mismatch'
 WHERE (SELECT COUNT(*) FROM information_schema.CHECK_CONSTRAINTS
         WHERE CONSTRAINT_SCHEMA = DATABASE() AND TABLE_NAME = 'combat_encounters'
           AND CONSTRAINT_NAME = 'chk_combat_reward_progression_json'
           AND LOWER(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(CHECK_CLAUSE, '`', ''), ' ', ''), CHAR(9), ''), CHAR(10), ''), CHAR(13), '')) IN
             ('reward_progressionisnullorjson_valid(reward_progression)', '(reward_progressionisnullorjson_valid(reward_progression))')) <> 1;

SELECT id AS character_id, 'alive level cache mismatch' AS violation FROM characters
 WHERE life_state = 'alive' AND level <> CASE
            WHEN experience >= 240100 THEN 50
            WHEN experience >= 230400 THEN 49
            WHEN experience >= 220900 THEN 48
            WHEN experience >= 211600 THEN 47
            WHEN experience >= 202500 THEN 46
            WHEN experience >= 193600 THEN 45
            WHEN experience >= 184900 THEN 44
            WHEN experience >= 176400 THEN 43
            WHEN experience >= 168100 THEN 42
            WHEN experience >= 160000 THEN 41
            WHEN experience >= 152100 THEN 40
            WHEN experience >= 144400 THEN 39
            WHEN experience >= 136900 THEN 38
            WHEN experience >= 129600 THEN 37
            WHEN experience >= 122500 THEN 36
            WHEN experience >= 115600 THEN 35
            WHEN experience >= 108900 THEN 34
            WHEN experience >= 102400 THEN 33
            WHEN experience >= 96100 THEN 32
            WHEN experience >= 90000 THEN 31
            WHEN experience >= 84100 THEN 30
            WHEN experience >= 78400 THEN 29
            WHEN experience >= 72900 THEN 28
            WHEN experience >= 67600 THEN 27
            WHEN experience >= 62500 THEN 26
            WHEN experience >= 57600 THEN 25
            WHEN experience >= 52900 THEN 24
            WHEN experience >= 48400 THEN 23
            WHEN experience >= 44100 THEN 22
            WHEN experience >= 40000 THEN 21
            WHEN experience >= 36100 THEN 20
            WHEN experience >= 32400 THEN 19
            WHEN experience >= 28900 THEN 18
            WHEN experience >= 25600 THEN 17
            WHEN experience >= 22500 THEN 16
            WHEN experience >= 19600 THEN 15
            WHEN experience >= 16900 THEN 14
            WHEN experience >= 14400 THEN 13
            WHEN experience >= 12100 THEN 12
            WHEN experience >= 10000 THEN 11
            WHEN experience >= 8100 THEN 10
            WHEN experience >= 6400 THEN 9
            WHEN experience >= 4900 THEN 8
            WHEN experience >= 3600 THEN 7
            WHEN experience >= 2500 THEN 6
            WHEN experience >= 1600 THEN 5
            WHEN experience >= 900 THEN 4
            WHEN experience >= 400 THEN 3
            WHEN experience >= 100 THEN 2
            ELSE 1
        END;
SELECT id AS character_id, 'invalid cumulative XP' AS violation FROM characters
 WHERE experience IS NULL OR experience < 0 OR experience > 9223372036854775807;
SELECT id AS encounter_id, 'invalid reward progression snapshot' AS violation
  FROM combat_encounters
 WHERE reward_progression IS NOT NULL AND
       (JSON_VALID(reward_progression) <> 1 OR rewards_issued_at IS NULL);

-- Evaluate JSON functions only on valid input. Exact receipt shape/accounting.
SELECT id AS encounter_id, 'reward progression accounting mismatch' AS violation
  FROM combat_encounters
 WHERE reward_progression IS NOT NULL AND CASE
    WHEN JSON_VALID(reward_progression) = 1 THEN
        JSON_TYPE(reward_progression) <> 'OBJECT'
        OR JSON_LENGTH(reward_progression) <> 5
        OR JSON_CONTAINS_PATH(reward_progression, 'all', '$.xp_gained', '$.total_xp_before', '$.total_xp_after', '$.old_level', '$.new_level') <> 1
        OR JSON_TYPE(JSON_EXTRACT(reward_progression, '$.xp_gained')) <> 'INTEGER'
        OR CAST(JSON_UNQUOTE(JSON_EXTRACT(reward_progression, '$.xp_gained')) AS DECIMAL(20,0)) < 0
        OR JSON_TYPE(JSON_EXTRACT(reward_progression, '$.total_xp_before')) <> 'INTEGER'
        OR CAST(JSON_UNQUOTE(JSON_EXTRACT(reward_progression, '$.total_xp_before')) AS DECIMAL(20,0)) < 0
        OR JSON_TYPE(JSON_EXTRACT(reward_progression, '$.total_xp_after')) <> 'INTEGER'
        OR CAST(JSON_UNQUOTE(JSON_EXTRACT(reward_progression, '$.total_xp_after')) AS DECIMAL(20,0)) < 0
        OR JSON_TYPE(JSON_EXTRACT(reward_progression, '$.old_level')) <> 'INTEGER'
        OR CAST(JSON_UNQUOTE(JSON_EXTRACT(reward_progression, '$.old_level')) AS DECIMAL(20,0)) < 0
        OR JSON_TYPE(JSON_EXTRACT(reward_progression, '$.new_level')) <> 'INTEGER'
        OR CAST(JSON_UNQUOTE(JSON_EXTRACT(reward_progression, '$.new_level')) AS DECIMAL(20,0)) < 0
        OR CAST(JSON_UNQUOTE(JSON_EXTRACT(reward_progression, '$.xp_gained')) AS DECIMAL(20,0)) <> reward_experience
        OR CAST(JSON_UNQUOTE(JSON_EXTRACT(reward_progression, '$.total_xp_after')) AS DECIMAL(20,0)) > 9223372036854775807
        OR CAST(JSON_UNQUOTE(JSON_EXTRACT(reward_progression, '$.total_xp_after')) AS DECIMAL(20,0)) <> CAST(JSON_UNQUOTE(JSON_EXTRACT(reward_progression, '$.total_xp_before')) AS DECIMAL(20,0)) + CAST(JSON_UNQUOTE(JSON_EXTRACT(reward_progression, '$.xp_gained')) AS DECIMAL(20,0))
        OR CAST(JSON_UNQUOTE(JSON_EXTRACT(reward_progression, '$.old_level')) AS DECIMAL(20,0)) NOT BETWEEN 1 AND 50
        OR CAST(JSON_UNQUOTE(JSON_EXTRACT(reward_progression, '$.new_level')) AS DECIMAL(20,0)) NOT BETWEEN 1 AND 50
        OR CAST(JSON_UNQUOTE(JSON_EXTRACT(reward_progression, '$.new_level')) AS DECIMAL(20,0)) < CAST(JSON_UNQUOTE(JSON_EXTRACT(reward_progression, '$.old_level')) AS DECIMAL(20,0))
        OR CAST(JSON_UNQUOTE(JSON_EXTRACT(reward_progression, '$.total_xp_before')) AS DECIMAL(20,0)) < 100 * (CAST(JSON_UNQUOTE(JSON_EXTRACT(reward_progression, '$.old_level')) AS DECIMAL(20,0)) - 1) * (CAST(JSON_UNQUOTE(JSON_EXTRACT(reward_progression, '$.old_level')) AS DECIMAL(20,0)) - 1)
        OR (CAST(JSON_UNQUOTE(JSON_EXTRACT(reward_progression, '$.old_level')) AS DECIMAL(20,0)) < 50 AND CAST(JSON_UNQUOTE(JSON_EXTRACT(reward_progression, '$.total_xp_before')) AS DECIMAL(20,0)) >= 100 * CAST(JSON_UNQUOTE(JSON_EXTRACT(reward_progression, '$.old_level')) AS DECIMAL(20,0)) * CAST(JSON_UNQUOTE(JSON_EXTRACT(reward_progression, '$.old_level')) AS DECIMAL(20,0)))
        OR CAST(JSON_UNQUOTE(JSON_EXTRACT(reward_progression, '$.total_xp_after')) AS DECIMAL(20,0)) < 100 * (CAST(JSON_UNQUOTE(JSON_EXTRACT(reward_progression, '$.new_level')) AS DECIMAL(20,0)) - 1) * (CAST(JSON_UNQUOTE(JSON_EXTRACT(reward_progression, '$.new_level')) AS DECIMAL(20,0)) - 1)
        OR (CAST(JSON_UNQUOTE(JSON_EXTRACT(reward_progression, '$.new_level')) AS DECIMAL(20,0)) < 50 AND CAST(JSON_UNQUOTE(JSON_EXTRACT(reward_progression, '$.total_xp_after')) AS DECIMAL(20,0)) >= 100 * CAST(JSON_UNQUOTE(JSON_EXTRACT(reward_progression, '$.new_level')) AS DECIMAL(20,0)) * CAST(JSON_UNQUOTE(JSON_EXTRACT(reward_progression, '$.new_level')) AS DECIMAL(20,0)))
    ELSE 1 END;
