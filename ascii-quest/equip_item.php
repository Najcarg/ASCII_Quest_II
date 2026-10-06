<?php
declare(strict_types=1);

session_start();
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate');
header('Pragma: no-cache');

function sendEquipJson(array $payload, int $status): never
{
    http_response_code($status); echo json_encode($payload, JSON_UNESCAPED_UNICODE); exit();
}

if (!isset($_SESSION['user_id'], $_SESSION['character_id'])) {
    sendEquipJson(['success' => false, 'message' => 'Your equipment session has expired.'], 401);
}
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    header('Allow: POST'); sendEquipJson(['success' => false, 'message' => 'Invalid equipment request.'], 405);
}
try {
    $raw = (string) file_get_contents('php://input');
    if ($raw === '' && PHP_SAPI === 'cli') { $raw = (string) file_get_contents('php://stdin'); }
    $request = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
} catch (JsonException) {
    sendEquipJson(['success' => false, 'message' => 'Invalid equipment request.'], 400);
}
$keys = is_array($request) ? array_keys($request) : [];
$allowed = ['csrf_token', 'item_id', 'request_token', 'slot']; sort($keys); sort($allowed);
if (!is_array($request) || $keys !== $allowed) {
    sendEquipJson(['success' => false, 'message' => 'Invalid equipment request.'], 400);
}
if (!isset($_SESSION['csrf_token']) || !is_string($request['csrf_token']) || !hash_equals((string) $_SESSION['csrf_token'], $request['csrf_token'])) {
    sendEquipJson(['success' => false, 'message' => 'Invalid equipment request.'], 403);
}
$slots = ['helm', 'gloves', 'chest', 'ring', 'weapon', 'off-hand', 'amulet', 'belt', 'charm', 'boots'];
if (!is_int($request['item_id']) || $request['item_id'] < 1 || !is_string($request['slot']) || !in_array($request['slot'], $slots, true)
    || !is_string($request['request_token']) || preg_match('/\A[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}\z/D', $request['request_token']) !== 1) {
    sendEquipJson(['success' => false, 'message' => 'Invalid equipment intent.'], 422);
}
try {
    require_once __DIR__ . '/db.php'; require_once __DIR__ . '/lib/EquipmentBootstrap.php'; require_once __DIR__ . '/lib/ItemBootstrap.php';
    $pdo = getDb();
    $result = EquipmentBootstrap::service($pdo)->equip((int) $_SESSION['user_id'], (int) $_SESSION['character_id'], $request['item_id'], $request['slot'], $request['request_token']);
    $state = ItemBootstrap::service($pdo)->state((int) $_SESSION['user_id'], (int) $_SESSION['character_id'], 1);
    sendEquipJson(['result' => $result, 'state' => $state], 200);
} catch (OutOfBoundsException|DomainException $exception) {
    error_log('Equipment unavailable: ' . $exception->getMessage()); sendEquipJson(['success' => false, 'message' => 'Equipment unavailable.'], 422);
} catch (Throwable $exception) {
    error_log('Equipment failed: ' . $exception->getMessage()); sendEquipJson(['success' => false, 'message' => 'Unable to change equipment. Please try again.'], 500);
}
