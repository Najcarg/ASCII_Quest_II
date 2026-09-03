<?php
declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| ASCII Quest - Delete Character
|--------------------------------------------------------------------------
| Purpose:
|   Deletes a character only if:
|   1. User is logged in
|   2. Character belongs to this user
|   3. User typed the exact character name
|   4. CSRF token is valid
*/

session_start();

require_once __DIR__ . "/db.php";
require_once __DIR__ . "/lib/CombatBootstrap.php";

$pdo = getDb();

/*
|--------------------------------------------------------------------------
| Helper: redirect back to character selection
|--------------------------------------------------------------------------
*/
function backToCharacterSelect(): void
{
    header("Location: character_select.php");
    exit();
}

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
| Security: only POST is allowed
|--------------------------------------------------------------------------
*/
if ($_SERVER["REQUEST_METHOD"] !== "POST") {
    backToCharacterSelect();
}

/*
|--------------------------------------------------------------------------
| Security: CSRF token check
|--------------------------------------------------------------------------
| This helps prevent unwanted delete requests from another page/site.
*/
$postedToken = $_POST["csrf_token"] ?? "";
$sessionToken = $_SESSION["csrf_token"] ?? "";

if (
    $postedToken === "" ||
    $sessionToken === "" ||
    !hash_equals($sessionToken, $postedToken)
) {
    $_SESSION["flash_message"] = "Security check failed. Please try again.";
    $_SESSION["flash_type"] = "error";
    backToCharacterSelect();
}

/*
|--------------------------------------------------------------------------
| Read submitted values
|--------------------------------------------------------------------------
*/
$characterId = (int) ($_POST["character_id"] ?? 0);
$confirmName = trim($_POST["confirm_name"] ?? "");

if ($characterId <= 0 || $confirmName === "") {
    $_SESSION["flash_message"] = "Invalid delete request.";
    $_SESSION["flash_type"] = "error";
    backToCharacterSelect();
}

/*
|--------------------------------------------------------------------------
| Load character and verify ownership
|--------------------------------------------------------------------------
*/
$stmt = $pdo->prepare("
    SELECT id, character_name
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
    $_SESSION["flash_message"] = "Character not found.";
    $_SESSION["flash_type"] = "error";
    backToCharacterSelect();
}

/*
|--------------------------------------------------------------------------
| Require exact character name
|--------------------------------------------------------------------------
| This prevents accidental deletion.
*/
if ($confirmName !== $character["character_name"]) {
    $_SESSION["flash_message"] =
        "Character name did not match. Character was not deleted.";
    $_SESSION["flash_type"] = "error";
    backToCharacterSelect();
}

$combatGuard = null;
try {
    $combatGuard = CombatBootstrap::guard($pdo);
    $decision = $combatGuard->beginAtomic(
        CombatAccessGuard::DELETE_CHARACTER,
        (int) $_SESSION["user_id"],
        $characterId,
    );
    $character = $decision["character"];
    if ($confirmName !== $character["character_name"]) {
        throw new DomainException(
            "Character name did not match. Character was not deleted.",
        );
    }

/*
|--------------------------------------------------------------------------
| Delete character
|--------------------------------------------------------------------------
*/
$deleteStmt = $pdo->prepare("
    DELETE FROM characters
    WHERE id = :character_id
      AND user_id = :user_id
");

$deleteStmt->execute([
    "character_id" => $characterId,
    "user_id" => $_SESSION["user_id"],
]);
$combatGuard->commit();
} catch (DomainException | OutOfBoundsException $e) {
    $combatGuard?->rollBack();
    $_SESSION["flash_message"] = $e->getMessage();
    $_SESSION["flash_type"] = "error";
    backToCharacterSelect();
} catch (Throwable $e) {
    $combatGuard?->rollBack();
    error_log("Character deletion failed: " . $e->getMessage());
    $_SESSION["flash_message"] = "Unable to delete that Champion. Please try again.";
    $_SESSION["flash_type"] = "error";
    backToCharacterSelect();
}

/*
|--------------------------------------------------------------------------
| If deleted character was currently selected, clear it from session
|--------------------------------------------------------------------------
*/
if (
    isset($_SESSION["character_id"]) &&
    (int) $_SESSION["character_id"] === $characterId
) {
    unset($_SESSION["character_id"]);
}

$_SESSION["flash_message"] = "Character deleted successfully.";
$_SESSION["flash_type"] = "success";

backToCharacterSelect();
