<?php
declare(strict_types=1);

$path = parse_url((string)($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH) ?: '/';
require_once __DIR__ . '/../includes/request-context.php';

if ($path === '/health') {
    rbc_emit_request_id_header(true);
}

$id1 = rbc_request_id();
$id2 = rbc_request_id();

header('Content-Type: application/json; charset=utf-8');
echo json_encode([
    'path' => $path,
    'id1' => $id1,
    'id2' => $id2,
    'same' => hash_equals($id1, $id2),
], JSON_UNESCAPED_SLASHES);
