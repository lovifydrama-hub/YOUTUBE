<?php
declare(strict_types=1);

$requestContext = file_get_contents(__DIR__ . '/../includes/request-context.php');
$cfg = file_get_contents(__DIR__ . '/../config/config.php');
$health = file_get_contents(__DIR__ . '/../api/health.php');
if ($requestContext === false || $cfg === false || $health === false) {
    fwrite(STDERR, "FAIL unable to read exact snapshot files\n");
    exit(1);
}

$requiredRequestContextNeedles = [
    "bin2hex(random_bytes(16))",
    "header('X-Request-ID: ' . rbc_request_id())",
    "preg_match('#^/(?:api|admin|antihacker)(?:/|$)#i'",
];
foreach ($requiredRequestContextNeedles as $needle) {
    if (strpos($requestContext, $needle) === false) {
        fwrite(STDERR, "FAIL request-context invariant missing: {$needle}\n");
        exit(1);
    }
}

foreach (['HTTP_X_REQUEST_ID', 'HTTP_X_CORRELATION_ID', 'HTTP_X_REQUESTID'] as $clientHeaderKey) {
    if (stripos($requestContext, $clientHeaderKey) !== false) {
        fwrite(STDERR, "FAIL request-context reads inbound correlation header: {$clientHeaderKey}\n");
        exit(1);
    }
}

$requiredLiveConfigNeedles = [
    '$requestPath = parse_url((string) ($_SERVER[\'REQUEST_URI\'] ?? \'/\'), PHP_URL_PATH);',
    '$isAdmin = preg_match(\'#^/(?:antihacker|admin)(?:/|$)#i\', $requestPath) === 1;',
    '$isApi = preg_match(\'#^/api(?:/|$)#i\', $requestPath) === 1;',
    '$rawSlug = $_GET[\'slug\'] ?? \'\';',
    '$slug = is_string($rawSlug) ? $rawSlug : \'\';',
];
foreach ($requiredLiveConfigNeedles as $needle) {
    if (strpos($cfg, $needle) === false) {
        fwrite(STDERR, "FAIL live config hotfix invariant missing: {$needle}\n");
        exit(1);
    }
}

$requiredLiveHealthNeedles = [
    "if (strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET')) !== 'GET')",
    "'code' => 'E001'",
    "define('DB_NO_MAINTENANCE_RESPONSE', true);",
    "catch (Throwable $e)",
    "header('Retry-After: 60');",
];
foreach ($requiredLiveHealthNeedles as $needle) {
    if (strpos($health, $needle) === false) {
        fwrite(STDERR, "FAIL live health hotfix invariant missing: {$needle}\n");
        exit(1);
    }
}

$requestContextRequire = "require_once dirname(__DIR__) . '/includes/request-context.php';";
$errorHandlerRequire = "require_once __DIR__ . '/error-handler.php';";
$reqPos = strpos($cfg, $requestContextRequire);
$errPos = strpos($cfg, $errorHandlerRequire);
if ($reqPos === false || $errPos === false || $reqPos >= $errPos) {
    fwrite(STDERR, "FAIL config integration: exact request-context require must load before exact error-handler require\n");
    exit(1);
}

$configRequire = "require_once dirname(__DIR__) . '/config/config.php';";
$forceCallNeedle = "rbc_emit_request_id_header(true);";
$configBootstrap = strpos($health, $configRequire);
$forceCall = strpos($health, $forceCallNeedle);
$functionsRequire = "require_once dirname(__DIR__) . '/includes/functions.php';";
$functionsBootstrap = strpos($health, $functionsRequire);
$dbGuard = strpos($health, "define('DB_NO_MAINTENANCE_RESPONSE', true);");
if (
    $configBootstrap === false
    || $forceCall === false
    || $functionsBootstrap === false
    || $dbGuard === false
    || $forceCall <= $configBootstrap
    || $forceCall >= $functionsBootstrap
    || $dbGuard >= $functionsBootstrap
) {
    fwrite(STDERR, "FAIL health integration ordering for request-id and live DB-outage contract\n");
    exit(1);
}

echo "PASS static security and integration invariants\n";
