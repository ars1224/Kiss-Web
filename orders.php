<?php
declare(strict_types=1);
require_once __DIR__ . '/php/auth/session.php';
requireLogin();

$title = 'Create Order';
$activePage = 'orders';
$pageCSS = 'css/ordersStyleSheet.css';
$content = __DIR__ . '/pages/ordersContent.php';

require_once __DIR__ . '/includes/_layout.php';
