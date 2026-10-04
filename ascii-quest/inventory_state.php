<?php
declare(strict_types=1);

session_start();

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate');
header('Pragma: no-cache');

function sendInventoryStateJson(array $payload, int $status): never
{
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE);
    exit();
}

if (!isset($_SESSION['user_id'], $_SESSION['character_id'])) {
    sendInventoryStateJson([
        'success' => false,
        'message' => 'Your inventory session has expired.',
    ], 401);
}

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'GET') {
    header('Allow: GET');
    sendInventoryStateJson([
        'success' => false,
        'message' => 'Invalid inventory request.',
    ], 405);
}

if (array_diff(array_keys($_GET), ['page']) !== []) {
    sendInventoryStateJson([
        'success' => false,
        'message' => 'Invalid inventory page.',
    ], 422);
}

$pageInput = $_GET['page'] ?? '1';
if (
    !is_string($pageInput)
    || preg_match('/^[1-9][0-9]*$/D', $pageInput) !== 1
    || strlen($pageInput) > 10
    || (int) $pageInput > 2147483647
) {
    sendInventoryStateJson([
        'success' => false,
        'message' => 'Invalid inventory page.',
    ], 422);
}
$page = (int) $pageInput;

try {
    require_once __DIR__ . '/db.php';
    require_once __DIR__ . '/lib/ItemBootstrap.php';

    $state = ItemBootstrap::service(getDb())->state(
        (int) $_SESSION['user_id'],
        (int) $_SESSION['character_id'],
        $page,
    );
    sendInventoryStateJson($state, 200);
} catch (OutOfBoundsException) {
    sendInventoryStateJson([
        'success' => false,
        'message' => 'Champion unavailable.',
    ], 404);
} catch (InvalidArgumentException) {
    sendInventoryStateJson([
        'success' => false,
        'message' => 'Invalid inventory page.',
    ], 422);
} catch (DomainException $exception) {
    error_log('Inventory state domain failure: ' . $exception->getMessage());
    sendInventoryStateJson([
        'success' => false,
        'message' => 'Inventory state is unavailable.',
    ], 422);
} catch (Throwable $exception) {
    error_log('Inventory state failed: ' . $exception->getMessage());
    sendInventoryStateJson([
        'success' => false,
        'message' => 'Unable to load inventory. Please try again.',
    ], 500);
}
