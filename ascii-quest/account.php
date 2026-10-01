<?php
declare(strict_types=1);

session_start();

require_once __DIR__ . "/db.php";
require_once __DIR__ . "/lib/CombatBootstrap.php";

if (!isset($_SESSION["user_id"])) {
    header("Location: login.php");
    exit();
}

function e(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES, "UTF-8");
}

$username = $_SESSION["username"] ?? "Player";
$activeCombat = null;
try {
    $activeCombat = CombatBootstrap::guard(getDb())->accountCombatState(
        (int) $_SESSION["user_id"],
    );
} catch (Throwable $e) {
    error_log("Main Menu combat lookup failed: " . $e->getMessage());
}
$resumeLabel = ($activeCombat["status"] ?? null) === "victory_loot"
    ? "Resume Loot"
    : "Resume Battle";
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>ASCII Quest - Main Menu</title>
    <link rel="stylesheet" href="css/style.css">
</head>
<body>

<main class="page">
    <section class="panel">
        <div class="logo">
            <h1>ASCII Quest</h1>
            <p>Beneath the mountain, darkness stirs.</p>
        </div>

        <h2>Main Menu</h2>

        <div class="message success">
            Welcome, <?= e($username) ?>.
        </div>

        <div class="menu-actions">
            <?php if ($activeCombat !== null): ?>
                <a class="menu-button" href="character_select.php">
                    <?= e($resumeLabel) ?>
                </a>
            <?php endif; ?>

            <a class="menu-button" href="create_character.php">
                Character Creation
            </a>

            <a class="menu-button" href="character_select.php">
                Character Selection
            </a>

            <a class="menu-button secondary" href="terms.php">
                Terms and Conditions
            </a>

            <a class="menu-button danger" href="logout.php">
                Log Out
            </a>
        </div>
    </section>
</main>

</body>
</html>
