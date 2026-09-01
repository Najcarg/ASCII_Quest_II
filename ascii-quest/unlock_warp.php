<?php
declare(strict_types=1);

session_start();

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/lib/WarpBootstrap.php';

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

try {
    $service = WarpBootstrap::service(getDb());
    $result = $service->unlock(
        (int) $_SESSION['user_id'],
        (int) $_SESSION['character_id'],
        trim($warpId),
    );

    sendWarpUnlockJson(['success' => true] + $result);
} catch (DomainException $e) {
    sendWarpUnlockJson([
        'success' => false,
        'message' => $e->getMessage(),
    ], 422);
} catch (OutOfBoundsException) {
    sendWarpUnlockJson([
        'success' => false,
        'message' => 'Champion unavailable.',
    ], 404);
} catch (Throwable $e) {
    error_log('Warp unlock failed: ' . $e->getMessage());
    sendWarpUnlockJson([
        'success' => false,
        'message' => 'Unable to unlock that Warp. Please try again.',
    ], 500);
}
