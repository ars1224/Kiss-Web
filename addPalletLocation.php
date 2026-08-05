<?php
declare(strict_types=1);

require_once __DIR__ . '/php/auth/session.php';
requireLogin();

$title   = 'Add Pallet';
$activePage = 'location';
$pageCSS = 'css/location-addpallet.css';
$pageJS  = 'js/locationAddPallet.js';

$content = __DIR__ . '/pages/addPalletLocationContent.php';

include __DIR__ . '/includes/_layout.php';
