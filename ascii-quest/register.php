<?php
declare(strict_types=1);

require_once __DIR__ . "/db.php";

$pdo = getDb();

$message = "";
$messageType = "";

if ($_SERVER["REQUEST_METHOD"] === "POST") {
    $username = trim($_POST["username"] ?? "");
    $email = trim($_POST["email"] ?? "");
    $password = $_POST["password"] ?? "";
    $confirmPassword = $_POST["confirm_password"] ?? "";

    if (
        $username === "" ||
        $email === "" ||
        $password === "" ||
        $confirmPassword === ""
    ) {
        $message = "All fields are required.";
        $messageType = "error";
    } elseif (strlen($username) < 3 || strlen($username) > 50) {
        $message = "Username must be between 3 and 50 characters.";
        $messageType = "error";
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $message = "Please enter a valid email address.";
        $messageType = "error";
    } elseif (strlen($password) < 8) {
        $message = "Password must be at least 8 characters.";
        $messageType = "error";
    } elseif ($password !== $confirmPassword) {
        $message = "Passwords do not match.";
        $messageType = "error";
    } else {
        $passwordHash = password_hash($password, PASSWORD_DEFAULT);

        try {
            $stmt = $pdo->prepare("
                INSERT INTO users (username, email, password_hash)
                VALUES (:username, :email, :password_hash)
            ");

            $stmt->execute([
                "username" => $username,
                "email" => $email,
                "password_hash" => $passwordHash,
            ]);

            $message = "Account created successfully. You can now log in.";
            $messageType = "success";
        } catch (PDOException $e) {
            if ($e->getCode() === "23000") {
                $message = "Username or email already exists.";
                $messageType = "error";
            } else {
                error_log("Register error: " . $e->getMessage());
                $message = "Something went wrong. Please try again.";
                $messageType = "error";
            }
        }
    }
}

function e(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES, "UTF-8");
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>ASCII Quest - Create Account</title>
    <link rel="stylesheet" href="css/style.css">
</head>
<body>

    <main class="page">
        <section class="panel">
            <div class="logo">
                <h1>ASCII Quest</h1>
                <p>Enter the dungeon. Survive the darkness.</p>
            </div>

            <h2>Create Account</h2>

            <?php if ($message !== ""): ?>
                <div class="message <?= e($messageType) ?>">
                    <?= e($message) ?>
                </div>
            <?php endif; ?>

            <form method="post" action="register.php">
                <label for="username">Username</label>
                <input
                    type="text"
                    id="username"
                    name="username"
                    maxlength="50"
                    required
                    value="<?= e($_POST["username"] ?? "") ?>"
                >

                <label for="email">Email</label>
                <input
                    type="email"
                    id="email"
                    name="email"
                    maxlength="120"
                    required
                    value="<?= e($_POST["email"] ?? "") ?>"
                >

                <label for="password">Password</label>
                <input
                    type="password"
                    id="password"
                    name="password"
                    required
                >

                <label for="confirm_password">Confirm Password</label>
                <input
                    type="password"
                    id="confirm_password"
                    name="confirm_password"
                    required
                >

                <button type="submit">Create Account</button>
            </form>

            <p class="small-text">
                Already have an account?
                <a href="login.php">Login here</a>
            </p>
        </section>
    </main>
