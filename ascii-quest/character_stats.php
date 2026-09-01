<?php
declare(strict_types=1);

session_start();

if (!isset($_SESSION['user_id'])) {
    header('Location: login.php');
    exit();
}

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/lib/CharacterStats.php';

function e(mixed $value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

function backToCharacterSelect(string $message): never
{
    $_SESSION['flash_message'] = $message;
    $_SESSION['flash_type'] = 'error';
    header('Location: character_select.php');
    exit();
}

$rawCharacterId = $_GET['character_id'] ?? null;
$characterId = is_string($rawCharacterId)
    ? filter_var($rawCharacterId, FILTER_VALIDATE_INT, [
        'options' => ['min_range' => 1],
    ])
    : false;

if ($characterId === false) {
    backToCharacterSelect('Invalid Champion selection.');
}

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

$pdo = getDb();
$stmt = $pdo->prepare('
    SELECT
        c.id,
        c.user_id,
        c.character_name,
        c.level,
        c.stat_points,
        c.strength,
        c.dexterity,
        c.vitality,
        c.energy,
        c.fate,
        c.current_hp,
        c.current_mana,
        cc.class_name
    FROM characters c
    INNER JOIN character_classes cc
        ON cc.id = c.class_id
    WHERE c.id = :character_id
      AND c.user_id = :user_id
    LIMIT 1
');

$stmt->execute([
    'character_id' => $characterId,
    'user_id' => (int) $_SESSION['user_id'],
]);

$character = $stmt->fetch();
if (!$character) {
    backToCharacterSelect('Champion not found.');
}

try {
    $stats = CharacterStats::calculate($character);
} catch (InvalidArgumentException $e) {
    error_log(
        'CharacterStats error for character ' .
        (int) $character['id'] . ': ' . $e->getMessage(),
    );
    backToCharacterSelect('Unable to load Champion statistics.');
}

$flashMessage = $_SESSION['flash_message'] ?? '';
$flashType = $_SESSION['flash_type'] ?? '';
unset($_SESSION['flash_message'], $_SESSION['flash_type']);

$mainStatLabels = [
    'strength' => 'Strength',
    'dexterity' => 'Dexterity',
    'vitality' => 'Vitality',
    'energy' => 'Energy',
    'fate' => 'Fate',
];

$hasStatPoints = (int) $character['stat_points'] > 0;
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>ASCII Quest - Allocate Champion Stats</title>
    <link rel="stylesheet" href="css/style.css">
</head>
<body>
<main class="page">
    <section class="panel wide-panel stat-allocation-panel">
        <div class="logo">
            <h1>ASCII Quest</h1>
            <p>Strengthen your Champion.</p>
        </div>

        <h2><?= e($character['character_name']) ?></h2>
        <p class="character-class">
            <?= e($character['class_name']) ?> · Level <?= e($character['level']) ?>
        </p>

        <?php if ($flashMessage !== ''): ?>
            <div class="message <?= e($flashType) ?>">
                <?= e($flashMessage) ?>
            </div>
        <?php endif; ?>

        <div class="stat-points-summary">
            Available Stat Points
            <strong><?= e($character['stat_points']) ?></strong>
        </div>

        <section class="stat-allocation-list" aria-label="Main stats">
            <?php foreach ($mainStatLabels as $statKey => $statLabel): ?>
                <div class="stat-allocation-row">
                    <span><?= e($statLabel) ?></span>
                    <strong><?= e($stats['main'][$statKey]) ?></strong>

                    <form class="stat-plus-form" method="post" action="allocate_stat.php">
                        <input
                            type="hidden"
                            name="csrf_token"
                            value="<?= e($_SESSION['csrf_token']) ?>"
                        >
                        <input
                            type="hidden"
                            name="character_id"
                            value="<?= e($character['id']) ?>"
                        >
                        <input type="hidden" name="stat" value="<?= e($statKey) ?>">
                        <button
                            class="stat-plus-button"
                            type="submit"
                            aria-label="Add one point to <?= e($statLabel) ?>"
                            <?= $hasStatPoints ? '' : 'disabled' ?>
                        >+</button>
                    </form>
                </div>
            <?php endforeach; ?>
        </section>

        <h3 class="calculated-stats-heading">Calculated Values</h3>
        <div class="character-stats calculated-stat-grid">
            <div>
                <span>Life</span>
                <strong><?= e($character['current_hp']) ?>/<?= e($stats['resources']['max_life']) ?></strong>
            </div>
            <div>
                <span>Mana</span>
                <strong><?= e($character['current_mana']) ?>/<?= e($stats['resources']['max_mana']) ?></strong>
            </div>
            <div>
                <span>Melee Damage</span>
                <strong><?= e($stats['combat']['melee_damage']) ?></strong>
            </div>
            <div>
                <span>Toughness</span>
                <strong><?= e($stats['combat']['toughness']) ?></strong>
            </div>
            <div>
                <span>Spell Power</span>
                <strong><?= e($stats['combat']['spell_power']) ?></strong>
            </div>
            <div>
                <span>Critical Chance</span>
                <strong><?= e($stats['combat']['critical_chance']) ?>%</strong>
            </div>
            <div>
                <span>Loot Chance</span>
                <strong><?= e($stats['fortune']['loot_chance']) ?>%</strong>
            </div>
            <div>
                <span>Gold Find</span>
                <strong><?= e($stats['fortune']['gold_find']) ?>%</strong>
            </div>
        </div>

        <a class="menu-button secondary" href="character_select.php">
            Back to Character Selection
        </a>
    </section>
</main>
</body>
</html>
