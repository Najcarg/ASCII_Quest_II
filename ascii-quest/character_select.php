<?php
declare(strict_types=1);

session_start();

require_once __DIR__ . "/db.php";
require_once __DIR__ . "/lib/CharacterStats.php";

$pdo = getDb();
/*
|--------------------------------------------------------------------------
| CSRF token
|--------------------------------------------------------------------------
| Used by delete form to protect character deletion.
*/
if (empty($_SESSION["csrf_token"])) {
    $_SESSION["csrf_token"] = bin2hex(random_bytes(32));
}

/*
|--------------------------------------------------------------------------
| Flash message
|--------------------------------------------------------------------------
| Used to show success/error after deleting a character.
*/
$flashMessage = $_SESSION["flash_message"] ?? "";
$flashType = $_SESSION["flash_type"] ?? "";

unset($_SESSION["flash_message"], $_SESSION["flash_type"]);
if (!isset($_SESSION["user_id"])) {
    header("Location: login.php");
    exit();
}

function e(mixed $value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES, "UTF-8");
}

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
        c.pos_x,
        c.pos_y,

        cc.class_name,
        cc.glyph,
        cc.description
    FROM characters c
    INNER JOIN character_classes cc
        ON cc.id = c.class_id
    WHERE c.user_id = :user_id
    ORDER BY c.created_at DESC
");

$stmt->execute([
    "user_id" => $_SESSION["user_id"],
]);

$characters = $stmt->fetchAll();
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>ASCII Quest - Character Selection</title>
    <link rel="stylesheet" href="css/style.css">
</head>
<body>

<main class="page">
    <section class="panel wide-panel">
        <div class="logo">
            <h1>ASCII Quest</h1>
            <p>Choose the soul that will descend.</p>
        </div>

        <h2>Character Selection</h2>
        <?php if ($flashMessage !== ""): ?>
            <div class="message <?= e($flashType) ?>">
                <?= e($flashMessage) ?>
            </div>
        <?php endif; ?>

        <?php if (count($characters) === 0): ?>
            <div class="message">
                You do not have any characters yet.
            </div>

            <a class="menu-button" href="create_character.php">Create Character</a>
            <a class="menu-button secondary" href="account.php">Back to Main Menu</a>
        <?php else: ?>
            <div class="character-list">
                <?php foreach ($characters as $character): ?>
                    <?php
                    try {
                        $stats = CharacterStats::calculate($character);
                    } catch (InvalidArgumentException $e) {
                        error_log(
                            "CharacterStats error for character " .
                            (int) $character["id"] . ": " . $e->getMessage(),
                        );
                        $stats = null;
                    }
                    ?>
                    <article class="character-card">
                        <div class="character-glyph-frame">
                            <div class="character-glyph">
                                <?= e($character["glyph"]) ?>
                            </div>
                        </div>

                        <div class="character-info">
                            <h3><?= e($character["character_name"]) ?></h3>

                            <p class="character-class">
                                <?= e(
                                    $character["class_name"],
                                ) ?> · Level <?= e($character["level"]) ?>
                            </p>

                            <p class="character-description">
                                <?= e($character["description"]) ?>
                            </p>

                            <?php if ($stats === null): ?>
                                <div class="message error">
                                    Unable to load Champion statistics.
                                </div>
                            <?php else: ?>
                                <div class="character-stats">
                                    <div>
                                        <span>HP</span>
                                        <strong><?= e($character["current_hp"]) ?>/<?= e($stats["resources"]["max_life"]) ?></strong>
                                    </div>

                                    <div>
                                        <span>Mana</span>
                                        <strong><?= e($character["current_mana"]) ?>/<?= e($stats["resources"]["max_mana"]) ?></strong>
                                    </div>

                                    <div><span>STR</span><strong><?= e($stats["main"]["strength"]) ?></strong></div>
                                    <div><span>DEX</span><strong><?= e($stats["main"]["dexterity"]) ?></strong></div>
                                    <div><span>VIT</span><strong><?= e($stats["main"]["vitality"]) ?></strong></div>
                                    <div><span>ENE</span><strong><?= e($stats["main"]["energy"]) ?></strong></div>
                                    <div><span>FATE</span><strong><?= e($stats["main"]["fate"]) ?></strong></div>

                                    <div>
                                        <span>XP</span>
                                        <strong><?= e($character["experience"]) ?></strong>
                                    </div>

                                    <div>
                                        <span>Gold</span>
                                        <strong><?= e($character["gold"]) ?></strong>
                                    </div>
                                </div>
                            <?php endif; ?>

                            <form method="post" action="select_character.php">
                                <input
                                    type="hidden"
                                    name="character_id"
                                    value="<?= e($character["id"]) ?>"
                                >

                                <button type="submit">Enter Dungeon</button>
                                <!--
                                |--------------------------------------------------------------------------
                                | Delete Character Button
                                |--------------------------------------------------------------------------
                                | This does not delete immediately.
                                | It opens a popup where player must type exact character name.
                                -->
                                <button
                                    type="button"
                                    class="delete-character-button"
                                    data-character-id="<?= e(
                                        $character["id"],
                                    ) ?>"
                                    data-character-name="<?= e(
                                        $character["character_name"],
                                    ) ?>"
                                >
                                    Delete Character
                                </button>
                            </form>
                        </div>
                    </article>
                <?php endforeach; ?>
            </div>

            <a class="menu-button secondary" href="create_character.php">Create Another Character</a>
            <a class="menu-button secondary" href="account.php">Back to Main Menu</a>
        <?php endif; ?>
    </section>
