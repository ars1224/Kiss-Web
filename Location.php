<?php
require_once __DIR__ . '/php/auth/session.php';
requireLogin();

$title   = 'Product Locations';
$activePage = 'location';
$pageCSS = 'css/ProductLocationStyleSheet.css';


$content = __DIR__ . '/pages/productLocationContent.php';
include __DIR__ . '/includes/_layout.php';

