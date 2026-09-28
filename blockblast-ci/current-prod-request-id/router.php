<?php
require __DIR__ . '/includes/request-context.php';

$path = parse_url((string) ($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH) ?: '/';
if ($path === '/health-force') {
    rbc_emit_request_id_header(true);
}
header('Content-Type: text/plain; charset=UTF-8');
echo rbc_request_id();
