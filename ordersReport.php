<?php
require_once __DIR__ . '/php/auth/session.php';
requireLogin();

$content = __DIR__ . '/pages/ordersReportContent.php';
$title = 'Orders Report';
$activePage = 'orders';
$pageCSS = 'css/ordersReport.css';

require __DIR__ . '/includes/_layout.php';
