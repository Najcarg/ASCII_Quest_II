<?php
declare(strict_types=1);

session_start();

require_once __DIR__ . "/db.php";
require_once __DIR__ . "/lib/CombatBootstrap.php";

$pdo = getDb();

if (!isset($_SESSION["user_id"])) {
    header("Location: login.php");
    exit();
}

if ($_SERVER["REQUEST_METHOD"] !== "POST") {
    header("Location: character_select.php");
    exit();
}

$postedToken = $_POST["csrf_token"] ?? "";
$sessionToken = $_SESSION["csrf_token"] ?? "";
if (
    !is_string($postedToken) ||
    !is_string($sessionToken) ||
    $postedToken === "" ||
    $sessionToken === "" ||
    !hash_equals($sessionToken, $postedToken)
) {
    $_SESSION["flash_message"] = "Security check failed. Please try again.";
    $_SESSION["flash_type"] = "error";
    header("Location: character_select.php");
    exit();
}

$characterId = (int) ($_POST["character_id"] ?? 0);

if ($characterId <= 0) {
    header("Location: character_select.php");
    exit();
}

$combatGuard = null;
$previousCharacterId = $_SESSION["character_id"] ?? null;
try {
    $combatGuard = CombatBootstrap::guard($pdo);
    $decision = $combatGuard->beginAtomic(
        CombatAccessGuard::SELECT_CHARACTER,
        (int) $_SESSION["user_id"],
        $characterId,
    );
    $character = $decision["character"];
    $_SESSION["character_id"] = $character["id"];
    $combatGuard->commit();
} catch (DomainException | OutOfBoundsException $e) {
    $combatGuard?->rollBack();
    if ($previousCharacterId === null) {
        unset($_SESSION["character_id"]);
    } else {
        $_SESSION["character_id"] = $previousCharacterId;
    }
    $_SESSION["flash_message"] = $e->getMessage();
    $_SESSION["flash_type"] = "error";
    header("Location: character_select.php");
    exit();
} catch (Throwable $e) {
    $combatGuard?->rollBack();
    if ($previousCharacterId === null) {
        unset($_SESSION["character_id"]);
    } else {
        $_SESSION["character_id"] = $previousCharacterId;
    }
    error_log("Character selection failed: " . $e->getMessage());
    $_SESSION["flash_message"] = "Unable to select that Champion. Please try again.";
    $_SESSION["flash_type"] = "error";
    header("Location: character_select.php");
    exit();
}

header("Location: game.php");
exit();
