<?php
declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| ASCII Quest - Interaction Endpoint
|--------------------------------------------------------------------------
| Purpose:
|   Handles interaction with the tile/player surroundings.
|
| Current interactions:
|   E on > or <     = map transition
|   E next to chest = open chest and collect gold
|
| Static map design comes from JSON.
| Player-specific changes are saved in SQL.
*/

session_start();

require_once __DIR__ . "/db.php";
require_once __DIR__ . "/map_loader.php";

$pdo = getDb();

header("Content-Type: application/json");

/*
|--------------------------------------------------------------------------
| Helper: return JSON and stop
|--------------------------------------------------------------------------
*/
function sendJson(array $data): void
{
    echo json_encode($data);
    exit();
}

/*
|--------------------------------------------------------------------------
| Helper: apply character-specific tile changes
|--------------------------------------------------------------------------
| Example:
|   closed door + became /
|   closed chest O became o
*/
function applyCharacterMapOverrides(
    PDO $pdo,
    array $mapRows,
    int $characterId,
    int $mapId,
): array {
    $overrideStmt = $pdo->prepare("
        SELECT pos_x, pos_y, glyph
        FROM character_map_overrides
        WHERE character_id = :character_id
          AND map_id = :map_id
    ");

    $overrideStmt->execute([
        "character_id" => $characterId,
        "map_id" => $mapId,
    ]);

    foreach ($overrideStmt->fetchAll() as $override) {
        $overrideX = (int) $override["pos_x"];
        $overrideY = (int) $override["pos_y"];
        $overrideGlyph = (string) $override["glyph"];

        if (
            isset($mapRows[$overrideY]) &&
            $overrideX >= 0 &&
            $overrideX < strlen($mapRows[$overrideY])
        ) {
            $mapRows[$overrideY][$overrideX] = $overrideGlyph;
        }
    }

    return $mapRows;
}

/*
|--------------------------------------------------------------------------
| Helper: get map glyph at X/Y
|--------------------------------------------------------------------------
*/
function getMapGlyph(array $mapRows, int $x, int $y): string
{
    if (!isset($mapRows[$y]) || $x < 0 || $x >= strlen($mapRows[$y])) {
        return " ";
    }

    return $mapRows[$y][$x];
}

/*
|--------------------------------------------------------------------------
| Helper: check if object is next to player
|--------------------------------------------------------------------------
| We use this for chests because chest tiles are not walkable.
*/
function isAdjacentToPlayer(
    int $playerX,
    int $playerY,
    int $objectX,
    int $objectY,
): bool {
    $distance = abs($playerX - $objectX) + abs($playerY - $objectY);

    return $distance === 1;
}

/*
|--------------------------------------------------------------------------
| Security checks
|--------------------------------------------------------------------------
*/
if (!isset($_SESSION["user_id"])) {
    sendJson([
        "success" => false,
        "message" => "Not logged in.",
        "messages" => ["Not logged in."],
    ]);
}

if (!isset($_SESSION["character_id"])) {
    sendJson([
        "success" => false,
        "message" => "No character selected.",
        "messages" => ["No character selected."],
    ]);
}

if ($_SERVER["REQUEST_METHOD"] !== "POST") {
    sendJson([
        "success" => false,
        "message" => "Invalid request method.",
        "messages" => ["Invalid request method."],
    ]);
}

/*
|--------------------------------------------------------------------------
| Load selected character and current map file
|--------------------------------------------------------------------------
*/
$stmt = $pdo->prepare("
    SELECT
        c.id,
        c.current_map_id,
        c.pos_x,
        c.pos_y,
        c.gold,

        gm.map_key,
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
        "message" => "Character not found.",
        "messages" => ["Character not found."],
    ]);
}

$characterId = (int) $character["id"];
$currentMapId = (int) $character["current_map_id"];
$playerX = (int) $character["pos_x"];
$playerY = (int) $character["pos_y"];
$currentGold = (int) $character["gold"];

/*
|--------------------------------------------------------------------------
| Load current map JSON
|--------------------------------------------------------------------------
*/
try {
    $mapData = loadMapFromFile((string) $character["map_file"]);
} catch (RuntimeException $e) {
    error_log("Interact map loading error: " . $e->getMessage());

    sendJson([
        "success" => false,
        "message" => "Map loading failed.",
        "messages" => ["Map loading failed."],
    ]);
}

$mapRows = $mapData["layout"];

/*
|--------------------------------------------------------------------------
| Apply character-specific changes before checking interactions
|--------------------------------------------------------------------------
*/
$mapRows = applyCharacterMapOverrides(
    $pdo,
    $mapRows,
    $characterId,
    $currentMapId,
);

$currentGlyph = getMapGlyph($mapRows, $playerX, $playerY);

