<?php

require_once __DIR__ . '/php/auth/session.php';
requireLogin();

$title   = 'Transactions';
$activePage = 'location';
$pageCSS = 'css/transactionsStyleSheet.css'; 
$pageJS  = 'js/transactions.js';

$content = __DIR__ . '/pages/transactionsContent.php';

include __DIR__ . '/includes/_layout.php';
