<?php
declare(strict_types=1);

session_start();

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate');
header('Pragma: no-cache');

function sendCombatStateJson(array $payload, int $status): never
{
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE);
    exit();
}

if (!isset($_SESSION['user_id'], $_SESSION['character_id'])) {
    sendCombatStateJson([
        'success' => false,
        'message' => 'Your combat session has expired.',
    ], 401);
}

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'GET') {
    header('Allow: GET');
    sendCombatStateJson([
        'success' => false,
        'message' => 'Invalid combat state request.',
    ], 405);
}

try {
    require_once __DIR__ . '/db.php';
    require_once __DIR__ . '/lib/CombatBootstrap.php';

    $service = CombatBootstrap::service(getDb());
    $state = $service->state(
        (int) $_SESSION['user_id'],
        (int) $_SESSION['character_id'],
    );
    if ($state === []) {
        sendCombatStateJson([
            'success' => false,
            'message' => 'No active combat encounter was found.',
        ], 404);
    }

    sendCombatStateJson($state, 200);
} catch (OutOfBoundsException) {
    sendCombatStateJson([
        'success' => false,
        'message' => 'Champion unavailable.',
    ], 404);
} catch (DomainException $exception) {
    error_log('Combat state domain failure: ' . $exception->getMessage());
    sendCombatStateJson([
        'success' => false,
        'message' => 'Combat state is unavailable.',
    ], 422);
} catch (Throwable $exception) {
    error_log('Combat state failed: ' . $exception->getMessage());
    sendCombatStateJson([
        'success' => false,
        'message' => 'Unable to synchronize combat. Please try again.',
    ], 500);
}
