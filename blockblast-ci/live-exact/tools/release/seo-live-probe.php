<?php
declare(strict_types=1);

/**
 * Read-only live Technical SEO / Indexability Certification.
 *
 * GET requests only, no cookies, no authentication, no mutation. The probe
 * validates the effective HTTP + HTML/XML behavior after CDN/hosting layers.
 */

$root = dirname(__DIR__, 2);
$baselinePath = $root . '/tools/release/seo-indexability-baseline.json';
$baseline = json_decode((string) @file_get_contents($baselinePath), true);
if (!is_array($baseline)) {
    fwrite(STDERR, "seo-live-probe: FAIL\n- invalid SEO baseline\n");
    exit(1);
}

$base = $argv[1] ?? getenv('SEO_PROBE_BASE_URL') ?: (string) ($baseline['site_origin'] ?? 'https://blockblast-unblocked.io');
$base = rtrim(trim((string) $base), '/');
$parts = parse_url($base);
if (!is_array($parts) || empty($parts['scheme']) || empty($parts['host'])) {
    fwrite(STDERR, "seo-live-probe: FAIL\n- invalid base URL\n");
    exit(1);
}
$scheme = strtolower((string) $parts['scheme']);
$host = strtolower((string) $parts['host']);
$isLocal = in_array($host, ['localhost', '127.0.0.1', '::1'], true);
if (!$isLocal && $scheme !== 'https') {
    fwrite(STDERR, "seo-live-probe: FAIL\n- remote target must use HTTPS\n");
    exit(1);
}

$curl = trim((string) shell_exec('command -v curl 2>/dev/null'));
if ($curl === '') {
    fwrite(STDERR, "seo-live-probe: BLOCKED\n- curl CLI unavailable\n");
    exit(2);
}