</main>
<!--
|--------------------------------------------------------------------------
| Delete Character Modal
|--------------------------------------------------------------------------
| Hidden popup. JavaScript opens it when Delete Character is clicked.
-->
<div id="deleteCharacterModal" class="modal-overlay" hidden>
    <div class="modal-box">
        <h2>Delete Character</h2>

        <p>
            This action cannot be undone.
        </p>

        <p>
            Type character name to confirm:
            <strong id="deleteCharacterNamePreview"></strong>
        </p>

        <form method="post" action="delete_character.php">
            <input
                type="hidden"
                name="csrf_token"
                value="<?= e($_SESSION["csrf_token"]) ?>"
            >

            <input
                type="hidden"
                id="deleteCharacterId"
                name="character_id"
                value=""
            >

            <label for="deleteConfirmName">Character Name</label>
            <input
                type="text"
                id="deleteConfirmName"
                name="confirm_name"
                autocomplete="off"
                required
            >

            <div class="modal-actions">
                <button type="submit" class="danger-submit-button">
                    Permanently Delete
                </button>

                <button type="button" id="cancelDeleteCharacter" class="secondary-button">
                    Cancel
                </button>
            </div>
        </form>
    </div>
</div>

<script>
/*
|--------------------------------------------------------------------------
| ASCII Quest - Delete Character Popup
|--------------------------------------------------------------------------
| Opens a popup and fills hidden character ID.
| Player must type exact character name before PHP allows deletion.
*/

const deleteModal = document.getElementById("deleteCharacterModal");
const deleteCharacterId = document.getElementById("deleteCharacterId");
const deleteCharacterNamePreview = document.getElementById("deleteCharacterNamePreview");
const deleteConfirmName = document.getElementById("deleteConfirmName");
const cancelDeleteCharacter = document.getElementById("cancelDeleteCharacter");

function openDeleteModal(characterId, characterName) {
    deleteCharacterId.value = characterId;
    deleteCharacterNamePreview.textContent = characterName;
    deleteConfirmName.value = "";

    deleteModal.hidden = false;
    deleteConfirmName.focus();
}

function closeDeleteModal() {
    deleteModal.hidden = true;
    deleteCharacterId.value = "";
    deleteCharacterNamePreview.textContent = "";
    deleteConfirmName.value = "";
}

document.querySelectorAll(".delete-character-button").forEach(function (button) {
    button.addEventListener("click", function () {
        openDeleteModal(
            button.dataset.characterId,
            button.dataset.characterName
        );
    });
});

cancelDeleteCharacter.addEventListener("click", closeDeleteModal);

deleteModal.addEventListener("click", function (event) {
    if (event.target === deleteModal) {
        closeDeleteModal();
    }
});

document.addEventListener("keydown", function (event) {
    if (event.key === "Escape" && !deleteModal.hidden) {
        closeDeleteModal();
    }
});
</script>
</body>
</html>
