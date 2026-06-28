<?php
declare(strict_types=1);

session_start();

require_once __DIR__ . "/db.php";

$pdo = getDb();

$message = "";
$messageType = "";

function e(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES, "UTF-8");
}

if (isset($_SESSION["user_id"])) {
    header("Location: account.php");
    exit();
}

if ($_SERVER["REQUEST_METHOD"] === "POST") {
    $login = trim($_POST["login"] ?? "");
    $password = $_POST["password"] ?? "";

    if ($login === "" || $password === "") {
        $message = "Username/email and password are required.";
        $messageType = "error";
    } else {
        try {
            $stmt = $pdo->prepare("
                SELECT id, username, email, password_hash
                FROM users
                WHERE username = :username_login OR email = :email_login
                LIMIT 1
            ");

            $stmt->execute([
                "username_login" => $login,
                "email_login" => $login,
            ]);

            $user = $stmt->fetch();

            if ($user && password_verify($password, $user["password_hash"])) {
                session_regenerate_id(true);

                $_SESSION["user_id"] = $user["id"];
                $_SESSION["username"] = $user["username"];

                header("Location: account.php");
                exit();
            }

            $message = "Invalid login details.";
            $messageType = "error";
        } catch (PDOException $e) {
            error_log("Login error: " . $e->getMessage());
            $message = "Something went wrong. Please try again.";
            $messageType = "error";
        }
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>ASCII Quest - Login</title>
    <link rel="stylesheet" href="css/style.css">
</head>
<body>

<main class="page">
    <section class="panel">
        <div class="logo">
            <h1>ASCII Quest</h1>
            <p>Enter the dungeon. Survive the darkness.</p>
        </div>

        <h2>Login</h2>

        <?php if ($message !== ""): ?>
            <div class="message <?= e($messageType) ?>">
                <?= e($message) ?>
            </div>
        <?php endif; ?>

        <form method="post" action="login.php">
            <label for="login">Username or Email</label>
            <input
                type="text"
                id="login"
                name="login"
                required
                value="<?= e($_POST["login"] ?? "") ?>"
            >

            <label for="password">Password</label>
            <input
                type="password"
                id="password"
                name="password"
                required
            >

            <button type="submit">Login</button>
        </form>

        <p class="small-text">
            No account yet?
            <a href="register.php">Create one here</a>
        </p>
    </section>
</main>

</body>
</html>
