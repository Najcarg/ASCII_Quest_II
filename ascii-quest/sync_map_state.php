<?php
declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| ASCII Quest - Sync Map State
|--------------------------------------------------------------------------
| Purpose:
|   Lets browser ask server:
|   "Did any temporary map overrides expire?"
|
| Current use:
|   Triggered traps reset after 5 minutes server time.
*/

session_start();

require_once __DIR__ . "/db.php";
require_once __DIR__ . "/map_loader.php";

$pdo = getDb();

header("Content-Type: application/json");

function sendJson(array $data): void
{
    echo json_encode($data);
    exit();
}

if (!isset($_SESSION["user_id"]) || !isset($_SESSION["character_id"])) {
    sendJson([
        "success" => false,
        "tile_updates" => [],
    ]);
}

/*
|--------------------------------------------------------------------------
| Load character and current map
|--------------------------------------------------------------------------
*/
$stmt = $pdo->prepare("
    SELECT
        c.id,
        c.current_map_id,
        gm.map_file
    FROM characters c
    INNER JOIN game_maps gm
        ON gm.id = c.current_map_id
    WHERE c.id = :character_id
      AND c.user_id = :user_id
    LIMIT 1
");

$stmt->execute([
    "character_id" => $_SESSION["character_id"],
    "user_id" => $_SESSION["user_id"],
]);

$character = $stmt->fetch();

if (!$character) {
    sendJson([
        "success" => false,
        "tile_updates" => [],
    ]);
}

try {
    $mapData = loadMapFromFile((string) $character["map_file"]);
} catch (RuntimeException $e) {
    error_log("Sync map loading error: " . $e->getMessage());

    sendJson([
        "success" => false,
        "tile_updates" => [],
    ]);
}

$mapRows = $mapData["layout"];

/*
|--------------------------------------------------------------------------
| Find expired temporary overrides
|--------------------------------------------------------------------------
*/
$expiredStmt = $pdo->prepare("
    SELECT pos_x, pos_y, glyph
    FROM character_map_overrides
    WHERE character_id = :character_id
      AND map_id = :map_id
      AND expires_at IS NOT NULL
      AND expires_at <= NOW()
");

$expiredStmt->execute([
    "character_id" => $_SESSION["character_id"],
    "map_id" => $character["current_map_id"],
]);

$expiredOverrides = $expiredStmt->fetchAll();

$tileUpdates = [];

foreach ($expiredOverrides as $override) {
    $x = (int) $override["pos_x"];
    $y = (int) $override["pos_y"];

    /*
    |--------------------------------------------------------------------------
    | Restore original glyph from JSON map
    |--------------------------------------------------------------------------
    | Example:
    |   override was v
    |   original map tile is ^
    */
    if (isset($mapRows[$y]) && $x >= 0 && $x < strlen($mapRows[$y])) {
        $tileUpdates[] = [
            "x" => $x,
            "y" => $y,
            "glyph" => $mapRows[$y][$x],
        ];
    }
}

/*
|--------------------------------------------------------------------------
| Delete expired overrides after preparing updates
|--------------------------------------------------------------------------
*/
if (count($expiredOverrides) > 0) {
    $deleteStmt = $pdo->prepare("
        DELETE FROM character_map_overrides
        WHERE character_id = :character_id
          AND map_id = :map_id
          AND expires_at IS NOT NULL
          AND expires_at <= NOW()
    ");

    $deleteStmt->execute([
        "character_id" => $_SESSION["character_id"],
        "map_id" => $character["current_map_id"],
    ]);
}

sendJson([
    "success" => true,
    "tile_updates" => $tileUpdates,
]);
