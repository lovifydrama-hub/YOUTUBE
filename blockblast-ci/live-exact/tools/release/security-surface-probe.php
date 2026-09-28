<?php
declare(strict_types=1);

/**
 * Read-only HTTP security surface certification.
 *
 * Usage:
 *   php tools/release/security-surface-probe.php https://blockblast-unblocked.io
 *   SECURITY_PROBE_BASE_URL=https://staging.example.com php tools/release/security-surface-probe.php
 *
 * The probe performs GET/TRACE requests only and never submits forms or mutates
 * application state. It validates the combined hosting + application headers.
 */

$base = $argv[1] ?? getenv('SECURITY_PROBE_BASE_URL') ?: 'https://blockblast-unblocked.io';
$base = rtrim(trim((string) $base), '/');
$parts = parse_url($base);
if (!is_array($parts) || empty($parts['scheme']) || empty($parts['host'])) {
    fwrite(STDERR, "security-surface-probe: FAIL\n- invalid base URL\n");
    exit(1);
}
$scheme = strtolower((string) $parts['scheme']);
$host = strtolower((string) $parts['host']);
$isLocal = in_array($host, ['localhost', '127.0.0.1', '::1'], true);
if (!$isLocal && $scheme !== 'https') {
    fwrite(STDERR, "security-surface-probe: FAIL\n- remote target must use HTTPS\n");
    exit(1);
}

$curl = trim((string) shell_exec('command -v curl 2>/dev/null'));
if ($curl === '') {
    fwrite(STDERR, "security-surface-probe: BLOCKED\n- curl CLI unavailable\n");
    exit(2);
}

/** @return array{status:int,headers:array<string,list<string>>,raw:string} */
function probeRequest(string $curl, string $url, string $method = 'GET'): array
{
    $cmd = [
        escapeshellarg($curl), '-sS', '--http1.1', '--connect-timeout', '10', '--max-time', '25',
        '-X', escapeshellarg($method), '-D', '-', '-o', '/dev/null', '--max-redirs', '0',
        escapeshellarg($url),
    ];
    $output = [];
    $code = 0;
    exec(implode(' ', $cmd) . ' 2>&1', $output, $code);
    $raw = implode("\n", $output);
    if ($code !== 0) {
        throw new RuntimeException("curl failed for {$method} {$url}: {$raw}");
    }

    $status = 0;
    $headers = [];
    foreach ($output as $line) {
        if (preg_match('#^HTTP/\S+\s+(\d{3})#i', $line, $m)) {
            $status = (int) $m[1];
            $headers = [];
            continue;
        }
        if (!str_contains($line, ':')) {
            continue;
        }
        [$name, $value] = explode(':', $line, 2);
        $name = strtolower(trim($name));
        $value = trim($value);
        if ($name === '') {
            continue;
        }
        $headers[$name] ??= [];
        $headers[$name][] = $value;
    }

    return ['status' => $status, 'headers' => $headers, 'raw' => $raw];
}

/** @param array<string,list<string>> $headers */
function headerValues(array $headers, string $name): array
{
    return $headers[strtolower($name)] ?? [];
}

/** @param array<string,list<string>> $headers */
function headerJoined(array $headers, string $name): string
{
    return implode(', ', headerValues($headers, $name));
}

$errors = [];
$warnings = [];
$passes = [];
$check = static function (bool $ok, string $pass, string $fail) use (&$errors, &$passes): void {
    if ($ok) {
        $passes[] = $pass;
    } else {
        $errors[] = $fail;
    }
};

