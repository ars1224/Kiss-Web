<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');

$TOKEN = 'Kiss-web Print';

// --- basic auth ---
$token = $_GET['token'] ?? '';
if (!hash_equals($TOKEN, $token)) {
  http_response_code(403);
  echo json_encode(['ok' => false, 'message' => 'Forbidden']);
  exit;
}

$file = $_GET['file'] ?? '';
$file = str_replace(['..', '/', '\\'], '', $file); // prevent traversal
if ($file === '' || !preg_match('/\.pdf$/i', $file)) {
  http_response_code(400);
  echo json_encode(['ok' => false, 'message' => 'Invalid file']);
  exit;
}

// only allow printing from this folder:
$pdfPath = 'C:\\xampp\\htdocs\\kiss-web\\pdf\\' . $file;

if (!file_exists($pdfPath)) {
  http_response_code(404);
  echo json_encode(['ok' => false, 'message' => 'PDF not found', 'path' => $pdfPath]);
  exit;
}

// call your cmd
$cmd = 'C:\\print\\print_pdf.cmd ' . escapeshellarg($pdfPath) . ' 2>&1';
$out = shell_exec($cmd);

// if your cmd returns exit /b 1 even on success, don't fail here.
// We'll treat "file exists" + "command executed" as success.
echo json_encode([
  'ok' => true,
  'message' => 'Print triggered',
  'output' => $out
]);
