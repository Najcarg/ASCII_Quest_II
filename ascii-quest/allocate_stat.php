<?php
declare(strict_types=1);

session_start();

if (!isset($_SESSION['user_id'])) {
    header('Location: login.php');
    exit();
}

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/lib/CharacterStatAllocator.php';

function redirectToStatPage(?int $characterId = null): never
{
    $location = 'character_select.php';
    if ($characterId !== null && $characterId > 0) {
        $location = 'character_stats.php?character_id=' . rawurlencode((string) $characterId);
    }

    header('Location: ' . $location);
    exit();
}

function setAllocationFlash(string $message, string $type): void
{
    $_SESSION['flash_message'] = $message;
    $_SESSION['flash_type'] = $type;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirectToStatPage();
}

$rawCharacterId = $_POST['character_id'] ?? null;
$characterId = is_string($rawCharacterId)
    ? filter_var($rawCharacterId, FILTER_VALIDATE_INT, [
        'options' => ['min_range' => 1],
    ])
    : false;

if ($characterId === false) {
    setAllocationFlash('Invalid allocation request.', 'error');
    redirectToStatPage();
}

$postedToken = $_POST['csrf_token'] ?? '';
$sessionToken = $_SESSION['csrf_token'] ?? '';
if (
    !is_string($postedToken) ||
    !is_string($sessionToken) ||
    $postedToken === '' ||
    $sessionToken === '' ||
    !hash_equals($sessionToken, $postedToken)
) {
    setAllocationFlash('Security check failed. Please try again.', 'error');
    redirectToStatPage((int) $characterId);
}

$stat = $_POST['stat'] ?? '';
if (!is_string($stat)) {
    setAllocationFlash('Invalid stat selection.', 'error');
    redirectToStatPage((int) $characterId);
}

$userId = (int) $_SESSION['user_id'];
$pdo = getDb();

try {
    $pdo->beginTransaction();

    $stmt = $pdo->prepare('
        SELECT
            id,
            user_id,
            stat_points,
            strength,
            dexterity,
            vitality,
            energy,
            fate,
            current_hp,
            current_mana
        FROM characters
        WHERE id = :character_id
          AND user_id = :user_id
        LIMIT 1
        FOR UPDATE
    ');

    $stmt->execute([
        'character_id' => (int) $characterId,
        'user_id' => $userId,
    ]);

    $character = $stmt->fetch();
    if (!$character) {
        throw new OutOfBoundsException('Champion not found.');
    }

    $allocated = CharacterStatAllocator::allocate($character, $userId, $stat);

    $updateStmt = $pdo->prepare('
        UPDATE characters
        SET
            stat_points = :stat_points,
            strength = :strength,
            dexterity = :dexterity,
            vitality = :vitality,
            energy = :energy,
            fate = :fate
        WHERE id = :character_id
          AND user_id = :user_id
          AND stat_points > 0
    ');

    $updateStmt->execute([
        'stat_points' => $allocated['stat_points'],
        'strength' => $allocated['strength'],
        'dexterity' => $allocated['dexterity'],
        'vitality' => $allocated['vitality'],
        'energy' => $allocated['energy'],
        'fate' => $allocated['fate'],
        'character_id' => (int) $characterId,
        'user_id' => $userId,
    ]);

    if ($updateStmt->rowCount() !== 1) {
        throw new RuntimeException('Champion allocation update failed.');
    }

    $pdo->commit();
    setAllocationFlash('Stat point allocated.', 'success');
} catch (InvalidArgumentException | DomainException | OutOfBoundsException $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }

    setAllocationFlash($e->getMessage(), 'error');
} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }

    error_log(
        'Stat allocation failed for character ' .
        (int) $characterId . ': ' . $e->getMessage(),
    );
    setAllocationFlash('Unable to allocate the stat point. Please try again.', 'error');
}

redirectToStatPage((int) $characterId);
