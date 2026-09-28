<?php
declare(strict_types=1);

/**
 * Read-only reliability/performance surface probe.
 *
 * GET requests only. No authentication, cookies, form submission or mutation.
 * Hard failures are reserved for availability/cache correctness and severe,
 * repeated latency regressions. Normal latency budget misses are warnings to
 * avoid flaky releases caused by transient Internet conditions.
 */

$base = $argv[1] ?? getenv('RELIABILITY_PROBE_BASE_URL') ?: 'https://blockblast-unblocked.io';
$base = rtrim(trim((string) $base), '/');
$parts = parse_url($base);
if (!is_array($parts) || empty($parts['scheme']) || empty($parts['host'])) {
    fwrite(STDERR, "reliability-surface-probe: FAIL\n- invalid base URL\n");
    exit(1);
}
$host = strtolower((string) $parts['host']);
$scheme = strtolower((string) $parts['scheme']);
$isLocal = in_array($host, ['localhost', '127.0.0.1', '::1'], true);
if (!$isLocal && $scheme !== 'https') {
    fwrite(STDERR, "reliability-surface-probe: FAIL\n- remote target must use HTTPS\n");
    exit(1);
}

$curl = trim((string) shell_exec('command -v curl 2>/dev/null'));
if ($curl === '') {
    fwrite(STDERR, "reliability-surface-probe: BLOCKED\n- curl CLI unavailable\n");
    exit(2);
}

$samples = max(2, min(5, (int) (getenv('RELIABILITY_SAMPLES') ?: 3)));
$warnTtfbMs = max(250, (int) (getenv('RELIABILITY_WARN_TTFB_MS') ?: 2500));
$severeTtfbMs = max($warnTtfbMs, (int) (getenv('RELIABILITY_SEVERE_TTFB_MS') ?: 5000));
$errors = [];
$warnings = [];
$results = [];

/** @return array{status:int,headers:array<string,list<string>>,ttfb_ms:float,total_ms:float,size:int,content_type:string} */
function timedGet(string $curl, string $url): array
{
    $headerFile = tempnam(sys_get_temp_dir(), 'rbc_hdr_');
    if ($headerFile === false) {
        throw new RuntimeException('unable to allocate header temp file');
    }
    $format = '%{http_code}\t%{time_starttransfer}\t%{time_total}\t%{size_download}\t%{content_type}';
    $cmd = sprintf(
        '%s -sS --compressed --http1.1 --connect-timeout 10 --max-time 30 -H %s -D %s -o /dev/null -w %s %s',
        escapeshellarg($curl),
        escapeshellarg('Accept-Encoding: br, gzip'),
        escapeshellarg($headerFile),
        escapeshellarg($format),
        escapeshellarg($url)
    );
    $output = [];
    $code = 0;
    exec($cmd . ' 2>&1', $output, $code);
    $rawMetric = trim(implode("\n", $output));
    $rawHeaders = (string) @file_get_contents($headerFile);
    @unlink($headerFile);
    if ($code !== 0) {
        throw new RuntimeException("curl failed for {$url}: {$rawMetric}");
    }
    $parts = explode("\t", $rawMetric, 5);
    if (count($parts) !== 5) {
        throw new RuntimeException("unexpected curl metrics for {$url}: {$rawMetric}");
    }

    $headers = [];
    foreach (preg_split('/\r?\n/', $rawHeaders) ?: [] as $line) {
        if (preg_match('#^HTTP/\S+\s+\d{3}#i', $line)) {
            $headers = [];
            continue;
        }
        if (!str_contains($line, ':')) {
            continue;
        }
        [$name, $value] = explode(':', $line, 2);
        $name = strtolower(trim($name));
        if ($name === '') {
            continue;
        }
        $headers[$name] ??= [];
        $headers[$name][] = trim($value);
    }

    return [
        'status' => (int) $parts[0],
        'headers' => $headers,
        'ttfb_ms' => round(((float) $parts[1]) * 1000, 2),
        'total_ms' => round(((float) $parts[2]) * 1000, 2),
        'size' => (int) round((float) $parts[3]),
        'content_type' => (string) $parts[4],
    ];
}

/** @param array<string,list<string>> $headers */
function headerJoined(array $headers, string $name): string
{
    return implode(', ', $headers[strtolower($name)] ?? []);
}

