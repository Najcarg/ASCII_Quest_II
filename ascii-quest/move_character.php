<?php
declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| ASCII Quest - Character Movement Endpoint
|--------------------------------------------------------------------------
| Purpose:
|   Receives movement request from browser.
|
| Browser sends:
|   direction = up / down / left / right
|
| PHP checks:
|   1. User is logged in
|   2. Character belongs to user
|   3. Target tile is inside map
|   4. Target tile is walkable
|   5. Door interaction
|   6. Trap trigger
|
| Important:
|   Browser is NOT trusted.
|   PHP is the source of truth.
*/

session_start();

require_once __DIR__ . "/db.php";
require_once __DIR__ . "/map_loader.php";
require_once __DIR__ . "/lib/CharacterStats.php";
require_once __DIR__ . "/lib/WarpBootstrap.php";
require_once __DIR__ . "/lib/CombatBootstrap.php";

$pdo = getDb();
$combatRepository = null;
$movementTransactionOpen = false;

header("Content-Type: application/json");

/*
|--------------------------------------------------------------------------
| Helper: return JSON and stop script
|--------------------------------------------------------------------------
*/
function sendJson(array $data): never
{
    global $combatRepository, $movementTransactionOpen;
    if ($movementTransactionOpen && $combatRepository instanceof CombatRepository) {
        $combatRepository->rollBack();
        $movementTransactionOpen = false;
    }

    echo json_encode($data);
    exit();
}

