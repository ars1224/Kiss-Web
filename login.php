<?php
declare(strict_types=1);

require_once __DIR__ . '/php/conn/db.php';
require_once __DIR__ . '/php/auth/session.php';

$message = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = trim($_POST['username'] ?? '');
    $password = $_POST['password'] ?? '';

    if ($username === '' || $password === '') {
        $message = 'Username and password are required.';
    } else {
        $pdo = db();

        $stmt = $pdo->prepare("
            SELECT *
            FROM users
            WHERE username = :username
            LIMIT 1
        ");

        $stmt->execute([
            ':username' => $username
        ]);

        $user = $stmt->fetch();

        if ($user && password_verify($password, $user['password_hash'])) {
            $_SESSION['user_id'] = $user['id'];
            $_SESSION['name'] = $user['name'];
            $_SESSION['username'] = $user['username'];
            $_SESSION['role'] = $user['role'];

            header('Location: index.php');
            exit;
        } else {
            $message = 'Invalid username or password.';
        }
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Login | KISS Web</title>

    <link rel="stylesheet" href="css/auth.css">
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;500;600;700&display=swap" rel="stylesheet">
</head>

<body>

<div class="auth-container">

    <div class="auth-logo">
        <h1>KISS Web</h1>
        <p>Warehouse Management System</p>
    </div>

    <h2 class="auth-title">Log In</h2>

    <?php if (isset($_GET['signup'])): ?>
        <div class="auth-message success">
            Signup successful. Please log in.
        </div>
    <?php endif; ?>

    <?php if ($message): ?>
        <div class="auth-message error">
            <?= htmlspecialchars($message) ?>
        </div>
    <?php endif; ?>

    <form method="POST">

        <div class="form-group">
            <label>Username</label>
            <input type="text" name="username" required>
        </div>

        <div class="form-group">
            <label>Password</label>
            <input type="password" name="password" required>
        </div>

        <button type="submit" class="auth-btn">Log In</button>

    </form>

    <div class="auth-footer">
        No account yet?
        <a href="signup.php">Sign up</a>
    </div>

</div>

</body>
</html>