<?php
declare(strict_types=1);

require_once __DIR__ . '/php/auth/session.php';
requireLogin();

$title = 'Products';
$activePage = 'Products';
$pageCSS = 'css/productsStyleSheet.css';
$content = __DIR__ . '/pages/productsContent.php';

require_once __DIR__ . '/includes/_layout.php';
