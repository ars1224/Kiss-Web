<?php
declare(strict_types=1);

require_once __DIR__ . '/php/conn/db.php';

$message = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $name = trim($_POST['name'] ?? '');
    $username = trim($_POST['username'] ?? '');
    $password = $_POST['password'] ?? '';
    $role = $_POST['role'] ?? 'Outwards';

    $allowedRoles = ['Inwards', 'Outwards', 'Rawmat', 'Admin'];

    if ($name === '' || $username === '' || $password === '') {
        $message = 'Name, username and password are required.';
    } elseif (!in_array($role, $allowedRoles, true)) {
        $message = 'Invalid role selected.';
    } else {
        try {
            $pdo = db();

            $hash = password_hash($password, PASSWORD_DEFAULT);

            $stmt = $pdo->prepare("
                INSERT INTO users (name, username, password_hash, role)
                VALUES (:name, :username, :password_hash, :role)
            ");

            $stmt->execute([
                ':name' => $name,
                ':username' => $username,
                ':password_hash' => $hash,
                ':role' => $role
            ]);

            header('Location: login.php?signup=success');
            exit;
        } catch (PDOException $e) {
            $message = 'Username already exists or signup failed.';
        }
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Sign Up | KISS Web</title>

    <link rel="stylesheet" href="css/auth.css">
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;500;600;700&display=swap" rel="stylesheet">
</head>

<body>

<div class="auth-container">

    <div class="auth-logo">
        <h1>KISS Web</h1>
        <p>Warehouse Management System</p>
    </div>

    <h2 class="auth-title">Create Account</h2>

    <?php if ($message): ?>
        <div class="auth-message error">
            <?= htmlspecialchars($message) ?>
        </div>
    <?php endif; ?>

    <form method="POST">

        <div class="form-group">
            <label>Name</label>
            <input type="text" name="name" required>
        </div>

        <div class="form-group">
            <label>Username</label>
            <input type="text" name="username" required>
        </div>

        <div class="form-group">
            <label>Password</label>
            <input type="password" name="password" required>
        </div>

        <div class="form-group">
            <label>Role</label>
            <select name="role" required>
                <option value="Inwards">Inwards</option>
                <option value="Outwards" selected>Outwards</option>
                <option value="Rawmat">Rawmat</option>
                <option value="Admin">Admin</option>
            </select>
        </div>

        <button type="submit" class="auth-btn">Sign Up</button>

    </form>

    <div class="auth-footer">
        Already have an account?
        <a href="login.php">Log in</a>
    </div>

</div>

</body>
</html>