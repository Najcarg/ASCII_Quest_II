<?php
declare(strict_types=1);

session_start();

if (!isset($_SESSION["user_id"])) {
    header("Location: login.php");
    exit();
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>ASCII Quest - Terms and Conditions</title>
    <link rel="stylesheet" href="css/style.css">
</head>
<body>

<main class="page">
    <section class="panel">
        <div class="logo">
            <h1>ASCII Quest</h1>
            <p>The laws of the dungeon.</p>
        </div>

        <h2>Terms and Conditions</h2>

        <div class="message">
            <p>
                ASCII Quest is currently a private learning project.
            </p>

            <p>
                Do not share your password. Do not try to access another player's account.
            </p>

            <p>
                Game data, characters, items and progress may be reset during development.
            </p>
        </div>

        <a class="menu-button" href="account.php">Back to Main Menu</a>
    </section>
</main>

</body>
</html>