try {
    $home = probeRequest($curl, $base . '/');
    $check($home['status'] === 200, 'homepage status 200', 'homepage must return 200, got ' . $home['status']);
    $check(stripos(headerJoined($home['headers'], 'content-type'), 'text/html') !== false, 'homepage HTML content type', 'homepage Content-Type must be HTML');
    $check(stripos(headerJoined($home['headers'], 'x-content-type-options'), 'nosniff') !== false, 'nosniff', 'X-Content-Type-Options: nosniff missing');
    $check(stripos(headerJoined($home['headers'], 'referrer-policy'), 'strict-origin-when-cross-origin') !== false, 'referrer policy', 'Referrer-Policy mismatch');
    $permissions = headerJoined($home['headers'], 'permissions-policy');
    $check($permissions !== '' && str_contains($permissions, 'camera=()') && str_contains($permissions, 'microphone=()') && str_contains($permissions, 'geolocation=()'), 'permissions policy', 'Permissions-Policy must disable camera/microphone/geolocation');
    $csp = headerJoined($home['headers'], 'content-security-policy');
    $check($csp !== '' && str_contains($csp, "object-src 'none'") && str_contains($csp, "base-uri 'self'") && str_contains($csp, "frame-ancestors 'self'"), 'CSP baseline', 'CSP missing required object-src/base-uri/frame-ancestors directives');
    if (!$isLocal) {
        $hsts = headerJoined($home['headers'], 'strict-transport-security');
        $check($hsts !== '' && stripos($hsts, 'max-age=') !== false && stripos($hsts, 'includesubdomains') !== false, 'HSTS', 'HSTS missing or incomplete');
    }
    $check(headerJoined($home['headers'], 'x-powered-by') === '', 'X-Powered-By absent', 'X-Powered-By must not be exposed');
    $check(headerValues($home['headers'], 'set-cookie') === [], 'homepage sessionless', 'homepage GET must not create Set-Cookie/session state');

    $health = probeRequest($curl, $base . '/api/health.php');
    $check($health['status'] === 200, 'health status 200', 'health endpoint must return 200 for certification, got ' . $health['status']);
    $check(stripos(headerJoined($health['headers'], 'content-type'), 'application/json') !== false, 'health JSON', 'health endpoint Content-Type must be JSON');
    $check(stripos(headerJoined($health['headers'], 'cache-control'), 'no-store') !== false, 'health no-store', 'health endpoint must be no-store');
    $check(stripos(headerJoined($health['headers'], 'x-robots-tag'), 'noindex') !== false, 'health noindex', 'health endpoint must be noindex');
    $healthRequestId = headerJoined($health['headers'], 'x-request-id');
    $check((bool) preg_match('/^[a-f0-9]{32}$/', $healthRequestId), 'health request id', 'health endpoint must expose one server-controlled 128-bit request id');
    $check(headerValues($health['headers'], 'set-cookie') === [], 'health sessionless', 'health GET must not create Set-Cookie/session state');

    $admin = probeRequest($curl, $base . '/antihacker/login.php');
    $check(in_array($admin['status'], [200, 302, 303], true), 'admin login reachable', 'admin login returned unexpected status ' . $admin['status']);
    $adminCache = headerJoined($admin['headers'], 'cache-control');
    $check(stripos($adminCache, 'no-store') !== false, 'admin no-store', 'Admin responses must include Cache-Control no-store');
    $check(stripos(headerJoined($admin['headers'], 'x-robots-tag'), 'noindex') !== false, 'admin noindex', 'Admin responses must be noindex');
    $adminRequestId = headerJoined($admin['headers'], 'x-request-id');
    $check((bool) preg_match('/^[a-f0-9]{32}$/', $adminRequestId), 'admin request id', 'Admin responses must expose one server-controlled 128-bit request id');
    $adminCsp = headerJoined($admin['headers'], 'content-security-policy');
    $check($adminCsp !== '' && str_contains($adminCsp, "frame-ancestors 'self'"), 'admin anti-clickjacking CSP', 'Admin CSP frame-ancestors self missing');

    $trace = probeRequest($curl, $base . '/', 'TRACE');
    $check(!in_array($trace['status'], [200, 201, 202, 204], true), 'TRACE blocked', 'TRACE must not be accepted with a success status');

    if (headerJoined($home['headers'], 'server') !== '') {
        $warnings[] = 'Server header is present; hosting layers may inject it even when application config removes it.';
    }
} catch (Throwable $e) {
    $errors[] = $e->getMessage();
}

$summary = [
    'target' => $base,
    'passed_checks' => count($passes),
    'warnings' => $warnings,
    'failed_checks' => count($errors),
];

echo 'security-surface-probe: ' . ($errors === [] ? 'PASS' : 'FAIL') . PHP_EOL;
echo json_encode($summary, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL;
if ($errors !== []) {
    fwrite(STDERR, "\nFailures:\n- " . implode("\n- ", $errors) . "\n");
    exit(1);
}
exit(0);
