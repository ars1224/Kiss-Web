<?php
declare(strict_types=1);

$isMobile = preg_match(
    '/Mobile|Android|iPhone|iPad|iPod/i',
    $_SERVER['HTTP_USER_AGENT'] ?? ''
);

if ($isMobile) {

    // MOBILE → stay logged in 30 days
    session_set_cookie_params([
        'lifetime' => 60 * 60 * 24 * 30,
        'path' => '/',
        'httponly' => true,
        'samesite' => 'Lax'
    ]);

} else {

    // PC → logout when browser closes
    session_set_cookie_params([
        'lifetime' => 0,
        'path' => '/',
        'httponly' => true,
        'samesite' => 'Lax'
    ]);
}

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

function isLoggedIn(): bool {
    return isset($_SESSION['user_id']);
}

function requireLogin(): void {
    if (!isLoggedIn()) {
        header('Location: login.php');
        exit;
    }
}

function currentUserRole(): string {
    return $_SESSION['role'] ?? '';
}

function requireRole(array $roles): void {
    requireLogin();

    if (!in_array(currentUserRole(), $roles, true)) {
        http_response_code(403);
        exit('Access denied.');
    }
}