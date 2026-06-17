<?php
declare(strict_types=1);

require_once __DIR__ . '/../conn/db.php';
require_once __DIR__ . '/../auth/session.php';
requireLogin();

require_once __DIR__ . '/../util/inventory_helper.php';

$inventoryType = inventoryType();

function h($v): string {
  return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
}

function get_str(string $k, string $default = ''): string {
  return isset($_GET[$k]) ? trim((string)$_GET[$k]) : $default;
}

function tx_parse_search(string $q): array {
  $q = trim($q);
  if ($q === '') return ['tokens' => [], 'free' => ''];

  preg_match_all('/(\w+):(".*?"|\S+)/', $q, $m, PREG_SET_ORDER);

  $tokens = [];
  foreach ($m as $hit) {
    $k = strtolower($hit[1]);
    $v = trim($hit[2], "\"' ");
    $tokens[$k] = $v;
  }

  $free = preg_replace('/(\w+):(".*?"|\S+)/', '', $q);
  $free = trim(preg_replace('/\s+/', ' ', $free));

  return ['tokens' => $tokens, 'free' => $free];
}

// -------------------------
// Read filters
// -------------------------
$rawQ   = get_str('q');
$parsed = tx_parse_search($rawQ);
$t      = $parsed['tokens'];
$free   = $parsed['free'];

$filters = [
  'q'        => $rawQ,
  'free'     => $free,
  'action'   => $t['action'] ?? get_str('action'),
  'sku'      => $t['sku'] ?? get_str('sku'),
  'loc'      => $t['loc'] ?? get_str('loc'),
  'batch'    => $t['batch'] ?? get_str('batch'),
  'actor'    => $t['actor'] ?? get_str('actor'),
  'comments' => $t['comments'] ?? get_str('comments'),
  'from'     => $t['from'] ?? get_str('from'),
  'to'       => $t['to'] ?? get_str('to'),
];

// -------------------------
// Build SQL
// -------------------------
$where  = [];
$params = [];

$role = strtolower(trim(currentUserRole()));

if ($role !== 'admin') {
  $where[] = "LOWER(TRIM(InventoryType)) = :inventory_type";

  if ($role === 'inwards') {
    $params['inventory_type'] = 'packaging';
  } elseif ($role === 'rawmat') {
    $params['inventory_type'] = 'rm';
  } else {
    $params['inventory_type'] = 'products';
  }
}

if ($filters['free'] !== '') {
  $like = '%' . $filters['free'] . '%';

  $cols = [
    'Action',
    'SKU_Code',
    'OldLocation',
    'NewLocation',
    'BatchNo',
    'Comments',
    'Actor'
  ];

  $ors = [];

  foreach ($cols as $i => $col) {
    $ph = "q{$i}";
    $ors[] = "$col LIKE :$ph";
    $params[$ph] = $like;
  }

  $where[] = "(" . implode(" OR ", $ors) . ")";
}

if ($filters['action'] !== '') {
  $where[] = "Action = :action";
  $params['action'] = $filters['action'];
}

if ($filters['sku'] !== '') {
  $where[] = "SKU_Code LIKE :sku";
  $params['sku'] = '%' . $filters['sku'] . '%';
}

if ($filters['loc'] !== '') {
  $like = '%' . $filters['loc'] . '%';
  $where[] = "(OldLocation LIKE :loc_old OR NewLocation LIKE :loc_new)";
  $params['loc_old'] = $like;
  $params['loc_new'] = $like;
}

if ($filters['batch'] !== '') {
  $where[] = "BatchNo LIKE :batch";
  $params['batch'] = '%' . $filters['batch'] . '%';
}

if ($filters['actor'] !== '') {
  $where[] = "Actor LIKE :actor";
  $params['actor'] = '%' . $filters['actor'] . '%';
}

if ($filters['comments'] !== '') {
  $where[] = "Comments LIKE :comments";
  $params['comments'] = '%' . $filters['comments'] . '%';
}

if ($filters['from'] !== '') {
  $where[] = "DATE(CreatedAt) >= :from";
  $params['from'] = $filters['from'];
}

if ($filters['to'] !== '') {
  $where[] = "DATE(CreatedAt) <= :to";
  $params['to'] = $filters['to'];
}

$sql = "
  SELECT *
  FROM producttransactions
" . (count($where) ? " WHERE " . implode(" AND ", $where) : "") . "
  ORDER BY CreatedAt DESC
  LIMIT 1000
";

$stmt = db()->prepare($sql);
$stmt->execute($params);
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

// -------------------------
// Summary
// -------------------------
$totalRows = count($rows);

$counts = [
  'create' => 0,
  'add'    => 0,
  'move'   => 0,
  'update' => 0,
  'delete' => 0,
  'adjust' => 0,
  'import' => 0,
  'other'  => 0,
];

$qtyNet = 0;

foreach ($rows as $r) {
  $a = strtolower((string)($r['Action'] ?? ''));

  if (!isset($counts[$a])) {
    $a = 'other';
  }

  $counts[$a]++;
  $qtyNet += (int)($r['DeltaQty'] ?? 0);
}

// -------------------------
// UI helpers
// -------------------------
function badge_class(string $action): string {
  $a = strtolower($action);

  return match ($a) {
    'create' => 'bg-success',
    'add'    => 'bg-success',
    'move'   => 'bg-primary',
    'update' => 'bg-warning text-dark',
    'adjust' => 'bg-warning text-dark',
    'delete' => 'bg-danger',
    'import' => 'bg-info text-dark',
    default  => 'bg-secondary',
  };
}

function field_flow($before, $after): string {
  $before = trim((string)$before);
  $after  = trim((string)$after);

  if ($before === '' && $after === '') {
    return '—';
  }

  if ($before === $after) {
    return h($after);
  }

  if ($before === '') {
    return h($after);
  }

  if ($after === '') {
    return h($before);
  }

  return h($before) . " → " . h($after);
}

function loc_flow(array $r): string {
  return field_flow(
    $r['OldLocation'] ?? '',
    $r['NewLocation'] ?? ''
  );
}

function qty_flow(array $r): string {
  $before = (int)($r['TotalQty_Before'] ?? 0);
  $after  = (int)($r['TotalQty_After'] ?? 0);

  return number_format($before) . " → " . number_format($after);
}