/*
|--------------------------------------------------------------------------
| Interaction 1: map transitions
|--------------------------------------------------------------------------
| Example:
|   Player stands on >
|   Presses E
|   Goes to target map from JSON transition
*/
foreach ($mapData["transitions"] ?? [] as $transition) {
    if (
        (int) $transition["x"] === $playerX &&
        (int) $transition["y"] === $playerY &&
        (string) $transition["glyph"] === $currentGlyph
    ) {
        $targetStmt = $pdo->prepare("
            SELECT id, map_name, map_file
            FROM game_maps
            WHERE map_key = :map_key
            LIMIT 1
        ");

        $targetStmt->execute([
            "map_key" => $transition["target_map_key"],
        ]);

        $targetMap = $targetStmt->fetch();

        if (!$targetMap) {
            sendJson([
                "success" => false,
                "message" => "Target map does not exist.",
                "messages" => ["Target map does not exist."],
            ]);
        }

        $updateStmt = $pdo->prepare("
            UPDATE characters
            SET current_map_id = :target_map_id,
                pos_x = :target_x,
                pos_y = :target_y
            WHERE id = :character_id
              AND user_id = :user_id
        ");

        $updateStmt->execute([
            "target_map_id" => $targetMap["id"],
            "target_x" => $transition["target_x"],
            "target_y" => $transition["target_y"],
            "character_id" => $characterId,
            "user_id" => $_SESSION["user_id"],
        ]);

        sendJson([
            "success" => true,
            "action" => "map_transition",
            "reload" => true,
            "message" => $transition["message"],
            "messages" => [
                $transition["message"],
                "You arrive at " . $targetMap["map_name"] . ".",
            ],
        ]);
    }
}

/*
|--------------------------------------------------------------------------
| Interaction 2: chests
|--------------------------------------------------------------------------
| Chest tiles are not walkable, so player interacts from adjacent tile.
*/
foreach ($mapData["objects"] ?? [] as $object) {
    if (($object["type"] ?? "") !== "chest") {
        continue;
    }

    $chestX = (int) $object["x"];
    $chestY = (int) $object["y"];

    if (!isAdjacentToPlayer($playerX, $playerY, $chestX, $chestY)) {
        continue;
    }

    $closedGlyph = (string) ($object["glyph"] ?? "O");
    $openedGlyph = (string) ($object["opened_glyph"] ?? "o");

    $currentChestGlyph = getMapGlyph($mapRows, $chestX, $chestY);

    /*
    |--------------------------------------------------------------------------
    | Chest already opened
    |--------------------------------------------------------------------------
    */
    if ($currentChestGlyph !== $closedGlyph) {
        $openedMessage =
            (string) ($object["opened_message"] ??
                "This chest is already open.");

        sendJson([
            "success" => false,
            "message" => $openedMessage,
            "messages" => [$openedMessage],
        ]);
    }

    $goldReward = max(0, (int) ($object["gold"] ?? 0));

    /*
    |--------------------------------------------------------------------------
    | Save opened chest as a map override
    |--------------------------------------------------------------------------
    | This keeps the chest opened for this character.
    */
    $overrideStmt = $pdo->prepare("
        INSERT INTO character_map_overrides (
            character_id,
            map_id,
            pos_x,
            pos_y,
            glyph
        )
        VALUES (
            :character_id,
            :map_id,
            :pos_x,
            :pos_y,
            :glyph
        )
        ON DUPLICATE KEY UPDATE
            glyph = VALUES(glyph),
            updated_at = CURRENT_TIMESTAMP
    ");

    $overrideStmt->execute([
        "character_id" => $characterId,
        "map_id" => $currentMapId,
        "pos_x" => $chestX,
        "pos_y" => $chestY,
        "glyph" => $openedGlyph,
    ]);

    /*
    |--------------------------------------------------------------------------
    | Add gold reward to character
    |--------------------------------------------------------------------------
    */
    if ($goldReward > 0) {
        $goldStmt = $pdo->prepare("
            UPDATE characters
            SET gold = gold + :gold
            WHERE id = :character_id
              AND user_id = :user_id
        ");

        $goldStmt->execute([
            "gold" => $goldReward,
            "character_id" => $characterId,
            "user_id" => $_SESSION["user_id"],
        ]);
    }

    /*
    |--------------------------------------------------------------------------
    | Reload gold from database after update
    |--------------------------------------------------------------------------
    | This makes sure the value sent to JavaScript is the real saved value.
    */
    $goldCheckStmt = $pdo->prepare("
        SELECT gold
        FROM characters
        WHERE id = :character_id
          AND user_id = :user_id
        LIMIT 1
    ");

    $goldCheckStmt->execute([
        "character_id" => $characterId,
        "user_id" => $_SESSION["user_id"],
    ]);

    $goldCheck = $goldCheckStmt->fetch();

    $newGold = $goldCheck
        ? (int) $goldCheck["gold"]
        : $currentGold + $goldReward;

    $messages = [(string) ($object["message"] ?? "You open the chest.")];

    if ($goldReward > 0) {
        $messages[] = "You find " . $goldReward . " gold.";
    }

    sendJson([
        "success" => true,
        "action" => "open_chest",
        "reload" => false,
        "message" => $messages[0],
        "messages" => $messages,
        "tile_updates" => [
            [
                "x" => $chestX,
                "y" => $chestY,
                "glyph" => $openedGlyph,
            ],
        ],
        "character_updates" => [
            "gold" => $newGold,
        ],
    ]);
}

/*
|--------------------------------------------------------------------------
| No interaction found
|--------------------------------------------------------------------------
*/
sendJson([
    "success" => false,
    "message" => "There is nothing to interact with here.",
    "messages" => ["There is nothing to interact with here."],
]);