/** @return array{status:int,headers:array<string,list<string>>,body:string,url:string} */
function seoGet(string $curl, string $url): array
{
    $headerFile = tempnam(sys_get_temp_dir(), 'rbc_seo_hdr_');
    $bodyFile = tempnam(sys_get_temp_dir(), 'rbc_seo_body_');
    if ($headerFile === false || $bodyFile === false) {
        throw new RuntimeException('unable to allocate temporary files');
    }
    try {
        $cmd = sprintf(
            '%s -sS --compressed --http1.1 --connect-timeout 10 --max-time 30 --max-redirs 0 -A %s -D %s -o %s -w %s %s',
            escapeshellarg($curl),
            escapeshellarg('BlockBlast-SEO-Certification/1.0'),
            escapeshellarg($headerFile),
            escapeshellarg($bodyFile),
            escapeshellarg('%{http_code}'),
            escapeshellarg($url)
        );
        $output = [];
        $code = 0;
        exec($cmd . ' 2>&1', $output, $code);
        if ($code !== 0) {
            throw new RuntimeException('curl failed for ' . $url . ': ' . implode("\n", $output));
        }
        $status = (int) trim(implode("\n", $output));
        $rawHeaders = (string) @file_get_contents($headerFile);
        $body = (string) @file_get_contents($bodyFile);
        $headers = [];
        foreach (preg_split('/\r?\n/', $rawHeaders) ?: [] as $line) {
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
        return ['status' => $status, 'headers' => $headers, 'body' => $body, 'url' => $url];
    } finally {
        @unlink($headerFile);
        @unlink($bodyFile);
    }
}

/** @param array<string,list<string>> $headers */
function seoHeader(array $headers, string $name): string
{
    return implode(', ', $headers[strtolower($name)] ?? []);
}

function seoAttr(string $html, string $tagPattern, string $attr): ?string
{
    if (!preg_match($tagPattern, $html, $m)) {
        return null;
    }
    $tag = $m[0];
    if (preg_match('/\b' . preg_quote($attr, '/') . '\s*=\s*(["\'])(.*?)\1/is', $tag, $a)) {
        return html_entity_decode(trim($a[2]), ENT_QUOTES | ENT_HTML5, 'UTF-8');
    }
    return null;
}

function seoCanonical(string $html): ?string
{
    return seoAttr($html, '/<link\b[^>]*\brel\s*=\s*(["\'])canonical\1[^>]*>/is', 'href');
}

function seoMetaRobots(string $html): string
{
    $value = seoAttr($html, '/<meta\b[^>]*\bname\s*=\s*(["\'])robots\1[^>]*>/is', 'content');
    return strtolower((string) $value);
}

function seoResolveExpected(string $base, string $expected): string
{
    if (preg_match('#^https?://#i', $expected)) {
        return rtrim($expected, '/');
    }
    if ($expected === '/') {
        return rtrim($base, '/') . '/';
    }
    return rtrim($base, '/') . '/' . ltrim($expected, '/');
}

function seoNormalizeAbsolute(string $url): string
{
    $parts = parse_url($url);
    if (!is_array($parts) || empty($parts['scheme']) || empty($parts['host'])) {
        return $url;
    }
    $scheme = strtolower((string) $parts['scheme']);
    $host = strtolower((string) $parts['host']);
    $port = isset($parts['port']) ? ':' . $parts['port'] : '';
    $path = $parts['path'] ?? '/';
    if ($path === '') {
        $path = '/';
    }
    $query = isset($parts['query']) ? '?' . $parts['query'] : '';
    return $scheme . '://' . $host . $port . $path . $query;
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
    foreach ((array) ($baseline['routes'] ?? []) as $route) {
        if (!is_array($route) || empty($route['path']) || !isset($route['status'])) {
            $errors[] = 'invalid route entry in baseline';
            continue;
        }
        $name = (string) ($route['name'] ?? $route['path']);
        $response = seoGet($curl, $base . (string) $route['path']);
        $check($response['status'] === (int) $route['status'], $name . ' status', $name . ' expected status ' . $route['status'] . ', got ' . $response['status']);

        if (isset($route['location'])) {
            $location = seoHeader($response['headers'], 'location');
            $expected = seoResolveExpected($base, (string) $route['location']);
            $actual = $location === '' ? '' : seoNormalizeAbsolute(seoResolveExpected($base, $location));
            $check($actual === seoNormalizeAbsolute($expected), $name . ' redirect location', $name . ' redirect mismatch: ' . $location . ' expected ' . $expected);
        }

        if (isset($route['canonical'])) {
            $canonical = seoCanonical($response['body']);
            $expected = seoResolveExpected($base, (string) $route['canonical']);
            $check($canonical !== null && seoNormalizeAbsolute($canonical) === seoNormalizeAbsolute($expected), $name . ' canonical', $name . ' canonical mismatch: ' . ($canonical ?? '[missing]') . ' expected ' . $expected);
            if ($canonical !== null) {
                $cParts = parse_url($canonical);
                $check(!isset($cParts['query']) && !isset($cParts['fragment']), $name . ' canonical clean', $name . ' canonical must not contain query/fragment: ' . $canonical);
            }
        }

        $robots = seoMetaRobots($response['body']);
        $xRobots = strtolower(seoHeader($response['headers'], 'x-robots-tag'));
        $combinedRobots = trim($robots . ', ' . $xRobots, ', ');
        foreach ((array) ($route['robots_must_contain'] ?? []) as $token) {
            $check(str_contains($combinedRobots, strtolower((string) $token)), $name . ' robots contains ' . $token, $name . ' robots missing ' . $token . ': ' . $combinedRobots);
        }
        foreach ((array) ($route['robots_must_not_contain'] ?? []) as $token) {
            $check(!str_contains($combinedRobots, strtolower((string) $token)), $name . ' robots excludes ' . $token, $name . ' robots unexpectedly contains ' . $token . ': ' . $combinedRobots);
        }
    }

    // Missing URL: must be a true 404, noindex, and must not self-canonicalize.
    $missingPath = '/__rbc_seo_missing_' . substr(hash('sha256', $base), 0, 12);
    $missing = seoGet($curl, $base . $missingPath);
    $check($missing['status'] === 404, 'missing URL true 404', 'missing URL must return 404, got ' . $missing['status']);
    $missingRobots = strtolower(seoMetaRobots($missing['body']) . ', ' . seoHeader($missing['headers'], 'x-robots-tag'));
    $check(str_contains($missingRobots, 'noindex'), 'missing URL noindex', 'missing URL must be noindex');
    $check(seoCanonical($missing['body']) === null, 'missing URL has no canonical', '404 must not self-canonicalize arbitrary missing URL');

    // robots.txt live contract.
    $robotsResponse = seoGet($curl, $base . '/robots.txt');
    $check($robotsResponse['status'] === 200, 'robots status 200', 'robots.txt must return 200');
    $robotsBody = $robotsResponse['body'];
    $check(str_contains($robotsBody, 'User-agent: *'), 'robots wildcard', 'robots wildcard user-agent missing');
    $check(!preg_match('/^\s*Disallow:\s*\/\s*$/mi', $robotsBody), 'robots does not block root', 'robots.txt globally disallows root');
    foreach ((array) ($baseline['sitemaps'] ?? []) as $sitemapPath) {
        $expectedLine = 'Sitemap: ' . rtrim($base, '/') . $sitemapPath;
        $check(str_contains($robotsBody, $expectedLine), 'robots declares ' . $sitemapPath, 'robots.txt missing ' . $expectedLine);
    }

    // Sitemap XML hygiene. We intentionally validate each file independently;
    // aggregate sitemap.xml may overlap child sitemaps by design.
    foreach ((array) ($baseline['sitemaps'] ?? []) as $sitemapPath) {
        $response = seoGet($curl, $base . $sitemapPath);
        $check($response['status'] === 200, $sitemapPath . ' status', $sitemapPath . ' expected 200, got ' . $response['status']);
        $body = $response['body'];
        $isXml = str_contains(strtolower(seoHeader($response['headers'], 'content-type')), 'xml') || str_starts_with(ltrim($body), '<?xml');
        $check($isXml, $sitemapPath . ' XML content', $sitemapPath . ' does not look like XML');
        preg_match_all('#<loc>\s*([^<]+?)\s*</loc>#i', $body, $m);
        $locs = array_map(static fn(string $v): string => html_entity_decode(trim($v), ENT_QUOTES | ENT_XML1, 'UTF-8'), $m[1] ?? []);
        $check($locs !== [], $sitemapPath . ' has loc entries', $sitemapPath . ' has no <loc> entries');
        if (count($locs) !== count(array_unique($locs))) {
            $errors[] = $sitemapPath . ' contains duplicate <loc> URLs';
        } else {
            $passes[] = $sitemapPath . ' locs unique';
        }
        foreach ($locs as $loc) {
            $p = parse_url($loc);
            $locScheme = strtolower((string) ($p['scheme'] ?? ''));
            $locHost = strtolower((string) ($p['host'] ?? ''));
            $schemeMatches = $isLocal ? $locScheme === $scheme : $locScheme === 'https';
            if (!is_array($p) || !$schemeMatches || $locHost !== $host) {
                $errors[] = $sitemapPath . ' contains non-canonical-host URL: ' . $loc;
                continue;
            }
            if (isset($p['query']) || isset($p['fragment'])) {
                $errors[] = $sitemapPath . ' contains query/fragment URL: ' . $loc;
            }
            $path = (string) ($p['path'] ?? '/');
            if (preg_match('#^/(?:admin|antihacker|api|config|includes|database|cache|search)(?:/|$)#i', $path)) {
                $errors[] = $sitemapPath . ' contains blocked/non-index target: ' . $loc;
            }
            if ($path === '/block-blast') {
                $errors[] = $sitemapPath . ' contains redirected flagship URL /block-blast';
            }
            if (str_ends_with($path, '.embed')) {
                $errors[] = $sitemapPath . ' contains noindex embed URL: ' . $loc;
            }
        }
    }

    // Sitemap index is a compatibility surface even though robots currently lists
    // child sitemaps directly.
    $indexResponse = seoGet($curl, $base . '/sitemap-index.xml');
    $check($indexResponse['status'] === 200, 'sitemap-index status', 'sitemap-index.xml must return 200');
    foreach (['/sitemap-games.xml', '/sitemap-taxonomies.xml', '/sitemap-pages.xml', '/sitemap-editorial.xml'] as $child) {
        $check(str_contains($indexResponse['body'], rtrim($base, '/') . $child), 'sitemap-index child ' . $child, 'sitemap-index.xml missing child ' . $child);
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

echo 'seo-live-probe: ' . ($errors === [] ? 'PASS' : 'FAIL') . PHP_EOL;
echo json_encode($summary, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL;
if ($errors !== []) {
    fwrite(STDERR, "\nFailures:\n- " . implode("\n- ", $errors) . "\n");
    exit(1);
}
exit(0);
