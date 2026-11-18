<?php
declare(strict_types=1);

/** Test-only parser: fixtures consume the authoritative migration seed, never a second catalogue. */
function task34bCatalogueRows(): array
{
    $sql = file_get_contents(__DIR__ . '/../../database/migrations/013_character_skill_system.sql');
    preg_match_all("/INSERT INTO skill_definitions \\(([^)]+)\\) SELECT (.+) FROM character_classes WHERE class_key='([^']+)';/", $sql, $matches, PREG_SET_ORDER);
    $classes = ['warrior' => 1, 'mage' => 2, 'rogue' => 3, 'cleric' => 4];
    $definitions = [];
    foreach ($matches as $match) {
        $columns = explode(',', $match[1]);
        $values = str_getcsv($match[2], ',', "'", '\\');
        foreach ($values as &$value) {
            if ($value === 'NULL') { $value = null; }
            elseif (ctype_digit((string) $value)) { $value = (int) $value; }
        }
        unset($value);
        $row = array_combine($columns, $values);
        $row['class_key'] = $match[3];
        $row['class_id'] = $classes[$match[3]];
        $definitions[] = $row;
    }
    preg_match_all("/INSERT INTO skill_prerequisites \\(skill_key,prerequisite_key,class_id\\) SELECT '([^']+)','([^']+)',id FROM character_classes WHERE class_key='([^']+)';/", $sql, $matches, PREG_SET_ORDER);
    $prerequisites = array_map(static fn (array $m): array => ['skill_key' => $m[1], 'prerequisite_key' => $m[2], 'class_id' => $classes[$m[3]]], $matches);
    if (count($definitions) !== 16 || count($prerequisites) !== 12) { throw new RuntimeException('Fixture seed parsing failed.'); }
    return [$definitions, $prerequisites];
}