function commitMovementTransaction(): void
{
    global $combatRepository, $movementTransactionOpen;
    if (!$movementTransactionOpen || !$combatRepository instanceof CombatRepository) {
        throw new LogicException('Movement transaction is unavailable.');
    }

    $combatRepository->commit();
    $movementTransactionOpen = false;
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
| Read requested direction
|--------------------------------------------------------------------------
*/
$direction = $_POST["direction"] ?? "";

$dx = 0;
$dy = 0;

/*
|--------------------------------------------------------------------------
| Human-readable direction names for message log
|--------------------------------------------------------------------------
*/
$directionNames = [
    "up" => "north",
    "down" => "south",
    "left" => "west",
    "right" => "east",
];

/*
|--------------------------------------------------------------------------
| Convert direction into X/Y movement
|--------------------------------------------------------------------------
| x - 1 = left
| x + 1 = right
| y - 1 = up
| y + 1 = down
*/
if ($direction === "up") {
    $dy = -1;
} elseif ($direction === "down") {
    $dy = 1;
} elseif ($direction === "left") {
    $dx = -1;
} elseif ($direction === "right") {
    $dx = 1;
} else {
    sendJson([
        "success" => false,
        "message" => "Invalid direction.",
        "messages" => ["Invalid direction."],
    ]);
}

/*
|--------------------------------------------------------------------------
| Load selected character + current map file
|--------------------------------------------------------------------------
*/
$combatRepository = CombatBootstrap::repository($pdo);
$combatService = CombatBootstrap::serviceForRepository($combatRepository);
$combatGuard = CombatBootstrap::guardForRepository($combatRepository);
$combatRepository->beginTransaction();
$movementTransactionOpen = true;
$character = $combatRepository->lockOwnedCharacter(
    (int) $_SESSION["user_id"],
    (int) $_SESSION["character_id"],
);

if (!$character) {
    sendJson([
        "success" => false,
        "message" => "Character not found.",
        "messages" => ["Character not found."],
    ]);
}

$character["map_file"] = (string) $character["current_map_file"];
$lockedEncounter = $combatRepository->lockOwnedAccountActiveEncounter(
    (int) $_SESSION["user_id"],
    (int) $character["id"],
);
try {
    $combatGuard->assertLockedAllowed(
        CombatAccessGuard::MOVE,
        (int) $_SESSION["user_id"],
        $character,
        $lockedEncounter,
    );
} catch (DomainException $e) {
    if (
        $lockedEncounter === null ||
        (int) $lockedEncounter["character_id"] !== (int) $character["id"]
    ) {
        sendJson([
            "success" => false,
            "message" => $e->getMessage(),
            "messages" => [$e->getMessage()],
        ]);
    }

    $encounterDefinition = CombatBootstrap::validatedEncounterForMap(
        (string) $character["map_file"],
        loadMapFromFile((string) $character["map_file"]),
    );
    if ($encounterDefinition === null) {
        throw new RuntimeException('Stored combat map has no encounter definition.');
    }
    $combatState = $combatService->startOrResumeForLockedMovement(
        (int) $_SESSION["user_id"],
        $character,
        $encounterDefinition,
    );

    sendJson([
        "success" => false,
        "combat_started" => true,
        "combat" => $combatState,
        "message" => "Combat is active. Resume the battle.",
        "messages" => ["Combat is active. Resume the battle."],
    ]);
}

$characterId = (int) $character["id"];
$currentMapId = (int) $character["current_map_id"];

$currentX = (int) $character["pos_x"];
$currentY = (int) $character["pos_y"];

$currentHp = (int) $character["current_hp"];

try {
    $characterStats = CharacterStats::calculate($character);
} catch (InvalidArgumentException $e) {
    error_log(
        "CharacterStats movement error for character " .
        (int) $character["id"] . ": " . $e->getMessage(),
    );

    sendJson([
        "success" => false,
        "message" => "Unable to load Champion statistics.",
        "messages" => ["Unable to load Champion statistics."],
    ]);
}

$maxHp = $characterStats["resources"]["max_life"];

$newX = $currentX + $dx;
$newY = $currentY + $dy;

/*
|--------------------------------------------------------------------------
| Load map from JSON file
|--------------------------------------------------------------------------
*/
try {
    $mapData = loadMapFromFile((string) $character["map_file"]);
} catch (RuntimeException $e) {
    error_log("Move map loading error: " . $e->getMessage());

    sendJson([
        "success" => false,
        "message" => "Map loading failed.",
        "messages" => ["Map loading failed."],
    ]);
}

try {
    $encounterDefinition = CombatBootstrap::validatedEncounterForMap(
        (string) $character["map_file"],
        $mapData,
    );
} catch (RuntimeException $e) {
    error_log("Combat map validation error: " . $e->getMessage());
    sendJson([
        "success" => false,
        "message" => "Map loading failed.",
        "messages" => ["Map loading failed."],
    ]);
}

$mapWidth = (int) $mapData["width"];
$mapHeight = (int) $mapData["height"];

/*
|--------------------------------------------------------------------------
| Check map boundary
|--------------------------------------------------------------------------
*/
if ($newX < 0 || $newY < 0 || $newX >= $mapWidth || $newY >= $mapHeight) {
    sendJson([
        "success" => false,
        "message" => "You cannot leave the map.",
        "messages" => ["You cannot leave the map."],
    ]);
}

if ($encounterDefinition !== null) {
    $contactDecision = $combatService->movementDecision(
        $character,
        $newX,
        $newY,
        false,
        $encounterDefinition,
    );
    if ($contactDecision["direct_contact"] && $contactDecision["start_combat"]) {
        $combatState = $combatService->startOrResumeForLockedMovement(
            (int) $_SESSION["user_id"],
            $character,
            $encounterDefinition,
        );
        commitMovementTransaction();

        sendJson([
            "success" => true,
            "combat_started" => true,
            "combat" => $combatState,
            "message" => "The Cave Brute engages.",
            "messages" => ["The Cave Brute engages."],
            "pos_x" => $currentX,
            "pos_y" => $currentY,
            "tile_updates" => [],
            "character_updates" => [],
        ]);
    }
}

/*
|--------------------------------------------------------------------------
| Warp occupancy
|--------------------------------------------------------------------------
| Warp metadata remains authoritative even though its underlying layout
| character is an ordinary floor tile.
*/
try {
    $warpDefinitions = WarpBootstrap::definitions();
} catch (RuntimeException $e) {
    error_log("Move Warp loading error: " . $e->getMessage());

    sendJson([
        "success" => false,
        "message" => "Map loading failed.",
        "messages" => ["Map loading failed."],
    ]);
}

if ($warpDefinitions->isWarpPosition(
    (string) $character["map_file"],
    $newX,
    $newY,
)) {
    sendJson([
        "success" => false,
        "message" => "The Warp blocks your path.",
        "messages" => ["The Warp blocks your path."],
    ]);
}

/*
|--------------------------------------------------------------------------
| Map rows from JSON
|--------------------------------------------------------------------------
*/
$mapRows = $mapData["layout"];

/*
|--------------------------------------------------------------------------
| Clean expired temporary map overrides
|--------------------------------------------------------------------------
| Server/database time is used with MariaDB NOW().
|
| Example:
|   Triggered trap v expires after 5 minutes.
|
| Permanent changes:
|   opened doors /
|   opened chests o
|
| These have expires_at = NULL and are NOT deleted.
*/
$cleanupStmt = $pdo->prepare("
    DELETE FROM character_map_overrides
    WHERE character_id = :character_id
      AND map_id = :map_id
      AND expires_at IS NOT NULL
      AND expires_at <= NOW()
");

$cleanupStmt->execute([
    "character_id" => $characterId,
    "map_id" => $currentMapId,
]);

/*
|--------------------------------------------------------------------------
| Apply active map overrides before collision check
|--------------------------------------------------------------------------
| Active overrides are:
|   1. Permanent overrides where expires_at IS NULL
|   2. Temporary overrides where expires_at > NOW()
*/
$overrideStmt = $pdo->prepare("
    SELECT pos_x, pos_y, glyph
    FROM character_map_overrides
    WHERE character_id = :character_id
      AND map_id = :map_id
      AND (
          expires_at IS NULL
          OR expires_at > NOW()
      )
");

$overrideStmt->execute([
    "character_id" => $characterId,
    "map_id" => $currentMapId,
]);

$mapOverrides = $overrideStmt->fetchAll();

foreach ($mapOverrides as $override) {
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

/*
|--------------------------------------------------------------------------
| Get target tile glyph
|--------------------------------------------------------------------------
*/
if (!isset($mapRows[$newY]) || $newX >= strlen($mapRows[$newY])) {
    sendJson([
        "success" => false,
        "message" => "Invalid map tile.",
        "messages" => ["Invalid map tile."],
    ]);
}

$targetGlyph = $mapRows[$newY][$newX];

/*
|--------------------------------------------------------------------------
| Load target tile rules and messages
|--------------------------------------------------------------------------
*/
$tileStmt = $pdo->prepare("
    SELECT
        glyph,
        display_glyph,
        css_class,
        is_walkable,
        name,
        blocked_message,
        enter_message,
        interact_message
    FROM tile_types
    WHERE glyph = :glyph
    LIMIT 1
");

$tileStmt->execute([
    "glyph" => $targetGlyph,
]);

$targetTile = $tileStmt->fetch();

if (!$targetTile) {
    sendJson([
        "success" => false,
        "message" => "Unknown tile.",
        "messages" => ["Unknown tile."],
    ]);
}

/*
|--------------------------------------------------------------------------
| Door interaction
|--------------------------------------------------------------------------
| If player tries to move into +:
|   1. Player does NOT move yet
|   2. Door changes from + to /
|   3. Door stays open permanently for this character
|
| Important:
|   expires_at = NULL because opened doors should not reset.
*/
if ($targetGlyph === "+") {
    $openDoorGlyph = "/";

    $openDoorStmt = $pdo->prepare("
        SELECT glyph, display_glyph, css_class, name
        FROM tile_types
        WHERE glyph = :glyph
        LIMIT 1
    ");

    $openDoorStmt->execute([
        "glyph" => $openDoorGlyph,
    ]);

    $openDoorTile = $openDoorStmt->fetch();

    if (!$openDoorTile) {
        sendJson([
            "success" => false,
            "message" => "Open door tile is missing.",
            "messages" => ["Open door tile is missing."],
        ]);
    }

    $doorStmt = $pdo->prepare("
        INSERT INTO character_map_overrides (
            character_id,
            map_id,
            pos_x,
            pos_y,
            glyph,
            expires_at
        )
        VALUES (
            :character_id,
            :map_id,
            :pos_x,
            :pos_y,
            :glyph,
            NULL
        )
        ON DUPLICATE KEY UPDATE
            glyph = VALUES(glyph),
            expires_at = NULL,
            updated_at = CURRENT_TIMESTAMP
    ");

    $doorStmt->execute([
        "character_id" => $characterId,
        "map_id" => $currentMapId,
        "pos_x" => $newX,
        "pos_y" => $newY,
        "glyph" => $openDoorGlyph,
    ]);

    $doorMessage = $targetTile["interact_message"] ?: "You open the door.";
    commitMovementTransaction();

    sendJson([
        "success" => true,
        "message" => $doorMessage,
        "messages" => [$doorMessage],

        /*
        |--------------------------------------------------------------------------
        | Player stays in same position
        |--------------------------------------------------------------------------
        */
        "pos_x" => $currentX,
        "pos_y" => $currentY,

        "tile_updates" => [
            [
                "x" => $newX,
                "y" => $newY,
                "glyph" => $openDoorTile["glyph"],
                "display_glyph" => $openDoorTile["display_glyph"],
                "css_class" => $openDoorTile["css_class"],
                "name" => $openDoorTile["name"],
            ],
        ],

        "character_updates" => [],
    ]);
}

/*
|--------------------------------------------------------------------------
| Blocked tile
|--------------------------------------------------------------------------
*/
if ((int) $targetTile["is_walkable"] !== 1) {
    $blockedMessage =
        $targetTile["blocked_message"] ?:
        "Blocked by " . $targetTile["name"] . ".";

    sendJson([
        "success" => false,
        "message" => $blockedMessage,
        "messages" => [$blockedMessage],
    ]);
}

/*
|--------------------------------------------------------------------------
| Save new position
|--------------------------------------------------------------------------
*/
if (!$combatRepository->updateLockedCharacterPosition(
    (int) $_SESSION["user_id"],
    $characterId,
    $currentMapId,
    $currentX,
    $currentY,
    $newX,
    $newY,
)) {
    sendJson([
        "success" => false,
        "message" => "Your position changed. Please move again.",
        "messages" => ["Your position changed. Please move again."],
    ]);
}

/*
|--------------------------------------------------------------------------
| Build movement response
|--------------------------------------------------------------------------
*/
$messages = ["You move " . $directionNames[$direction] . "."];

$tileUpdates = [];
$characterUpdates = [];

/*
|--------------------------------------------------------------------------
| Normal tile enter message
|--------------------------------------------------------------------------
*/
if (!empty($targetTile["enter_message"])) {
    $messages[] = $targetTile["enter_message"];
}

/*
|--------------------------------------------------------------------------
| Trap trigger system
|--------------------------------------------------------------------------
| Trap data is stored in the map JSON objects list.
|
| If player steps on active trap:
|   1. Damage player
|   2. Change trap tile from ^ to v
|   3. Save override with expires_at = NOW() + 5 minutes
|   4. Return HP update to JavaScript
*/
foreach ($mapData["objects"] ?? [] as $object) {
    if (($object["type"] ?? "") !== "trap") {
        continue;
    }

    $trapX = (int) ($object["x"] ?? -1);
    $trapY = (int) ($object["y"] ?? -1);

    if ($trapX !== $newX || $trapY !== $newY) {
        continue;
    }

    $activeTrapGlyph = (string) ($object["glyph"] ?? "^");
    $triggeredTrapGlyph = (string) ($object["triggered_glyph"] ?? "v");

    /*
    |--------------------------------------------------------------------------
    | If tile is already disabled, do not trigger again
    |--------------------------------------------------------------------------
    */
    if ($targetGlyph !== $activeTrapGlyph) {
        continue;
    }

    $trapDamage = max(0, (int) ($object["damage"] ?? 0));

    /*
    |--------------------------------------------------------------------------
    | For now, traps cannot kill player.
    | They reduce HP to minimum 1.
    |
    | Later:
    |   We will add proper death system.
    |--------------------------------------------------------------------------
    */
    $newHp = max(1, $currentHp - $trapDamage);

    $hpStmt = $pdo->prepare("
        UPDATE characters
        SET current_hp = :current_hp
        WHERE id = :character_id
          AND user_id = :user_id
    ");

    $hpStmt->execute([
        "current_hp" => $newHp,
        "character_id" => $characterId,
        "user_id" => $_SESSION["user_id"],
    ]);

    /*
    |--------------------------------------------------------------------------
    | Save disabled trap override
    |--------------------------------------------------------------------------
    | This is temporary.
    | It expires after 5 minutes of server/database time.
    */
    $trapOverrideStmt = $pdo->prepare("
        INSERT INTO character_map_overrides (
            character_id,
            map_id,
            pos_x,
            pos_y,
            glyph,
            expires_at
        )
        VALUES (
            :character_id,
            :map_id,
            :pos_x,
            :pos_y,
            :glyph,
            DATE_ADD(NOW(), INTERVAL 5 MINUTE)
        )
        ON DUPLICATE KEY UPDATE
            glyph = VALUES(glyph),
            expires_at = VALUES(expires_at),
            updated_at = CURRENT_TIMESTAMP
    ");

    $trapOverrideStmt->execute([
        "character_id" => $characterId,
        "map_id" => $currentMapId,
        "pos_x" => $newX,
        "pos_y" => $newY,
        "glyph" => $triggeredTrapGlyph,
    ]);

    $messages[] = (string) ($object["message"] ?? "You trigger a trap.");

    if ($trapDamage > 0) {
        $messages[] = "You take " . $trapDamage . " damage.";
    }

    $tileUpdates[] = [
        "x" => $newX,
        "y" => $newY,
        "glyph" => $triggeredTrapGlyph,
    ];

    $characterUpdates = [
        "current_hp" => $newHp,
        "max_hp" => $maxHp,
    ];

    break;
}

$combatState = null;
if ($encounterDefinition !== null) {
    $movementDecision = $combatService->movementDecision(
        $character,
        $newX,
        $newY,
        true,
        $encounterDefinition,
    );
    if ($movementDecision["start_combat"]) {
        $character["pos_x"] = $newX;
        $character["pos_y"] = $newY;
        $combatState = $combatService->startOrResumeForLockedMovement(
            (int) $_SESSION["user_id"],
            $character,
            $encounterDefinition,
        );
        $messages[] = "The Cave Brute engages.";
    }
}

commitMovementTransaction();

/*
|--------------------------------------------------------------------------
| Send successful movement response
|--------------------------------------------------------------------------
*/
sendJson([
    "success" => true,
    "message" => $messages[0],
    "messages" => $messages,

    "pos_x" => $newX,
    "pos_y" => $newY,

    "tile_name" => $targetTile["name"],
    "tile_updates" => $tileUpdates,
    "character_updates" => $characterUpdates,
    "combat_started" => $combatState !== null,
    "combat" => $combatState,
]);
