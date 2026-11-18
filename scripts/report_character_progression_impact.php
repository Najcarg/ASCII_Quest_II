<?php
declare(strict_types=1);

// Offline report only. Input JSON exported by separately authorized read-only SQL.
// No DB/bootstrap/credentials/network access, no mutation mode, no file writes.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require_once __DIR__ . '/../ascii-quest/lib/CharacterProgression.php';

function characterProgressionImpact(array $rows): array
{
    $report = [];
    foreach ($rows as $row) {
        if (!is_array($row) || !is_string($row['character_name'] ?? null)) {
            throw new InvalidArgumentException('Invalid Champion report row.');
        }
        $id = CharacterProgression::nonNegativeInteger($row['id'] ?? null);
        $current = CharacterProgression::nonNegativeInteger($row['level'] ?? null);
        if ($id < 1 || $current < 1) { throw new InvalidArgumentException('Invalid Champion report identity or level.'); }
        $xp = CharacterProgression::nonNegativeInteger($row['experience'] ?? null);
        $projected = CharacterProgression::project($row);
        $expected = CharacterProgression::levelForTotalXp($xp);
        $delta = $projected['level'] - $current;
        $report[] = [
            'id' => $id,
            'character_name' => $row['character_name'],
            'life_state' => $row['life_state'],
            'current_level' => $current,
            'total_xp' => $xp,
            'xp_expected_level' => $expected,
            'proposed_level' => $projected['level'],
            'level_delta' => $delta,
            'policy' => $row['life_state'] === 'dead' ? 'dead_history_unchanged' : ($delta === 0 ? 'no_change' : 'reconcile_alive_cache'),
            'large_change' => abs($delta) >= 5,
        ];
    }
    return $report;
}

if (realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === __FILE__) {
    try {
        $rows = json_decode((string) file_get_contents('php://stdin'), true, 512, JSON_THROW_ON_ERROR);
        if (!is_array($rows) || !array_is_list($rows)) { throw new InvalidArgumentException('Expected a JSON row list.'); }
        echo json_encode(characterProgressionImpact($rows), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR) . "\n";
    } catch (Throwable) {
        fwrite(STDERR, "Unable to calculate impact: invalid or incomplete exported Champion rows.\n");
        exit(1);
    }
}
