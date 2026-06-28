<?php
declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| ASCII Quest - Game Screen
|--------------------------------------------------------------------------
| Purpose:
|   Shows the selected character inside the current map.
|
| This page:
|   1. Checks login/session
|   2. Loads selected character
|   3. Loads map JSON file
|   4. Applies character-specific map overrides
|   5. Sends map/player data to JavaScript
|   6. JavaScript renders smooth map viewport
*/

session_start();

require_once __DIR__ . "/db.php";
require_once __DIR__ . "/map_loader.php";

$pdo = getDb();

/*
|--------------------------------------------------------------------------
| Security: user must be logged in
|--------------------------------------------------------------------------
*/
if (!isset($_SESSION["user_id"])) {
    header("Location: login.php");
    exit();
}

/*
|--------------------------------------------------------------------------
| Security: character must be selected
|--------------------------------------------------------------------------
*/
if (!isset($_SESSION["character_id"])) {
    header("Location: character_select.php");
    exit();
}

/*
|--------------------------------------------------------------------------
| Helper: escape HTML output
|--------------------------------------------------------------------------
*/
function e(mixed $value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES, "UTF-8");
}

/*
|--------------------------------------------------------------------------
| Load selected character + class + current map file
|--------------------------------------------------------------------------
*/
$stmt = $pdo->prepare("
    SELECT
        c.id,
        c.character_name,
        c.level,
        c.experience,
        c.current_hp,
        c.max_hp,
        c.current_mana,
        c.max_mana,
        c.attack,
        c.defense,
        c.gold,
        c.current_map_id,
        c.pos_x,
        c.pos_y,

        cc.class_name,
        cc.glyph,

        gm.map_name,
        gm.map_file
    FROM characters c
    INNER JOIN character_classes cc
        ON cc.id = c.class_id
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
    unset($_SESSION["character_id"]);
    header("Location: character_select.php");
    exit();
}

