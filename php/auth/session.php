<?php
declare(strict_types=1);

$isMobile = preg_match(
    '/Mobile|Android|iPhone|iPad|iPod/i',
    $_SERVER['HTTP_USER_AGENT'] ?? ''
);

if ($isMobile) {

    // MOBILE -> stay logged in 30 days
    session_set_cookie_params([
        'lifetime' => 60 * 60 * 24 * 30,
        'path' => '/',
        'httponly' => true,
        'samesite' => 'Lax'
    ]);

} else {

    // PC -> logout when browser closes
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

function appBasePath(): string {
    $script = str_replace('\\', '/', $_SERVER['SCRIPT_NAME'] ?? '');

    foreach (['/php/', '/pages/', '/includes/'] as $marker) {
        $pos = strpos($script, $marker);
        if ($pos !== false) {
            return rtrim(substr($script, 0, $pos), '/');
        }
    }

    $dir = rtrim(str_replace('\\', '/', dirname($script)), '/');
    return $dir === '/' ? '' : $dir;
}

function appPath(string $path): string {
    return appBasePath() . '/' . ltrim($path, '/');
}

function normalizeLocalRedirectTarget(?string $target, string $fallback = 'index.php'): string {
    $target = trim((string)($target ?? ''));

    if ($target === '') {
        return $fallback;
    }

    if (str_contains($target, "\r") || str_contains($target, "\n") || strpos($target, '//') === 0) {
        return $fallback;
    }

    $parts = parse_url($target);
    if ($parts === false) {
        return $fallback;
    }

    if (isset($parts['scheme']) || isset($parts['host'])) {
        $host = $_SERVER['HTTP_HOST'] ?? '';
        if (empty($parts['host']) || strcasecmp((string)$parts['host'], $host) !== 0) {
            return $fallback;
        }

        $target = (string)($parts['path'] ?? '');
        if (isset($parts['query'])) {
            $target .= '?' . $parts['query'];
        }
        if (isset($parts['fragment'])) {
            $target .= '#' . $parts['fragment'];
        }
    }

    $path = parse_url($target, PHP_URL_PATH);
    $page = strtolower(basename((string)$path));
    if (in_array($page, ['login.php', 'logout.php'], true)) {
        return $fallback;
    }

    return $target;
}

function markRestoreTarget(string $target): string {
    $path = parse_url($target, PHP_URL_PATH);
    if (strtolower(basename((string)$path)) !== 'addpalletlocation.php') {
        return $target;
    }

    $query = parse_url($target, PHP_URL_QUERY);
    parse_str((string)$query, $params);
    if (isset($params['restoreDraft'])) {
        return $target;
    }

    $fragment = '';
    $hashPos = strpos($target, '#');
    if ($hashPos !== false) {
        $fragment = substr($target, $hashPos);
        $target = substr($target, 0, $hashPos);
    }

    $separator = str_contains($target, '?') ? '&' : '?';
    return $target . $separator . 'restoreDraft=1' . $fragment;
}

function loginReturnTarget(): string {
    $method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
    $target = $method === 'GET'
        ? ($_SERVER['REQUEST_URI'] ?? 'index.php')
        : ($_SERVER['HTTP_REFERER'] ?? 'index.php');

    return markRestoreTarget(normalizeLocalRedirectTarget($target, 'index.php'));
}

function requireLogin(): void {
    if (!isLoggedIn()) {
        $target = loginReturnTarget();
        $_SESSION['login_redirect'] = $target;

        header('Location: ' . appPath('login.php') . '?redirect=' . rawurlencode($target));
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