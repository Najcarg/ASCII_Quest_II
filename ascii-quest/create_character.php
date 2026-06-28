<?php
declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| ASCII Quest - Character Creation
|--------------------------------------------------------------------------
| Purpose:
|   Lets a logged-in user create a new character.
|
| What this page does:
|   1. Loads available classes from character_classes table
|   2. Shows class preview with glyph, description, stats and growth
|   3. Creates character using selected class base stats
|   4. Places new character on the starting map
|
| Important:
|   Class stats come from database, not hardcoded PHP.
|   Starting position comes from game_maps table.
*/

session_start();

require_once __DIR__ . "/db.php";

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
| Helper: escape text before showing in HTML
|--------------------------------------------------------------------------
*/
function e(mixed $value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES, "UTF-8");
}

$message = "";
$messageType = "";

/*
|--------------------------------------------------------------------------
| Load all character classes
|--------------------------------------------------------------------------
| These classes appear in the dropdown and preview panel.
*/
$stmt = $pdo->query("
    SELECT
        id,
        class_name,
        glyph,
        ascii_fallback,
        description,

        base_hp,
        base_mana,
        base_attack,
        base_defense,
        base_crit_damage,
        base_crit_chance,
        base_attack_count,
        base_dodge,
        base_heal_per_step,
        base_life_on_hit,
        base_mana_per_min,
        base_mana_on_hit,
        base_bonus_xp_on_kill,
        base_gold_find,

        hp_per_level,
        mana_per_level,
        attack_per_level,
        defense_per_level,
        dodge_per_level
    FROM character_classes
    ORDER BY id
");

$classes = $stmt->fetchAll();

/*
|--------------------------------------------------------------------------
| Handle form submit
|--------------------------------------------------------------------------
*/
if ($_SERVER["REQUEST_METHOD"] === "POST") {
    $characterName = trim($_POST["character_name"] ?? "");
    $classId = (int) ($_POST["class_id"] ?? 0);

    /*
    |--------------------------------------------------------------------------
    | Validate character name and selected class
    |--------------------------------------------------------------------------
    */
    if ($characterName === "") {
        $message = "Character name is required.";
        $messageType = "error";
    } elseif (strlen($characterName) < 3 || strlen($characterName) > 50) {
        $message = "Character name must be between 3 and 50 characters.";
        $messageType = "error";
    } elseif ($classId <= 0) {
        $message = "Please select a class.";
        $messageType = "error";
    } else {
        /*
        |--------------------------------------------------------------------------
        | Load selected class
        |--------------------------------------------------------------------------
        | We do not trust the class_id from browser.
        | We check that it really exists in the database.
        */
        $classStmt = $pdo->prepare("
            SELECT *
            FROM character_classes
            WHERE id = :id
            LIMIT 1
        ");

        $classStmt->execute([
            "id" => $classId,
        ]);

        $selectedClass = $classStmt->fetch();

        if (!$selectedClass) {
            $message = "Selected class does not exist.";
            $messageType = "error";
        } else {
            /*
            |--------------------------------------------------------------------------
            | Load starting map
            |--------------------------------------------------------------------------
            | Every new character must have:
            |   current_map_id
            |   pos_x
            |   pos_y
            |
            | Without this, game.php cannot load the dungeon.
            */
            $mapStmt = $pdo->prepare("
                SELECT id, start_x, start_y
                FROM game_maps
                WHERE map_key = :map_key
                LIMIT 1
            ");

            $mapStmt->execute([
                "map_key" => "test_cave_01",
            ]);

            $startingMap = $mapStmt->fetch();

            if (!$startingMap) {
                $message = "Starting map does not exist.";
                $messageType = "error";
            } else {
                try {
                    /*
                    |--------------------------------------------------------------------------
                    | Create new character
                    |--------------------------------------------------------------------------
                    | Base stats are copied from selected class.
                    |
                    | Later:
                    |   Equipment bonuses should NOT be saved here.
                    |   They should be calculated separately.
                    */
                    $insertStmt = $pdo->prepare("
                        INSERT INTO characters (
                            user_id,
                            class_id,
                            character_name,

                            level,
                            experience,

                            max_hp,
                            current_hp,

                            max_mana,
                            current_mana,

                            attack,
                            defense,

                            gold,

                            current_map_id,
                            pos_x,
                            pos_y
                        )
                        VALUES (
                            :user_id,
                            :class_id,
                            :character_name,

                            1,
                            0,

                            :max_hp,
                            :current_hp,

                            :max_mana,
                            :current_mana,

                            :attack,
                            :defense,

                            0,

                            :current_map_id,
                            :pos_x,
                            :pos_y
                        )
                    ");

                    $insertStmt->execute([
                        "user_id" => $_SESSION["user_id"],
                        "class_id" => $selectedClass["id"],
                        "character_name" => $characterName,

                        "max_hp" => $selectedClass["base_hp"],
                        "current_hp" => $selectedClass["base_hp"],

                        "max_mana" => $selectedClass["base_mana"],
                        "current_mana" => $selectedClass["base_mana"],

                        "attack" => $selectedClass["base_attack"],
                        "defense" => $selectedClass["base_defense"],

                        "current_map_id" => $startingMap["id"],
                        "pos_x" => $startingMap["start_x"],
                        "pos_y" => $startingMap["start_y"],
                    ]);

                    /*
                    |--------------------------------------------------------------------------
                    | After creating character, go to character selection
                    |--------------------------------------------------------------------------
                    */
                    header("Location: character_select.php");
                    exit();
                } catch (PDOException $e) {
                    /*
                    |--------------------------------------------------------------------------
                    | Duplicate character name
                    |--------------------------------------------------------------------------
                    | This happens if user already has a character with same name.
                    */
                    if ($e->getCode() === "23000") {
                        $message =
                            "You already have a character with this name.";
                        $messageType = "error";
                    } else {
                        error_log(
                            "Create character error: " . $e->getMessage(),
                        );

                        $message = "Something went wrong. Please try again.";
                        $messageType = "error";
                    }
                }
            }
        }
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>ASCII Quest - Character Creation</title>
    <link rel="stylesheet" href="css/style.css">
</head>
<body>

<main class="page">
    <section class="panel wide-panel">
        <div class="logo">
            <h1>ASCII Quest</h1>
            <p>Choose the soul that will descend.</p>
        </div>

        <h2>Character Creation</h2>

        <?php if ($message !== ""): ?>
            <div class="message <?= e($messageType) ?>">
                <?= e($message) ?>
            </div>
        <?php endif; ?>

        <form method="post" action="create_character.php">
            <!--
            |--------------------------------------------------------------------------
            | Character name
            |--------------------------------------------------------------------------
            -->
            <label for="character_name">Character Name</label>
            <input
                type="text"
                id="character_name"
                name="character_name"
                maxlength="50"
                required
                value="<?= e($_POST["character_name"] ?? "") ?>"
            >

            <!--
            |--------------------------------------------------------------------------
            | Class selector
            |--------------------------------------------------------------------------
            | Each option includes data-* attributes.
            | JavaScript uses these to update the preview panel.
            -->
            <label for="class_id">Class</label>
            <select id="class_id" name="class_id" required>
                <option value="" selected>-- Select Class --</option>

                <?php foreach ($classes as $class): ?>
                    <option
                        value="<?= e($class["id"]) ?>"

                        data-name="<?= e($class["class_name"]) ?>"
                        data-glyph="<?= e($class["glyph"]) ?>"
                        data-description="<?= e($class["description"]) ?>"

                        data-hp="<?= e($class["base_hp"]) ?>"
                        data-mana="<?= e($class["base_mana"]) ?>"
                        data-attack="<?= e($class["base_attack"]) ?>"
                        data-defense="<?= e($class["base_defense"]) ?>"

                        data-crit-damage="<?= e($class["base_crit_damage"]) ?>"
                        data-crit-chance="<?= e($class["base_crit_chance"]) ?>"
                        data-dodge="<?= e($class["base_dodge"]) ?>"

                        data-hp-level="<?= e($class["hp_per_level"]) ?>"
                        data-mana-level="<?= e($class["mana_per_level"]) ?>"
                        data-attack-level="<?= e($class["attack_per_level"]) ?>"
                        data-defense-level="<?= e(
                            $class["defense_per_level"],
                        ) ?>"
                        data-dodge-level="<?= e($class["dodge_per_level"]) ?>"
                    >
                        <?= e($class["glyph"]) ?> <?= e($class["class_name"]) ?>
                    </option>
                <?php endforeach; ?>
            </select>

            <!--
            |--------------------------------------------------------------------------
            | Class preview
            |--------------------------------------------------------------------------
            | Starts blank.
            | When player selects a class, JavaScript fills the glyph/stats.
            -->
            <div class="class-preview">
                <div class="glyph-frame">
                    <div id="previewGlyph" class="big-glyph"></div>
                </div>

                <h3 id="previewName">No class selected</h3>

                <p id="previewDescription" class="class-description">
                    Select a class to see its description.
                </p>

                <div class="stats-grid">
                    <div>
                        <span>HP</span>
                        <strong id="statHp"></strong>
                    </div>

                    <div>
                        <span>Mana</span>
                        <strong id="statMana"></strong>
                    </div>

                    <div>
                        <span>Attack</span>
                        <strong id="statAttack"></strong>
                    </div>

                    <div>
                        <span>Defense</span>
                        <strong id="statDefense"></strong>
                    </div>

                    <div>
                        <span>Crit Damage</span>
                        <strong id="statCritDamage"></strong>
                    </div>

                    <div>
                        <span>Crit Chance</span>
                        <strong id="statCritChance"></strong>
                    </div>

                    <div>
                        <span>Dodge</span>
                        <strong id="statDodge"></strong>
                    </div>
                </div>

                <div class="growth-box">
                    <p>Growth per level</p>

                    <div class="stats-grid">
                        <div>
                            <span>HP</span>
                            <strong id="growthHp"></strong>
                        </div>

                        <div>
                            <span>Mana</span>
                            <strong id="growthMana"></strong>
                        </div>

                        <div>
                            <span>Attack</span>
                            <strong id="growthAttack"></strong>
                        </div>

                        <div>
                            <span>Defense</span>
                            <strong id="growthDefense"></strong>
                        </div>

                        <div>
                            <span>Dodge</span>
                            <strong id="growthDodge"></strong>
                        </div>
                    </div>
                </div>
            </div>

            <button type="submit">Create Character</button>
        </form>

        <p class="small-text">
            <a href="account.php">Back to Main Menu</a>
        </p>
    </section>
</main>

<script>
/*
|--------------------------------------------------------------------------
| ASCII Quest - Character Class Preview
|--------------------------------------------------------------------------
| Purpose:
|   Updates big glyph, description, base stats and growth stats
|   when player selects a class from dropdown.
*/

const classSelect = document.getElementById("class_id");

const previewGlyph = document.getElementById("previewGlyph");
const previewName = document.getElementById("previewName");
const previewDescription = document.getElementById("previewDescription");

const statHp = document.getElementById("statHp");
const statMana = document.getElementById("statMana");
const statAttack = document.getElementById("statAttack");
const statDefense = document.getElementById("statDefense");
const statCritDamage = document.getElementById("statCritDamage");
const statCritChance = document.getElementById("statCritChance");
const statDodge = document.getElementById("statDodge");

const growthHp = document.getElementById("growthHp");
const growthMana = document.getElementById("growthMana");
const growthAttack = document.getElementById("growthAttack");
const growthDefense = document.getElementById("growthDefense");
const growthDodge = document.getElementById("growthDodge");

/*
|--------------------------------------------------------------------------
| Helper: safely set text
|--------------------------------------------------------------------------
*/
function setText(element, value) {
    element.textContent = value || "";
}

/*
|--------------------------------------------------------------------------
| Clear preview when no class is selected
|--------------------------------------------------------------------------
*/
function clearClassPreview() {
    setText(previewGlyph, "");
    setText(previewName, "No class selected");
    setText(previewDescription, "Select a class to see its description.");

    const statElements = [
        statHp,
        statMana,
        statAttack,
        statDefense,
        statCritDamage,
        statCritChance,
        statDodge,
        growthHp,
        growthMana,
        growthAttack,
        growthDefense,
        growthDodge
    ];

    statElements.forEach(function (element) {
        setText(element, "");
    });
}

/*
|--------------------------------------------------------------------------
| Update preview when class is selected
|--------------------------------------------------------------------------
*/
function updateClassPreview() {
    const selected = classSelect.options[classSelect.selectedIndex];

    if (!selected || !selected.value) {
        clearClassPreview();
        return;
    }

    setText(previewGlyph, selected.dataset.glyph);
    setText(previewName, selected.dataset.name);
    setText(previewDescription, selected.dataset.description);

    setText(statHp, selected.dataset.hp);
    setText(statMana, selected.dataset.mana);
    setText(statAttack, selected.dataset.attack);
    setText(statDefense, selected.dataset.defense);

    setText(statCritDamage, selected.dataset.critDamage);
    setText(statCritChance, selected.dataset.critChance + "%");
    setText(statDodge, selected.dataset.dodge + "%");

    setText(growthHp, "+" + selected.dataset.hpLevel);
    setText(growthMana, "+" + selected.dataset.manaLevel);
    setText(growthAttack, "+" + selected.dataset.attackLevel);
    setText(growthDefense, "+" + selected.dataset.defenseLevel);
    setText(growthDodge, "+" + selected.dataset.dodgeLevel);
}

/*
|--------------------------------------------------------------------------
| Event listener
|--------------------------------------------------------------------------
*/
classSelect.addEventListener("change", updateClassPreview);

/*
|--------------------------------------------------------------------------
| First page load
|--------------------------------------------------------------------------
| Keep preview blank unless user selects a class.
*/
clearClassPreview();
</script>

</body>
</html>
