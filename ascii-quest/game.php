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
require_once __DIR__ . "/lib/CharacterStats.php";

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
        c.stat_points,
        c.strength,
        c.dexterity,
        c.vitality,
        c.energy,
        c.fate,
        c.current_hp,
        c.current_mana,
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

try {
    $characterStats = CharacterStats::calculate($character);
} catch (InvalidArgumentException $e) {
    error_log(
        "CharacterStats error for character " .
        (int) $character["id"] . ": " . $e->getMessage(),
    );

    exit("Unable to load Champion statistics.");
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

/*
|--------------------------------------------------------------------------
| Resource-bar display values
|--------------------------------------------------------------------------
| CharacterStats remains authoritative for maximum resources. These ratios
| only control the visual fill of the exploration HUD bars.
*/
$currentHp = (int) $character["current_hp"];
$maximumLife = (int) $characterStats["resources"]["max_life"];
$currentMana = (int) $character["current_mana"];
$maximumMana = (int) $characterStats["resources"]["max_mana"];

$hpPercentage = $maximumLife > 0
    ? max(0.0, min(100.0, ($currentHp / $maximumLife) * 100.0))
    : 0.0;
$manaPercentage = $maximumMana > 0
    ? max(0.0, min(100.0, ($currentMana / $maximumMana) * 100.0))
    : 0.0;
$styleVersion = (int) filemtime(__DIR__ . "/css/style.css");
$explorationHudVersion = (int) filemtime(
    __DIR__ . "/js/exploration_hud.js",
);
$gameControlsVersion = (int) filemtime(__DIR__ . "/js/game_controls.js");
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>ASCII Quest - Game</title>
    <link rel="stylesheet" href="css/style.css?v=<?= $styleVersion ?>">
</head>
<body>

<main class="game-page">
    <section class="game-shell">
        <header class="game-header">
            <div class="game-branding">
                <h1>ASCII Quest</h1>
                <p>Exploration</p>
            </div>

            <div class="game-current-area">
                <span>Current Area</span>
                <strong><?= e($mapName) ?></strong>
            </div>

            <nav class="game-nav">
                <a href="character_select.php">Change Character</a>
                <a href="account.php">Main Menu</a>
            </nav>
        </header>

        <section class="game-layout">
            <aside class="hud-panel hud-left-panel" data-tab-group>
                <div class="hud-tabs" role="tablist" aria-label="Champion panels">
                    <button
                        type="button"
                        class="hud-tab is-active"
                        role="tab"
                        aria-selected="true"
                        aria-controls="left-main"
                        data-tab-target="left-main"
                    >Main</button>
                    <button
                        type="button"
                        class="hud-tab"
                        role="tab"
                        aria-selected="false"
                        aria-controls="left-details"
                        data-tab-target="left-details"
                    >Details</button>
                    <button
                        type="button"
                        class="hud-tab"
                        role="tab"
                        aria-selected="false"
                        aria-controls="left-warp"
                        data-tab-target="left-warp"
                    >Warp</button>
                </div>

                <section
                    id="left-main"
                    class="hud-tab-panel"
                    role="tabpanel"
                    data-tab-panel
                >
                    <div class="champion-summary">
                        <div class="sidebar-glyph" aria-label="Champion portrait">
                            <?= e($character["glyph"]) ?>
                        </div>

                        <h2><?= e($character["character_name"]) ?></h2>

                        <p>
                            <?= e($character["class_name"]) ?>
                            · Level <?= e($character["level"]) ?>
                        </p>
                    </div>

                    <div class="champion-resources">
                        <div class="resource-status">
                            <div class="resource-status-label">
                                <span>HP</span>
                                <strong id="playerHp"><?= e($currentHp) ?>/<?= e($maximumLife) ?></strong>
                            </div>
                            <div
                                id="playerHpBar"
                                class="status-bar status-bar-hp"
                                role="progressbar"
                                aria-label="Champion Life"
                                aria-valuemin="0"
                                aria-valuenow="<?= e($currentHp) ?>"
                                aria-valuemax="<?= e($maximumLife) ?>"
                            >
                                <span
                                    id="playerHpFill"
                                    class="status-bar-fill"
                                    style="width: <?= e($hpPercentage) ?>%"
                                ></span>
                            </div>
                        </div>

                        <div class="resource-status">
                            <div class="resource-status-label">
                                <span>Mana</span>
                                <strong id="playerMana"><?= e($currentMana) ?>/<?= e($maximumMana) ?></strong>
                            </div>
                            <div
                                id="playerManaBar"
                                class="status-bar status-bar-mana"
                                role="progressbar"
                                aria-label="Champion Mana"
                                aria-valuemin="0"
                                aria-valuenow="<?= e($currentMana) ?>"
                                aria-valuemax="<?= e($maximumMana) ?>"
                            >
                                <span
                                    id="playerManaFill"
                                    class="status-bar-fill"
                                    style="width: <?= e($manaPercentage) ?>%"
                                ></span>
                            </div>
                        </div>

                        <div class="resource-status">
                            <div class="resource-status-label">
                                <span>XP</span>
                                <strong id="playerXp"><?= e($character["experience"]) ?> XP</strong>
                            </div>
                            <div
                                id="playerXpBar"
                                class="status-bar status-bar-xp"
                                role="progressbar"
                                aria-label="Champion experience progress"
                                aria-valuemin="0"
                                aria-valuenow="0"
                                aria-valuemax="100"
                                aria-valuetext="<?= e($character["experience"]) ?> XP; next-level progress is not defined"
                                data-current-xp="<?= e($character["experience"]) ?>"
                                data-progress-value="0"
                            >
                                <span
                                    id="playerXpFill"
                                    class="status-bar-fill"
                                    style="width: 0%"
                                ></span>
                            </div>
                        </div>
                    </div>

                </section>

                <section
                    id="left-details"
                    class="hud-tab-panel hud-placeholder"
                    role="tabpanel"
                    data-tab-panel
                    hidden
                >
                    <h2>Details</h2>
                    <p>Detailed Champion statistics will appear here.</p>
                </section>

                <section
                    id="left-warp"
                    class="hud-tab-panel hud-placeholder"
                    role="tabpanel"
                    data-tab-panel
                    hidden
                >
                    <h2>Warp</h2>
                    <p>Discovered warp destinations will appear here.</p>
                </section>
            </aside>

            <section class="map-area hud-center-panel">
                <div class="map-title">
                    <?= e($mapName) ?>
                </div>

                <!--
                |--------------------------------------------------------------------------
                | Game Map Container
                |--------------------------------------------------------------------------
                | JavaScript draws the existing visible map viewport inside this div.
                -->
                <div
                    id="gameMap"
                    class="game-map"
                    style="
                        --viewport-width: <?= e($viewportWidth) ?>;
                        --viewport-height: <?= e($viewportHeight) ?>;
                    "
                ></div>

                <div class="game-help">
                    <span>
                        Position:
                        <strong id="playerPosition"><?= e($playerX) ?>, <?= e($playerY) ?></strong>
                    </span>
                    <span>
                        Viewport: <?= e($viewportWidth) ?> x <?= e($viewportHeight) ?>
                    </span>
                    <span>
                        Full map: <?= e($mapWidth) ?> x <?= e($mapHeight) ?>
                    </span>
                </div>

                <section class="hud-bottom-panel" data-tab-group>
                    <div class="hud-tabs hud-bottom-tabs" role="tablist" aria-label="Exploration information panels">
                        <button
                            type="button"
                            class="hud-tab is-active"
                            role="tab"
                            aria-selected="true"
                            aria-controls="bottom-information"
                            data-tab-target="bottom-information"
                        >Information</button>
                        <button
                            type="button"
                            class="hud-tab"
                            role="tab"
                            aria-selected="false"
                            aria-controls="bottom-server"
                            data-tab-target="bottom-server"
                        >Server Info</button>
                        <button
                            type="button"
                            class="hud-tab"
                            role="tab"
                            aria-selected="false"
                            aria-controls="bottom-chat"
                            data-tab-target="bottom-chat"
                        >Chat</button>
                    </div>

                    <section
                        id="bottom-information"
                        class="hud-tab-panel"
                        role="tabpanel"
                        data-tab-panel
                    >
                        <div class="game-log">
                            <div class="game-log-title">Exploration Information</div>
                            <div id="gameLogMessages" class="game-log-messages">
                                <!-- JavaScript adds exploration messages here -->
                            </div>
                        </div>
                    </section>

                    <section
                        id="bottom-server"
                        class="hud-tab-panel hud-placeholder hud-bottom-placeholder"
                        role="tabpanel"
                        data-tab-panel
                        hidden
                    >
                        <p>Server information will appear here in a later milestone.</p>
                    </section>

                    <section
                        id="bottom-chat"
                        class="hud-tab-panel hud-placeholder hud-bottom-placeholder"
                        role="tabpanel"
                        data-tab-panel
                        hidden
                    >
                        <p>Chat will be implemented in a later milestone.</p>
                    </section>
                </section>
            </section>

            <aside class="hud-panel hud-right-panel" data-tab-group>
                <div class="hud-tabs" role="tablist" aria-label="Item and skill panels">
                    <button
                        type="button"
                        class="hud-tab is-active"
                        role="tab"
                        aria-selected="true"
                        aria-controls="right-items"
                        data-tab-target="right-items"
                    >Items</button>
                    <button
                        type="button"
                        class="hud-tab"
                        role="tab"
                        aria-selected="false"
                        aria-controls="right-skills"
                        data-tab-target="right-skills"
                    >Skill Tree</button>
                    <button
                        type="button"
                        class="hud-tab"
                        role="tab"
                        aria-selected="false"
                        aria-controls="right-passives"
                        data-tab-target="right-passives"
                    >Passive Tree</button>
                </div>

                <section
                    id="right-items"
                    class="hud-tab-panel"
                    role="tabpanel"
                    data-tab-panel
                >
                    <section class="hud-item-section">
                        <h2>Equipment</h2>
                        <div class="paper-doll" aria-label="Empty equipment paper doll">
                            <div class="equipment-slot equipment-slot-helm" data-equipment-slot="helm">
                                <span class="equipment-slot-glyph" aria-hidden="true">◇</span>
                                <span>Helm</span>
                            </div>
                            <div class="equipment-slot equipment-slot-gloves" data-equipment-slot="gloves">
                                <span class="equipment-slot-glyph" aria-hidden="true">◇</span>
                                <span>Gloves</span>
                            </div>
                            <div class="equipment-slot equipment-slot-chest" data-equipment-slot="chest">
                                <span class="equipment-slot-glyph" aria-hidden="true">◇</span>
                                <span>Chest</span>
                            </div>
                            <div class="equipment-slot equipment-slot-ring" data-equipment-slot="ring">
                                <span class="equipment-slot-glyph" aria-hidden="true">◇</span>
                                <span>Ring</span>
                            </div>
                            <div class="equipment-slot equipment-slot-weapon" data-equipment-slot="weapon">
                                <span class="equipment-slot-glyph" aria-hidden="true">╱</span>
                                <span>Weapon</span>
                            </div>
                            <div class="paper-doll-body" aria-label="Champion body">
                                <pre aria-hidden="true"> O
/|\
/ \</pre>
                                <span>Body</span>
                            </div>
                            <div class="equipment-slot equipment-slot-offhand" data-equipment-slot="off-hand">
                                <span class="equipment-slot-glyph" aria-hidden="true">▱</span>
                                <span>Off-Hand</span>
                            </div>
                            <div class="equipment-slot equipment-slot-amulet" data-equipment-slot="amulet">
                                <span class="equipment-slot-glyph" aria-hidden="true">◇</span>
                                <span>Amulet</span>
                            </div>
                            <div class="equipment-slot equipment-slot-belt" data-equipment-slot="belt">
                                <span class="equipment-slot-glyph" aria-hidden="true">◇</span>
                                <span>Belt</span>
                            </div>
                            <div class="equipment-slot equipment-slot-charm" data-equipment-slot="charm">
                                <span class="equipment-slot-glyph" aria-hidden="true">◇</span>
                                <span>Charm</span>
                            </div>
                            <div class="equipment-slot equipment-slot-boots" data-equipment-slot="boots">
                                <span class="equipment-slot-glyph" aria-hidden="true">◇</span>
                                <span>Boots</span>
                            </div>
                        </div>

                        <div class="equipment-gold">
                            <span>Gold</span>
                            <strong id="playerGold"><?= e($character["gold"]) ?></strong>
                        </div>
                    </section>

                    <section class="hud-item-section">
                        <h2>Loadout</h2>
                        <div class="loadout-grid">
                            <?php foreach (["Skill 1", "Skill 2", "Skill 3", "Ultimate", "Potion"] as $slot): ?>
                                <div class="loadout-slot">
                                    <span><?= e($slot) ?></span>
                                    <strong>Empty</strong>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </section>

                    <section class="hud-item-section">
                        <h2>Inventory</h2>
                        <p class="hud-section-note">Visual shell only</p>
                        <div class="inventory-grid" aria-label="Empty inventory placeholders">
                            <?php for ($slot = 1; $slot <= 20; $slot++): ?>
                                <div
                                    class="inventory-slot"
                                    aria-label="Empty inventory slot <?= e($slot) ?>"
                                ></div>
                            <?php endfor; ?>
                        </div>
                    </section>
                </section>

                <section
                    id="right-skills"
                    class="hud-tab-panel hud-placeholder"
                    role="tabpanel"
                    data-tab-panel
                    hidden
                >
                    <h2>Skill Tree</h2>
                    <p>Skill Tree will be implemented in a later milestone.</p>
                </section>

                <section
                    id="right-passives"
                    class="hud-tab-panel hud-placeholder"
                    role="tabpanel"
                    data-tab-panel
                    hidden
                >
                    <h2>Passive Tree</h2>
                    <p>Passive Tree will be implemented in a later milestone.</p>
                </section>
            </aside>
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

<script src="js/exploration_hud.js?v=<?= $explorationHudVersion ?>"></script>
<script src="js/game_controls.js?v=<?= $gameControlsVersion ?>"></script>

</body>
</html>