/*
|--------------------------------------------------------------------------
| Load tile type definitions
|--------------------------------------------------------------------------
| glyph         = internal map code, for example #
| display_glyph = visual Unicode symbol, for example ▓
*/
$tileStmt = $pdo->query("
    SELECT
        glyph,
        display_glyph,
        css_class,
        name
    FROM tile_types
");

$tileTypes = [];

foreach ($tileStmt->fetchAll() as $tileType) {
    $tileTypes[$tileType["glyph"]] = [
        "display_glyph" => $tileType["display_glyph"],
        "css_class" => $tileType["css_class"],
        "name" => $tileType["name"],
    ];
}

/*
|--------------------------------------------------------------------------
| Load map from JSON file
|--------------------------------------------------------------------------
| Database stores only file name.
| Example:
|   forgotten_cave.json
*/
try {
    $mapData = loadMapFromFile((string) $character["map_file"]);
} catch (RuntimeException $e) {
    error_log("Map loading error: " . $e->getMessage());

    http_response_code(500);
    exit("Map loading failed.");
}

/*
|--------------------------------------------------------------------------
| Map information from JSON
|--------------------------------------------------------------------------
*/
$mapWidth = (int) $mapData["width"];
$mapHeight = (int) $mapData["height"];
$mapRows = $mapData["layout"];
$mapName = (string) $mapData["map_name"];

/*
|--------------------------------------------------------------------------
| Clean expired temporary map overrides
|--------------------------------------------------------------------------
| Server/database time is used here through MariaDB NOW().
|
| Example:
|   triggered trap v expires after 5 minutes
|
| Permanent changes like doors/chests have expires_at = NULL,
| so they are not deleted.
*/
$cleanupStmt = $pdo->prepare("
    DELETE FROM character_map_overrides
    WHERE character_id = :character_id
      AND map_id = :map_id
      AND expires_at IS NOT NULL
      AND expires_at <= NOW()
");

$cleanupStmt->execute([
    "character_id" => $_SESSION["character_id"],
    "map_id" => $character["current_map_id"],
]);

/*
|--------------------------------------------------------------------------
| Apply active character map overrides
|--------------------------------------------------------------------------
| Permanent overrides:
|   expires_at IS NULL
|
| Temporary overrides:
|   expires_at > NOW()
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
    "character_id" => $_SESSION["character_id"],
    "map_id" => $character["current_map_id"],
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
| Player position
|--------------------------------------------------------------------------
*/
$playerX = (int) $character["pos_x"];
$playerY = (int) $character["pos_y"];

/*
|--------------------------------------------------------------------------
| Viewport size
|--------------------------------------------------------------------------
| Full map can be bigger than screen.
| Viewport is visible area around player.
*/
$viewportWidth = 21;
$viewportHeight = 15;
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>ASCII Quest - Game</title>
    <link rel="stylesheet" href="css/style.css">
</head>
<body>

<main class="game-page">
    <section class="game-shell">
        <header class="game-header">
            <div>
                <h1>ASCII Quest</h1>
                <p><?= e($mapName) ?></p>
            </div>

            <nav class="game-nav">
                <a href="character_select.php">Change Character</a>
                <a href="account.php">Main Menu</a>
            </nav>
        </header>

        <section class="game-layout">
            <aside class="game-sidebar">
                <div class="mini-card">
                    <div class="sidebar-glyph">
                        <?= e($character["glyph"]) ?>
                    </div>

                    <h2><?= e($character["character_name"]) ?></h2>

                    <p>
                        <?= e($character["class_name"]) ?>
                        · Level <?= e($character["level"]) ?>
                    </p>
                </div>

                <div class="mini-stats">
                    <div>
                        <span>HP</span>
                        <strong id="playerHp">
                            <?= e($character["current_hp"]) ?>/<?= e(
    $character["max_hp"],
) ?>
                        </strong>
                    </div>

                    <div>
                        <span>Mana</span>
                        <strong><?= e($character["current_mana"]) ?>/<?= e(
    $character["max_mana"],
) ?></strong>
                    </div>

                    <div>
                        <span>Attack</span>
                        <strong><?= e($character["attack"]) ?></strong>
                    </div>

                    <div>
                        <span>Defense</span>
                        <strong><?= e($character["defense"]) ?></strong>
                    </div>

                    <div>
                        <span>XP</span>
                        <strong><?= e($character["experience"]) ?></strong>
                    </div>

                    <div>
                        <span>Gold</span>
                        <strong id="playerGold"><?= e(
                            $character["gold"],
                        ) ?></strong>
                    </div>

                    <div>
                        <span>Pos</span>
                        <strong id="playerPosition">
                            <?= e($playerX) ?>, <?= e($playerY) ?>
                        </strong>
                    </div>
                </div>
            </aside>

            <section class="map-area">
                <div class="map-title">
                    <?= e($mapName) ?>
                </div>

                <!--
                |--------------------------------------------------------------------------
                | Game Map Container
                |--------------------------------------------------------------------------
                | JavaScript draws the visible map viewport inside this div.
                -->
                <div
                    id="gameMap"
                    class="game-map"
                    style="
                        --viewport-width: <?= e($viewportWidth) ?>;
                        --viewport-height: <?= e($viewportHeight) ?>;
                    "
                ></div>

                <!--
                |--------------------------------------------------------------------------
                | Message Log
                |--------------------------------------------------------------------------
                | JavaScript adds game messages here.
                -->
                <div class="game-log">
                    <div class="game-log-title">Message Log</div>

                    <div id="gameLogMessages" class="game-log-messages">
                        <!-- JavaScript adds messages here -->
                    </div>
                </div>

                <div class="game-help">
                    Viewport: <?= e($viewportWidth) ?> x <?= e(
     $viewportHeight,
 ) ?>
                    · Full map: <?= e($mapWidth) ?> x <?= e($mapHeight) ?>
                </div>
            </section>
        </section>
    </section>
</main>

<script>
/*
|--------------------------------------------------------------------------
| ASCII Quest - Initial Game State
|--------------------------------------------------------------------------
| PHP prepares map/player/tile data.
| JavaScript file uses this object to render and control the game.
*/
window.ASCII_QUEST_STATE = {
    mapRows: <?= json_encode($mapRows, JSON_UNESCAPED_UNICODE) ?>,

    mapWidth: <?= (int) $mapWidth ?>,
    mapHeight: <?= (int) $mapHeight ?>,

    viewportWidth: <?= (int) $viewportWidth ?>,
    viewportHeight: <?= (int) $viewportHeight ?>,

    playerX: <?= (int) $playerX ?>,
    playerY: <?= (int) $playerY ?>,

    playerGlyph: <?= json_encode(
        (string) $character["glyph"],
        JSON_UNESCAPED_UNICODE,
    ) ?>,
    playerName: <?= json_encode(
        (string) $character["character_name"],
        JSON_UNESCAPED_UNICODE,
    ) ?>,

    tileTypes: <?= json_encode($tileTypes, JSON_UNESCAPED_UNICODE) ?>,

    initialMessages: [
        <?= json_encode(
            "You enter " . $mapName . ".",
            JSON_UNESCAPED_UNICODE,
        ) ?>,
        "Use Arrow Keys, W A S D, mouse click, or E to interact."
    ]
};
</script>

<script src="js/game_controls.js"></script>

</body>
</html>