/** @param list<float> $values */
function percentile(array $values, float $p): float
{
    sort($values, SORT_NUMERIC);
    if ($values === []) {
        return 0.0;
    }
    $index = (int) ceil($p * count($values)) - 1;
    $index = max(0, min(count($values) - 1, $index));
    return (float) $values[$index];
}

$targets = [
    '/' => ['status' => 200, 'type' => 'text/html', 'cache' => 'public'],
    '/manifest.json' => ['status' => 200, 'type' => '', 'cache' => 'public'],
    '/sw.js' => ['status' => 200, 'type' => '', 'cache' => 'revalidate'],
    '/robots.txt' => ['status' => 200, 'type' => 'text/plain', 'cache' => 'public'],
    '/sitemap.xml' => ['status' => 200, 'type' => '', 'cache' => 'public'],
    '/api/health.php' => ['status' => 200, 'type' => 'application/json', 'cache' => 'no-store'],
];

try {
    foreach ($targets as $path => $expect) {
        $run = [];
        for ($i = 0; $i < $samples; $i++) {
            $run[] = timedGet($curl, $base . $path);
        }
        $statuses = array_column($run, 'status');
        foreach ($statuses as $status) {
            if ($status !== $expect['status']) {
                $errors[] = "{$path} expected {$expect['status']} but received {$status}";
                break;
            }
        }
        $type = strtolower((string) $run[0]['content_type']);
        if ($expect['type'] !== '' && !str_contains($type, strtolower($expect['type']))) {
            $errors[] = "{$path} content type mismatch: {$type}";
        }

        $cache = strtolower(headerJoined($run[0]['headers'], 'cache-control'));
        if ($expect['cache'] === 'public' && !str_contains($cache, 'public')) {
            $errors[] = "{$path} expected public cache semantics, got: {$cache}";
        }
        if ($expect['cache'] === 'no-store' && !str_contains($cache, 'no-store')) {
            $errors[] = "{$path} expected no-store, got: {$cache}";
        }
        if ($expect['cache'] === 'revalidate' && !(str_contains($cache, 'no-cache') || str_contains($cache, 'must-revalidate'))) {
            $errors[] = "{$path} service-worker cache contract missing revalidation: {$cache}";
        }

        $ttfb = array_map('floatval', array_column($run, 'ttfb_ms'));
        $total = array_map('floatval', array_column($run, 'total_ms'));
        $p50 = percentile($ttfb, 0.50);
        $p95 = percentile($ttfb, 0.95);
        $severeCount = count(array_filter($ttfb, static fn(float $v): bool => $v > $severeTtfbMs));
        if ($p95 > $warnTtfbMs) {
            $warnings[] = sprintf('%s p95 TTFB %.2fms exceeds warning budget %dms', $path, $p95, $warnTtfbMs);
        }
        if ($severeCount === count($ttfb)) {
            $errors[] = sprintf('%s all %d samples exceeded severe TTFB %dms', $path, count($ttfb), $severeTtfbMs);
        }
        $results[$path] = [
            'samples' => $samples,
            'ttfb_p50_ms' => $p50,
            'ttfb_p95_ms' => $p95,
            'total_p95_ms' => percentile($total, 0.95),
            'bytes_last_sample' => $run[count($run) - 1]['size'],
            'cache_control' => $cache,
        ];
    }

    $missingPath = '/__rbc_reliability_missing_' . substr(hash('sha256', $base), 0, 12);
    $missing = timedGet($curl, $base . $missingPath);
    if ($missing['status'] !== 404) {
        $errors[] = "missing-route contract expected 404, got {$missing['status']}";
    }
    $missingCache = strtolower(headerJoined($missing['headers'], 'cache-control'));
    if (!str_contains($missingCache, 'no-store')) {
        $errors[] = '404 HTML must be no-store; got: ' . $missingCache;
    }
} catch (Throwable $e) {
    $errors[] = $e->getMessage();
}

$summary = [
    'target' => $base,
    'samples_per_route' => $samples,
    'warning_ttfb_ms' => $warnTtfbMs,
    'severe_ttfb_ms' => $severeTtfbMs,
    'results' => $results,
    'warnings' => $warnings,
    'failed_checks' => count($errors),
];

echo 'reliability-surface-probe: ' . ($errors === [] ? 'PASS' : 'FAIL') . PHP_EOL;
echo json_encode($summary, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL;
if ($errors !== []) {
    fwrite(STDERR, "\nFailures:\n- " . implode("\n- ", $errors) . "\n");
    exit(1);
}
exit(0);
