<?php
require_once __DIR__ . '/php/auth/session.php';
requireLogin();

$title   = 'Dashboard';
$activePage = 'dashboard';

$content = __DIR__ . '/pages/dashboard.php';

include __DIR__ . '/includes/_layout.php';
