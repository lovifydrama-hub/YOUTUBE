<?php
/**
 * Health Check Endpoint (HARDENED)
 *
 * PUBLIC response: {"status":"ok"} — no internal details leaked.
 * ADMIN response (with ?detail=1 + valid session): full diagnostics.
 *
 * Rate limited: 10 req/min per IP.
 *
 * @since 2026-04-05 (Phase 6: Health Endpoint)
 * @hardened 2026-04-08 — Removed public exposure of PHP version,
 *   extensions, disk space, and writable directories per external audit.
 */

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate');
header('X-Content-Type-Options: nosniff');
header('X-Robots-Tag: noindex, nofollow');

// Only allow GET
if (strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET')) !== 'GET') {
    http_response_code(405);
    header('Allow: GET');
    echo json_encode([
        'success' => false,
        'error' => 'Method not allowed',
        'message' => 'Method not allowed',
        'code' => 'E001',
    ]);
    exit;
}

// Must be defined before functions.php, which loads the DB connection. This
// keeps a local/remote DB outage inside the JSON 503 contract.
if (!defined('DB_NO_MAINTENANCE_RESPONSE')) {
    define('DB_NO_MAINTENANCE_RESPONSE', true);
}

$healthStart = microtime(true);

// The shared functions bootstrap also loads the database connection. Guard
// that dependency load here so an unavailable DB cannot escape as a global
// maintenance body or an uncaught JSON 500.
require_once dirname(__DIR__) . '/config/config.php';
if (function_exists('rbc_emit_request_id_header')) {
    rbc_emit_request_id_header(true);
}
try {
    require_once dirname(__DIR__) . '/includes/functions.php';
    require_once dirname(__DIR__) . '/includes/observability.php';
    require_once dirname(__DIR__) . '/includes/rate-limiter.php';
} catch (Throwable $e) {
    error_log('[HEALTH] dependency bootstrap failed');
    http_response_code(503);
    header('Retry-After: 60');
    echo json_encode(['status' => 'degraded']);
    exit;
}

// Rate limiting (10 req/min)
if (!checkAvailabilityRateLimit('health_check', 10, 60)) {
    http_response_code(429);
    echo json_encode(['error' => 'Too many requests']);
    exit;
}

// ----- PUBLIC RESPONSE: minimal status only -----
$health = [
    'status' => 'ok',
];
$diagnostics = [
    'time' => date('c'),
];
$wantDetail = isset($_GET['detail']) && $_GET['detail'] === '1';

// Check database (always — core functionality)
try {
    if (!defined('DB_NO_MAINTENANCE_RESPONSE')) {
        define('DB_NO_MAINTENANCE_RESPONSE', true);
    }
    require_once dirname(__DIR__) . '/config/database.php';
    if (isset($pdo)) {
        $stmt = $pdo->query('SELECT 1');
        $diagnostics['db'] = $stmt ? 'ok' : 'error';
    } else {
        $diagnostics['db'] = 'error';
    }
} catch (Exception $e) {
    $diagnostics['db'] = 'error';
    $health['status'] = 'degraded';
    error_log('[HEALTH] DB check failed: ' . $e->getMessage());
}

// ----- ADMIN DETAIL: only for authenticated admin sessions -----
if ($wantDetail) {
    require_once dirname(__DIR__) . '/admin/auth.php';
    $health = array_merge($health, $diagnostics);

        // Cache writes are admin-detail only; public health stays read-only.
        try {
            if (function_exists('cache_set') && function_exists('cache_get')) {
                $testKey = 'health_' . bin2hex(random_bytes(4));
                cache_set($testKey, 'ok', 5);
                $health['cache'] = (cache_get($testKey) === 'ok') ? 'ok' : 'error';
                if (function_exists('cache_delete')) cache_delete($testKey);
            } else {
                $health['cache'] = 'ok'; // file cache assumed working
            }
        } catch (Exception $e) {
            $health['cache'] = 'error';
            error_log('[HEALTH] Cache check failed: ' . $e->getMessage());
        }

        $health['version'] = defined('APP_VERSION') ? APP_VERSION : 'unknown';
        $health['php'] = PHP_MAJOR_VERSION . '.' . PHP_MINOR_VERSION; // major.minor only

        // Disk space
        $uploadDir = defined('UPLOAD_DIR') ? UPLOAD_DIR : dirname(__DIR__) . '/uploads';
        if (is_dir($uploadDir)) {
            $freeBytes = @disk_free_space($uploadDir);
            if ($freeBytes !== false) {
                $health['disk_free_mb'] = round($freeBytes / 1048576);
                if ($freeBytes < 104857600) {
                    $health['disk_warning'] = 'low_space';
                }
            }
        }

        // Writable directories
        $writableDirs = ['cache', 'uploads'];
        $health['writable'] = [];
        foreach ($writableDirs as $dir) {
            $fullPath = dirname(__DIR__) . '/' . $dir;
            $health['writable'][$dir] = (is_dir($fullPath) && is_writable($fullPath)) ? 'ok' : 'error';
        }

        // Extensions (minimal)
    $health['extensions'] = [
        'pdo_mysql' => extension_loaded('pdo_mysql'),
        'mbstring'  => extension_loaded('mbstring'),
    ];
}

http_response_code($health['status'] === 'ok' ? 200 : 503);
observability_timing('api.health', (microtime(true) - $healthStart) * 1000, [
    'status' => $health['status'],
    'detail' => $wantDetail ? 'requested' : 'public',
]);
echo json_encode($health, JSON_UNESCAPED_UNICODE);