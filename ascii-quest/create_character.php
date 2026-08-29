<?php
declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| ASCII Quest - Character Creation
|--------------------------------------------------------------------------
| Purpose:
|   Lets a logged-in user create a new Champion.
|
| Stat authority:
|   The browser chooses only a class and character name.
|   Starting main stats are reconstructed on the server from the class
|   bonuses, then CharacterStats calculates all derived values.
*/

session_start();

require_once __DIR__ . "/db.php";
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
| Load character classes and build trusted preview values
|--------------------------------------------------------------------------
| Class rows store only starting bonuses. Final preview values are calculated
| by CharacterStats so the UI uses the same rules as character creation.
*/
$stmt = $pdo->query("
    SELECT
        id,
        class_name,
        glyph,
        ascii_fallback,
        description,
        start_strength_bonus,
        start_dexterity_bonus,
        start_vitality_bonus,
        start_energy_bonus,
        start_fate_bonus
    FROM character_classes
    ORDER BY id
");

$classes = $stmt->fetchAll();
$classPreviews = [];

try {
    foreach ($classes as $class) {
        $mainStats = CharacterStats::startingMainStats($class);
        $calculatedStats = CharacterStats::calculate($mainStats);

        $classPreviews[(int) $class["id"]] = [
            "main" => $mainStats,
            "stats" => $calculatedStats,
        ];
    }
} catch (InvalidArgumentException $e) {
    error_log("Character class stat error: " . $e->getMessage());
    $classes = [];
    $classPreviews = [];
    $message = "Unable to load Champion statistics.";
    $messageType = "error";
}

/*
|--------------------------------------------------------------------------
| Handle form submit
|--------------------------------------------------------------------------
*/
if ($_SERVER["REQUEST_METHOD"] === "POST") {
    $characterName = trim($_POST["character_name"] ?? "");
    $classId = (int) ($_POST["class_id"] ?? 0);

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
        |------------------------------------------------------------------
        | Reload the selected class from MariaDB
        |------------------------------------------------------------------
        | Never trust starting stats supplied by the browser. The form sends
        | only class_id; all class bonuses come from the database again here.
        */
        $classStmt = $pdo->prepare("
            SELECT
                id,
                class_name,
                start_strength_bonus,
                start_dexterity_bonus,
                start_vitality_bonus,
                start_energy_bonus,
                start_fate_bonus
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
            |------------------------------------------------------------------
            | Load starting map
            |------------------------------------------------------------------
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
                    $mainStats = CharacterStats::startingMainStats($selectedClass);
                    $calculatedStats = CharacterStats::calculate($mainStats);
                    $maxLife = $calculatedStats["resources"]["max_life"];
                    $maxMana = $calculatedStats["resources"]["max_mana"];

                    $pdo->beginTransaction();

                    $insertStmt = $pdo->prepare("
                        INSERT INTO characters (
                            user_id,
                            class_id,
                            character_name,
                            level,
                            experience,
                            stat_points,
                            strength,
                            dexterity,
                            vitality,
                            energy,
                            fate,
                            current_hp,
                            current_mana,
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
                            0,
                            :strength,
                            :dexterity,
                            :vitality,
                            :energy,
                            :fate,
                            :current_hp,
                            :current_mana,
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
                        "strength" => $mainStats["strength"],
                        "dexterity" => $mainStats["dexterity"],
                        "vitality" => $mainStats["vitality"],
                        "energy" => $mainStats["energy"],
                        "fate" => $mainStats["fate"],
                        "current_hp" => $maxLife,
                        "current_mana" => $maxMana,
                        "current_map_id" => $startingMap["id"],
                        "pos_x" => $startingMap["start_x"],
                        "pos_y" => $startingMap["start_y"],
                    ]);

                    $pdo->commit();

                    header("Location: character_select.php");
                    exit();
                } catch (InvalidArgumentException $e) {
                    if ($pdo->inTransaction()) {
                        $pdo->rollBack();
                    }

                    error_log("Character class stat error: " . $e->getMessage());
                    $message = "Unable to load Champion statistics.";
                    $messageType = "error";
                } catch (Throwable $e) {
                    if ($pdo->inTransaction()) {
                        $pdo->rollBack();
                    }

                    if ($e instanceof PDOException && $e->getCode() === "23000") {
                        $message = "You already have a character with this name.";
                        $messageType = "error";
                    } else {
                        error_log("Create character error: " . $e->getMessage());
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
            <label for="character_name">Character Name</label>
            <input
                type="text"
                id="character_name"
                name="character_name"
                maxlength="50"
                required
                value="<?= e($_POST["character_name"] ?? "") ?>"
            >

            <label for="class_id">Class</label>
            <select id="class_id" name="class_id" required>
                <option value="">-- Select Class --</option>

                <?php foreach ($classes as $class): ?>
                    <?php $preview = $classPreviews[(int) $class["id"]]; ?>
                    <option
                        value="<?= e($class["id"]) ?>"
                        <?= (int) ($_POST["class_id"] ?? 0) === (int) $class["id"] ? "selected" : "" ?>

                        data-name="<?= e($class["class_name"]) ?>"
                        data-glyph="<?= e($class["glyph"]) ?>"
                        data-description="<?= e($class["description"]) ?>"

                        data-strength="<?= e($preview["main"]["strength"]) ?>"
                        data-dexterity="<?= e($preview["main"]["dexterity"]) ?>"
                        data-vitality="<?= e($preview["main"]["vitality"]) ?>"
                        data-energy="<?= e($preview["main"]["energy"]) ?>"
                        data-fate="<?= e($preview["main"]["fate"]) ?>"
                        data-life="<?= e($preview["stats"]["resources"]["max_life"]) ?>"
                        data-mana="<?= e($preview["stats"]["resources"]["max_mana"]) ?>"
                        data-melee-damage="<?= e($preview["stats"]["combat"]["melee_damage"]) ?>"
                        data-toughness="<?= e($preview["stats"]["combat"]["toughness"]) ?>"
                        data-spell-power="<?= e($preview["stats"]["combat"]["spell_power"]) ?>"
                    >
                        <?= e($class["glyph"]) ?> <?= e($class["class_name"]) ?>
                    </option>
                <?php endforeach; ?>
            </select>

            <div class="class-preview">
                <div class="glyph-frame">
                    <div id="previewGlyph" class="big-glyph"></div>
                </div>

                <h3 id="previewName">No class selected</h3>

                <p id="previewDescription" class="class-description">
                    Select a class to see its description.
                </p>

                <div class="stats-grid">
                    <div><span>Strength</span><strong id="statStrength"></strong></div>
                    <div><span>Dexterity</span><strong id="statDexterity"></strong></div>
                    <div><span>Vitality</span><strong id="statVitality"></strong></div>
                    <div><span>Energy</span><strong id="statEnergy"></strong></div>
                    <div><span>Fate</span><strong id="statFate"></strong></div>
                    <div><span>Life</span><strong id="statLife"></strong></div>
                    <div><span>Mana</span><strong id="statMana"></strong></div>
                    <div><span>Melee Damage</span><strong id="statMeleeDamage"></strong></div>
                    <div><span>Toughness</span><strong id="statToughness"></strong></div>
                    <div><span>Spell Power</span><strong id="statSpellPower"></strong></div>
                </div>

                <div class="growth-box">
                    <p>Each level after Level 1 grants 5 stat points.</p>
                </div>
            </div>

            <button type="submit" <?= $classes === [] ? "disabled" : "" ?>>Create Character</button>
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
| The browser only displays values calculated by PHP. No game-stat formula
| is duplicated in JavaScript.
*/

const classSelect = document.getElementById("class_id");

const previewGlyph = document.getElementById("previewGlyph");
const previewName = document.getElementById("previewName");
const previewDescription = document.getElementById("previewDescription");

const statStrength = document.getElementById("statStrength");
const statDexterity = document.getElementById("statDexterity");
const statVitality = document.getElementById("statVitality");
const statEnergy = document.getElementById("statEnergy");
const statFate = document.getElementById("statFate");
const statLife = document.getElementById("statLife");
const statMana = document.getElementById("statMana");
const statMeleeDamage = document.getElementById("statMeleeDamage");
const statToughness = document.getElementById("statToughness");
const statSpellPower = document.getElementById("statSpellPower");

function setText(element, value) {
    element.textContent = value || "";
}

function clearClassPreview() {
    setText(previewGlyph, "");
    setText(previewName, "No class selected");
    setText(previewDescription, "Select a class to see its description.");

    [
        statStrength,
        statDexterity,
        statVitality,
        statEnergy,
        statFate,
        statLife,
        statMana,
        statMeleeDamage,
        statToughness,
        statSpellPower
    ].forEach(function (element) {
        setText(element, "");
    });
}

function updateClassPreview() {
    const selected = classSelect.options[classSelect.selectedIndex];

    if (!selected || !selected.value) {
        clearClassPreview();
        return;
    }

    setText(previewGlyph, selected.dataset.glyph);
    setText(previewName, selected.dataset.name);
    setText(previewDescription, selected.dataset.description);

    setText(statStrength, selected.dataset.strength);
    setText(statDexterity, selected.dataset.dexterity);
    setText(statVitality, selected.dataset.vitality);
    setText(statEnergy, selected.dataset.energy);
    setText(statFate, selected.dataset.fate);
    setText(statLife, selected.dataset.life);
    setText(statMana, selected.dataset.mana);
    setText(statMeleeDamage, selected.dataset.meleeDamage);
    setText(statToughness, selected.dataset.toughness);
    setText(statSpellPower, selected.dataset.spellPower);
}

classSelect.addEventListener("change", updateClassPreview);

if (classSelect.value) {
    updateClassPreview();
} else {
    clearClassPreview();
}
</script>

</body>
</html>
