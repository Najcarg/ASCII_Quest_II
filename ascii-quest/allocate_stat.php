<?php
declare(strict_types=1);

session_start();

$wantsJson = str_contains(
    (string) ($_SERVER['HTTP_ACCEPT'] ?? ''),
    'application/json',
);

function sendJson(array $payload, int $status): never
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($payload, JSON_UNESCAPED_UNICODE);
    exit();
}

if (!isset($_SESSION['user_id'])) {
    if ($wantsJson) {
        sendJson([
            'success' => false,
            'message' => 'Your session has expired. Please sign in again.',
        ], 401);
    }

    header('Location: login.php');
    exit();
}

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/lib/CharacterStatAllocator.php';
require_once __DIR__ . '/lib/CharacterStats.php';
require_once __DIR__ . '/lib/CombatBootstrap.php';

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

function respondAllocationError(
    bool $wantsJson,
    string $message,
    int $status,
    ?int $characterId = null,
): never {
    if ($wantsJson) {
        sendJson([
            'success' => false,
            'message' => $message,
        ], $status);
    }

    setAllocationFlash($message, 'error');
    redirectToStatPage($characterId);
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    if ($wantsJson) {
        respondAllocationError(
            true,
            'Invalid allocation request.',
            405,
        );
    }

    redirectToStatPage();
}

$rawCharacterId = $_POST['character_id'] ?? null;
$characterId = is_string($rawCharacterId)
    ? filter_var($rawCharacterId, FILTER_VALIDATE_INT, [
        'options' => ['min_range' => 1],
    ])
    : false;

if ($characterId === false) {
    respondAllocationError(
        $wantsJson,
        'Invalid allocation request.',
        400,
    );
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
    respondAllocationError(
        $wantsJson,
        'Security check failed. Please try again.',
        403,
        (int) $characterId,
    );
}

$stat = $_POST['stat'] ?? '';
if (!is_string($stat)) {
    respondAllocationError(
        $wantsJson,
        'Invalid stat selection.',
        422,
        (int) $characterId,
    );
}

$userId = (int) $_SESSION['user_id'];
$pdo = null;
$combatGuard = null;

try {
    $pdo = getDb();
    $combatGuard = CombatBootstrap::guard($pdo);
    $decision = $combatGuard->beginAtomic(
        CombatAccessGuard::STAT_ALLOCATE,
        $userId,
        (int) $characterId,
    );
    $character = $decision['character'];

    $allocated = CharacterStatAllocator::allocate($character, $userId, $stat);
    $calculatedStats = CharacterStats::calculate($allocated);

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

    $combatGuard->commit();
} catch (InvalidArgumentException | DomainException | OutOfBoundsException $e) {
    $combatGuard?->rollBack();

    respondAllocationError(
        $wantsJson,
        $e->getMessage(),
        422,
        (int) $characterId,
    );
} catch (Throwable $e) {
    $combatGuard?->rollBack();

    error_log(
        'Stat allocation failed for character ' .
        (int) $characterId . ': ' . $e->getMessage(),
    );
    respondAllocationError(
        $wantsJson,
        'Unable to allocate the stat point. Please try again.',
        500,
        (int) $characterId,
    );
}

if ($wantsJson) {
    sendJson([
        'success' => true,
        'message' => 'Stat point allocated.',
        'character' => [
            'stat_points' => (int) $allocated['stat_points'],
            'current_hp' => (int) $allocated['current_hp'],
            'current_mana' => (int) $allocated['current_mana'],
            'stats' => $calculatedStats,
        ],
    ], 200);
}

setAllocationFlash('Stat point allocated.', 'success');
redirectToStatPage((int) $characterId);
