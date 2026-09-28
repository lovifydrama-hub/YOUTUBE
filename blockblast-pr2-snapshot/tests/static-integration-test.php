<?php
declare(strict_types=1);

$cfg = file_get_contents(__DIR__ . '/../config/config.php');
$health = file_get_contents(__DIR__ . '/../api/health.php');
if ($cfg === false || $health === false) {
    fwrite(STDERR, "FAIL unable to read exact snapshot files\n");
    exit(1);
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
if ($configBootstrap === false || $forceCall === false || $forceCall <= $configBootstrap) {
    fwrite(STDERR, "FAIL health integration: force request ID must occur after exact config bootstrap require\n");
    exit(1);
}

echo "PASS static integration ordering\n";
