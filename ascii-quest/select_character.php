<?php
declare(strict_types=1);

session_start();

require_once __DIR__ . "/db.php";

$pdo = getDb();

if (!isset($_SESSION["user_id"])) {
    header("Location: login.php");
    exit();
}

if ($_SERVER["REQUEST_METHOD"] !== "POST") {
    header("Location: character_select.php");
    exit();
}

$characterId = (int) ($_POST["character_id"] ?? 0);

if ($characterId <= 0) {
    header("Location: character_select.php");
    exit();
}

$stmt = $pdo->prepare("
    SELECT id
    FROM characters
    WHERE id = :character_id
      AND user_id = :user_id
    LIMIT 1
");

$stmt->execute([
    "character_id" => $characterId,
    "user_id" => $_SESSION["user_id"],
]);

$character = $stmt->fetch();

if (!$character) {
    header("Location: character_select.php");
    exit();
}

$_SESSION["character_id"] = $character["id"];

header("Location: game.php");
exit();
