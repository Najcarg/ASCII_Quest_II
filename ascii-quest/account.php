<?php
declare(strict_types=1);

session_start();

if (!isset($_SESSION["user_id"])) {
    header("Location: login.php");
    exit();
}

function e(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES, "UTF-8");
}

$username = $_SESSION["username"] ?? "Player";
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
