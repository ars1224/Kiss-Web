<?php
declare(strict_types=1);
require_once __DIR__ . '/php/auth/session.php';
requireLogin();

$title = 'Orders List';
$activePage = 'orders_list';
$pageCSS = 'css/ordersStyleSheet.css';
$content = __DIR__ . '/pages/ordersListContent.php';

require_once __DIR__ . '/includes/_layout.php';
