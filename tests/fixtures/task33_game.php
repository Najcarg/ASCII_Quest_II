<?php
declare(strict_types=1);

// CLI-only, database-free rendering of the real game template for local UI checks.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
$root = realpath($argv[1] ?? __DIR__ . '/../..');
require_once $root . '/ascii-quest/lib/CharacterStats.php';
function e(mixed $value): string { return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8'); }
function formatDetailValue(mixed $value, string $format): string {
    return $format === 'percentage' ? $value . '%' : (string) $value;
}
$character = [
    'id' => 1, 'character_name' => 'Local UI Champion', 'class_name' => 'Warrior',
    'level' => 1, 'glyph' => 'Ω', 'gold' => 125, 'experience' => 0, 'stat_points' => 0,
    'strength' => 10, 'dexterity' => 5, 'vitality' => 10, 'energy' => 5, 'fate' => 5,
];
$skillState = $task34bState ?? null;
$passiveState = $task35State ?? null;
if ($skillState !== null) {
    $character['id'] = $skillState['champion']['id'];
    $character['level'] = $skillState['champion']['level'];
    $character['class_name'] = ucfirst($skillState['champion']['class_key']);
}
$characterStats = CharacterStats::calculate($character);
$_SESSION = ['csrf_token' => 'local-fixture-not-a-session'];
$isCombatMode = in_array('--combat', $argv, true);
$isDefeated = false;
$combatSkillOneAvailable = false;
$combatSkillOneStatus = 'Unavailable';
$isVictoryLoot = false;
$combatState = $combatSkillOne = $currentWarp = $encounterEnemy = null;
if ($isCombatMode) {
    $combatState = [
        'status' => 'active',
        'turn' => ['number' => 1, 'player_actions_remaining' => 1],
        'champion' => ['current_hp' => 100],
        'enemy' => ['glyph' => 'B', 'name' => 'Local UI Enemy', 'current_hp' => 90, 'maximum_hp' => 120],
        'player_attack' => ['name' => 'Weapon Attack'],
        'potion' => ['key' => 'Local potion', 'charges_remaining' => 1, 'charge_allowance' => 1],
    ];
}
if (isset($task34bCombatState)) {
    $combatState = $task34bCombatState;
    $combatSlotStates = array_column($combatState['player_skills'], null, 'slot');
    $combatSkillOne = $combatSlotStates['skill_1'];
    $combatSkillOneAvailable = $combatSkillOne['available'];
    $combatSkillOneStatus = $combatSkillOneAvailable ? 'Ready' : 'Unavailable';
}
$warpDestinations = [];
$currentHp = $maximumLife = $characterStats['resources']['max_life'];
$currentMana = $maximumMana = $characterStats['resources']['max_mana'];
$hpPercentage = $manaPercentage = 100;
$viewportWidth = 21; $viewportHeight = 15;
$mapWidth = 25; $mapHeight = 19; $playerX = 12; $playerY = 9;
$mapName = 'Local UI Cave (synthetic, no database)';
$mapRows = array_fill(0, 19, '#' . str_repeat('.', 23) . '#');
$mapRows[0] = $mapRows[18] = str_repeat('#', 25);
$tileTypes = [
    '#' => ['display_glyph' => '▓', 'css_class' => 'tile-wall', 'name' => 'Wall'],
    '.' => ['display_glyph' => '·', 'css_class' => 'tile-floor', 'name' => 'Floor'],
];
$items = [];
foreach (['mace' => '𓉒', 'sword' => '/', 'dagger' => '/', 'wand' => '/', 'shield' => 'O', 'gloves' => '.', 'boots' => '.', 'belt' => '.', 'ring' => '.', 'amulet' => '.', 'charm' => '.', 'axe' => '/'] as $type => $glyph) {
    $slot = in_array($type, ['mace', 'sword', 'dagger', 'wand', 'axe'], true) ? 'weapon' : ($type === 'shield' ? 'off-hand' : $type);
    $items[] = [
        'id' => count($items) + 1, 'display_name' => 'Fine ' . ucfirst($type) . ' of Accuracy',
        'rarity' => 'magic', 'item_level' => 1, 'base_type' => $type, 'equipment_slot' => $slot,
        'glyph' => $type === 'mace' ? 'T' : $glyph,
        'base_stats' => ['damage_min' => 5, 'damage_max' => 7, 'damage_type' => 'physical'],
        'affixes' => [['display_fragment' => 'of Accuracy']], 'stat_lines' => ['+5 Accuracy'],
        'equipped' => false, 'equipped_slot' => null,
    ];
}
$inventoryState = [
    'items' => $items, 'equipment' => [], 'equipment_locked' => false,
    'pagination' => ['page' => 1, 'total_pages' => 2, 'has_previous' => false, 'has_next' => true],
];
$styleVersion = $explorationHudVersion = $gameControlsVersion = $combatHudVersion = $itemHudVersion = 1;
if (is_file($root . '/ascii-quest/lib/CharacterProgression.php')) {
    require_once $root . '/ascii-quest/lib/CharacterProgression.php';
    $character['experience'] = $task34bExperience ?? 0;
    $character['life_state'] = 'alive';
    $progressionState = CharacterProgression::project($character);
    $rewardProgression = null;
}
$source = file_get_contents($root . '/ascii-quest/game.php');
// Only evaluate the display definitions/template, never the controller or bootstrap.
$start = strpos($source, '$mainStatLabels =');
ob_start();
eval(substr($source, $start));
$html = ob_get_clean();
// Layout-only combat fixture: do not start polling or combat commands.
if ($isCombatMode) {
    $html = preg_replace('~<script src="js/combat_hud\.js[^" ]*"></script>~', '', $html);
}
$base = 'file://' . $root . '/ascii-quest/';
echo str_replace('<head>', '<head><base href="' . e($base) . '">', $html);
