<?php
declare(strict_types=1);

$cfg = file_get_contents(__DIR__ . '/../config/config.php');
$health = file_get_contents(__DIR__ . '/../api/health.php');
if ($cfg === false || $health === false) {
    fwrite(STDERR, "FAIL unable to read exact snapshot files\n");
    exit(1);
}

$reqPos = strpos($cfg, "includes/request-context.php");
$errPos = strpos($cfg, "error-handler.php");
if ($reqPos === false || $errPos === false || $reqPos >= $errPos) {
    fwrite(STDERR, "FAIL config integration: request-context must load before error-handler\n");
    exit(1);
}

$configBootstrap = strpos($health, "config/config.php");
$forceCall = strpos($health, "rbc_emit_request_id_header(true)");
if ($configBootstrap === false || $forceCall === false || $forceCall <= $configBootstrap) {
    fwrite(STDERR, "FAIL health integration: force request ID must occur after config bootstrap\n");
    exit(1);
}

echo "PASS static integration ordering\n";
