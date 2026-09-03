<?php
declare(strict_types=1);

session_start();

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/lib/WarpBootstrap.php';
require_once __DIR__ . '/lib/CombatBootstrap.php';

header('Content-Type: application/json; charset=utf-8');

function sendWarpUnlockJson(array $payload, int $status = 200): never
{
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE);
    exit();
}

if (!isset($_SESSION['user_id'], $_SESSION['character_id'])) {
    sendWarpUnlockJson([
        'success' => false,
        'message' => 'Your exploration session has expired.',
    ], 401);
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    sendWarpUnlockJson([
        'success' => false,
        'message' => 'Invalid Warp request.',
    ], 405);
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
    sendWarpUnlockJson([
        'success' => false,
        'message' => 'Security check failed. Please try again.',
    ], 403);
}

$warpId = $_POST['warp_id'] ?? '';
if (!is_string($warpId) || trim($warpId) === '') {
    sendWarpUnlockJson([
        'success' => false,
        'message' => 'Invalid Warp request.',
    ], 400);
}

$combatGuard = null;
try {
    $pdo = getDb();
    $combatGuard = CombatBootstrap::guard($pdo);
    $combatGuard->beginAtomic(
        CombatAccessGuard::WARP_UNLOCK,
        (int) $_SESSION['user_id'],
        (int) $_SESSION['character_id'],
    );
    $service = WarpBootstrap::service($pdo);
    $result = $service->unlock(
        (int) $_SESSION['user_id'],
        (int) $_SESSION['character_id'],
        trim($warpId),
    );
    $combatGuard->commit();

    sendWarpUnlockJson(['success' => true] + $result);
} catch (DomainException $e) {
    $combatGuard?->rollBack();
    sendWarpUnlockJson([
        'success' => false,
        'message' => $e->getMessage(),
    ], 422);
} catch (OutOfBoundsException) {
    $combatGuard?->rollBack();
    sendWarpUnlockJson([
        'success' => false,
        'message' => 'Champion unavailable.',
    ], 404);
} catch (Throwable $e) {
    $combatGuard?->rollBack();
    error_log('Warp unlock failed: ' . $e->getMessage());
    sendWarpUnlockJson([
        'success' => false,
        'message' => 'Unable to unlock that Warp. Please try again.',
    ], 500);
}
