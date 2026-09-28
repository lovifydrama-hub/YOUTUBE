<?php
/**
 * GLOBAL CONFIGURATION
 * Optimized for blockblast-unblocked.io (Hostinger Deployment)
 * -------------------------------------------------------------
 * This file handles URLs, Paths, Sessions, and Environment Settings.
 */

if (PHP_SAPI !== 'cli' && realpath((string) ($_SERVER['SCRIPT_FILENAME'] ?? '')) === __FILE__) {
    http_response_code(404);
    header('Content-Type: text/plain; charset=UTF-8');
    exit;
}

// Load .env file if present (Docker / staging environments)
// Placed before error-handler.php so env vars are available to all includes
$_envPaths = [
    dirname(__DIR__) . '/.env',
    __DIR__ . '/.env',
];
foreach ($_envPaths as $_envPath) {
if (is_file($_envPath)) {
    $lines = file($_envPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    if (is_array($lines)) {
        foreach ($lines as $_line) {
            $_line = trim($_line);
            if ($_line === '' || $_line[0] === '#' || strpos($_line, '=') === false) {
                continue;
            }
            [$_name, $_val] = explode('=', $_line, 2);
            $_name = trim($_name);
            $_val = trim($_val, " \t\"'");
            if ($_name !== '' && getenv($_name) === false) {
                putenv("{$_name}={$_val}");
                $_ENV[$_name] = $_val;
                $_SERVER[$_name] = $_val;
            }
        }
    }
}
}
unset($_envPaths, $_envPath, $lines, $_line, $_name, $_val);

// Initialize server-controlled request correlation before error handling so API/Admin responses share one request id.
require_once dirname(__DIR__) . '/includes/request-context.php';

// Load global error & exception handler FIRST (catches everything after this point)
require_once __DIR__ . '/error-handler.php';

// ==========================================
// 1. PRODUCTION ENVIRONMENT & ERROR HANDLING
// ==========================================
// Set to FALSE for live site to hide sensitive errors from users
// Supports APP_ENV environment variable: 'development' enables debug, everything else = production
$appEnv = getenv('APP_ENV') ?: 'production';
define('APP_ENV', $appEnv);
define('DEBUG_MODE', $appEnv === 'development');
define('APP_VERSION', trim(@file_get_contents(dirname(__DIR__) . '/VERSION') ?: '1.0.0'));
define('APP_START_TIME', microtime(true)); // Request timing — used for performance monitoring

if (DEBUG_MODE) {
    error_reporting(E_ALL);
    ini_set('display_errors', 1);
    ini_set('display_startup_errors', 1);
} else {
    // Log real errors, warnings and notices, but NOT E_DEPRECATED/E_STRICT.
    // PHP 8.5 emits deprecation notices for legacy no-op calls (curl_close,
    // imagedestroy, finfo_close, legacy PDO constants, ...) that were flooding
    // error_log by the tens of thousands and hiding actionable errors. Runtime
    // deprecations are non-actionable in production and are still caught in CI
    // via PHPStan, so they are excluded from the production log here.
    error_reporting(E_ALL & ~E_DEPRECATED & ~E_STRICT);
    ini_set('display_errors', 0);
    ini_set('display_startup_errors', 0);
    ini_set('log_errors', 1);
    ini_set('error_log', dirname(__DIR__, 2) . '/error_log.txt'); // Outside web root — not accessible via HTTP
}

// ==========================================
// 2. SITE IDENTITY & URLS
// ==========================================
// IMPORTANT: No trailing slash.
// Production must never derive canonical URLs from an arbitrary Host header.
if (!function_exists('detectRuntimeSiteUrl')) {
    function normalizeRuntimeHost(string $host): string
    {
        $host = trim($host);
        if ($host === '') {
            return '';
        }
        if (strpos($host, ',') !== false) {
            $host = trim(explode(',', $host, 2)[0]);
        }
        $parts = parse_url('//' . $host);
        $host = strtolower((string) ($parts['host'] ?? ''));
        return preg_match('/^[a-z0-9.-]+$/', $host) ? $host : '';
    }

    function allowedRuntimeHosts(): array
    {
        $hosts = ['blockblast-unblocked.io', 'www.blockblast-unblocked.io'];
        $envHosts = getenv('ALLOWED_HOSTS');
        if ($envHosts !== false && trim($envHosts) !== '') {
            $hosts = array_merge($hosts, preg_split('/\s*,\s*/', trim($envHosts)) ?: []);
        }

        $siteUrl = getenv('SITE_URL');
        if ($siteUrl !== false && $siteUrl !== '') {
            $siteHost = (string) (parse_url($siteUrl, PHP_URL_HOST) ?: '');
            if ($siteHost !== '') {
                $hosts[] = $siteHost;
            }
        }

        return array_values(array_unique(array_filter(array_map(
            static fn($host) => normalizeRuntimeHost((string) $host),
            $hosts
        ))));
    }

    function detectRuntimeSiteUrl(): string
    {
        $defaultUrl = 'https://blockblast-unblocked.io';
        $env = getenv('SITE_URL');
        if ($env !== false && $env !== '') {
            $parts = parse_url($env);
            if (!empty($parts['scheme']) && !empty($parts['host']) && in_array(strtolower($parts['scheme']), ['http', 'https'], true)) {
                $scheme = strtolower($parts['scheme']);
                $url = $scheme . '://' . strtolower($parts['host']);
                if (!empty($parts['port'])) {
                    $port = (int) $parts['port'];
                    $isDefaultPort = ($scheme === 'https' && $port === 443)
                        || ($scheme === 'http' && $port === 80);
                    if (!$isDefaultPort) {
                        $url .= ':' . $port;
                    }
                }
                return rtrim($url, '/');
            }
            error_log('[CONFIG] Ignoring invalid SITE_URL value');
        }

        $host = normalizeRuntimeHost((string) ($_SERVER['HTTP_HOST'] ?? ''));
        if ($host !== '' && in_array($host, allowedRuntimeHosts(), true)) {
            $isLocal = in_array($host, ['localhost', '127.0.0.1'], true);
            $scheme = (!DEBUG_MODE && !$isLocal) ? 'https' : ((!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http');
            return $scheme . '://' . $host;
        }

        return $defaultUrl;
    }
}
define('SITE_URL', detectRuntimeSiteUrl());
define('ALLOWED_HOSTS', allowedRuntimeHosts());
define('BASE_URL', SITE_URL); // Alias for compatibility

// Helper URLs
define('ASSETS_URL', SITE_URL . '/assets');
define('UPLOADS_URL', SITE_URL . '/uploads');
define('ADMIN_URL', SITE_URL . '/antihacker');

// Versioning for Cache Busting (CSS/JS)
// Change this number when you update styles/scripts to force users to download new versions
define('CACHE_VERSION', '5.0.22'); // Keep page asset URLs and service-worker cache generation synchronized

// Crawl architecture governance. Defaults preserve current production output.
$tagGovernanceMode = strtolower(trim((string) (getenv('TAG_GOVERNANCE_MODE') ?: 'preserve')));
define('TAG_GOVERNANCE_MODE', in_array($tagGovernanceMode, ['preserve', 'evidence'], true) ? $tagGovernanceMode : 'preserve');
define('INDEXABLE_TAG_ALLOWLIST', (string) (getenv('INDEXABLE_TAG_ALLOWLIST') ?: ''));
define('NOINDEX_TAG_DENYLIST', (string) (getenv('NOINDEX_TAG_DENYLIST') ?: ''));
$topicalLinkFlag = strtolower(trim((string) (getenv('ENABLE_TOPICAL_RELATED_LINKING') ?: '')));
$topicalLinkApprovalFlag = strtolower(trim((string) (getenv('ENABLE_TOPICAL_RELATED_LINKING_PRODUCTION_APPROVED') ?: '')));
$homepageGameSchemaFlag = strtolower(trim((string) (getenv('ENABLE_HOMEPAGE_FEATURED_GAME_SCHEMA') ?: '')));
$staticIframeFlag = strtolower(trim((string) (getenv('ENABLE_STATIC_IFRAME_DISCOVERY_EXPERIMENT') ?: '')));
define('ENABLE_TOPICAL_RELATED_LINKING', in_array($topicalLinkFlag, ['1', 'true', 'yes', 'on'], true));
define('ENABLE_TOPICAL_RELATED_LINKING_PRODUCTION_APPROVED', in_array($topicalLinkApprovalFlag, ['1', 'true', 'yes', 'on'], true));
define('ENABLE_HOMEPAGE_FEATURED_GAME_SCHEMA', in_array($homepageGameSchemaFlag, ['1', 'true', 'yes', 'on'], true));
define('ENABLE_STATIC_IFRAME_DISCOVERY_EXPERIMENT', in_array($staticIframeFlag, ['1', 'true', 'yes', 'on'], true));
unset($tagGovernanceMode, $topicalLinkFlag, $topicalLinkApprovalFlag, $homepageGameSchemaFlag, $staticIframeFlag);

// ==========================================
// 3. SERVER PATHS (Dynamic)
// ==========================================
define('ROOT_PATH', dirname(__DIR__));
define('INCLUDES_PATH', ROOT_PATH . '/includes');
define('CONFIG_PATH', ROOT_PATH . '/config');
define('UPLOAD_PATH', ROOT_PATH . '/uploads');

// Sub-directories for uploads
define('GAME_THUMBS_PATH', UPLOAD_PATH . '/games/thumbs');
define('GAME_THUMBS_URL', UPLOADS_URL . '/games/thumbs');

// ==========================================
// 4. SECURITY & SESSIONS
// ==========================================
// Prevent Session Hijacking & Fix HTTPS Issues
ini_set('session.cookie_httponly', 1); // JS cannot access session cookie
ini_set('session.use_only_cookies', 1); // No session IDs in URL
$sessionHost = (string) (parse_url(SITE_URL, PHP_URL_HOST) ?: '');
$sessionScheme = (string) (parse_url(SITE_URL, PHP_URL_SCHEME) ?: 'https');
$sessionCookieSecure = !in_array($sessionHost, ['localhost', '127.0.0.1'], true) || $sessionScheme === 'https';
ini_set('session.cookie_secure', $sessionCookieSecure ? 1 : 0);    // Only send cookies over HTTPS except explicit localhost QA
$cspUpgradeDirective = $sessionScheme === 'https' ? 'upgrade-insecure-requests' : '';
ini_set('session.cookie_samesite', 'Lax'); // CSRF protection (prevents cross-site cookie theft)
ini_set('session.use_strict_mode', 1);  // Reject uninitialized session IDs (prevents session fixation)
ini_set('session.gc_maxlifetime', 86400 * 7); // Keep login for 7 days
ini_set('session.cookie_lifetime', 86400 * 7);

// Start Session safely — ONLY for admin and mutating API requests.
// Safe GET APIs stay sessionless/cacheable and do not emit PHPSESSID.
$isAdmin = strpos($_SERVER['REQUEST_URI'] ?? '', '/antihacker') !== false || strpos($_SERVER['REQUEST_URI'] ?? '', '/admin') !== false;
$isApi = strpos($_SERVER['REQUEST_URI'] ?? '', '/api/') !== false;
$isApiMutation = $isApi && strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET')) !== 'GET';
$isEmbed = preg_match('#(?:^|/)(?:embed\.php|[a-z0-9\-]+\.embed)(?:\?.*)?$#i', $_SERVER['REQUEST_URI'] ?? '') === 1;
if (!function_exists('rbc_permissions_policy_request_path')) {
    function rbc_permissions_policy_request_path(): string
    {
        $path = parse_url((string) ($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH);
        $path = is_string($path) && $path !== '' ? $path : '/';
        $path = '/' . ltrim(rawurldecode($path), '/');
        return $path !== '/' ? rtrim($path, '/') : '/';
    }

    function rbc_permissions_policy_script_names(): array
    {
        $names = [];
        foreach (['SCRIPT_NAME', 'SCRIPT_FILENAME', 'PHP_SELF'] as $key) {
            $name = strtolower(basename((string) ($_SERVER[$key] ?? '')));
            if ($name !== '') {
                $names[] = $name;
            }
        }

        return array_values(array_unique($names));
    }

    function rbc_is_reserved_public_slug_for_permissions_policy(string $slug): bool
    {
        static $reserved = [
            'about-us',
            'best-games',
            'contact',
            'contact-us',
            'disclaimer',
            'dmca',
            'faq',
            'favorites',
            'guides',
            'history',
            'hot-games',
            'manifest',
            'new-games',
            'offline',
            'privacy-policy',
            'robots',
            'search',
            'sitemap',
            'sw',
            'terms',
            'terms-of-service',
        ];

        return in_array(strtolower($slug), $reserved, true);
    }

    function rbc_is_clean_game_slug_candidate_for_permissions_policy(string $path): bool
    {
        $slug = trim($path, '/');
        if ($slug === '' || strpos($slug, '/') !== false) {
            return false;
        }
        if (!preg_match('/^[a-z0-9-]+$/i', $slug)) {
            return false;
        }

        return !rbc_is_reserved_public_slug_for_permissions_policy($slug);
    }

    function rbc_is_embed_route_for_permissions_policy(): bool
    {
        $path = rbc_permissions_policy_request_path();
        if (str_ends_with(strtolower($path), '.embed')) {
            return true;
        }

        return in_array('embed.php', rbc_permissions_policy_script_names(), true);
    }

    function rbc_is_game_or_embed_route_for_permissions_policy(): bool
    {
        if (rbc_is_embed_route_for_permissions_policy()) {
            return true;
        }

        $path = rbc_permissions_policy_request_path();
        $firstSegment = strtolower(strtok(trim($path, '/'), '/') ?: '');
        if (in_array($firstSegment, [
            'admin',
            'antihacker',
            'api',
            'assets',
            'cache',
            'category',
            'config',
            'database',
            'games',
            'includes',
            'page',
            'tag',
            'uploads',
        ], true)) {
            return false;
        }

        if (in_array('play.php', rbc_permissions_policy_script_names(), true)) {
            $slug = (string) ($_GET['slug'] ?? '');
            return $slug !== ''
                ? rbc_is_clean_game_slug_candidate_for_permissions_policy('/' . $slug)
                : rbc_is_clean_game_slug_candidate_for_permissions_policy($path);
        }

        return rbc_is_clean_game_slug_candidate_for_permissions_policy($path);
    }

    function rbc_permissions_policy_header_value(bool $allowCrossOriginFullscreen): string
    {
        $fullscreenPolicy = $allowCrossOriginFullscreen ? '(*)' : '(self)';
        return "camera=(), microphone=(), geolocation=(), payment=(), usb=(), bluetooth=(), magnetometer=(), gyroscope=(), accelerometer=(), interest-cohort=(), browsing-topics=(), autoplay=(self), fullscreen={$fullscreenPolicy}, picture-in-picture=(self)";
    }

    function rbc_send_permissions_policy_header(bool $allowCrossOriginFullscreen): void
    {
        header('Permissions-Policy: ' . rbc_permissions_policy_header_value($allowCrossOriginFullscreen));
    }
}
if ($isAdmin && !headers_sent()) {
    header('X-Robots-Tag: noindex, nofollow');
    header('Cache-Control: no-store, no-cache, must-revalidate');
    header('Pragma: no-cache');
}
if ($isAdmin || $isApiMutation) {
    if (session_status() === PHP_SESSION_NONE) {
        // Admin sessions: tighter lifetime aligned with auth.php 30-min inactivity timeout
        if ($isAdmin) {
            ini_set('session.cookie_samesite', 'Strict');
            ini_set('session.gc_maxlifetime', 1800);
            ini_set('session.cookie_lifetime', 0); // Session cookie — expires when browser closes
        }
        session_start();
    }
    // Generate CSRF vote token if not exists (Geometry-Games.io trick)
    if (empty($_SESSION['vote_token'])) {
        $_SESSION['vote_token'] = bin2hex(random_bytes(16));
    }
}

// ==========================================
// CSP NONCE — per-request, used to allow specific inline scripts
// without unsafe-inline (OWASP A05:2021 defence-in-depth)
// Admin/frontend pages enforce only the low-risk subset; strict script/frame rules
// stay in Report-Only until compatibility data is clean.
// ==========================================
$_cspNonce = base64_encode(random_bytes(16));
define('CSP_NONCE', $_cspNonce);
unset($_cspNonce);

// Public pages enforce the nonce-based script policy below. The Admin surface
// retains its legacy inline-handler compatibility exception until those
// handlers are migrated without changing the Admin UI or CRUD behaviour.
// Remove any server-injected CSP headers before setting the intended policy.
header_remove('Content-Security-Policy');
header_remove('Content-Security-Policy-Report-Only');

// CSP canary: keep enforcement compatible, emit the richer policy as Report-Only.
// This lets production collect breakage data before strict CSP enforcement.
$cspReportOnlyCanarySent = false;
$safeEnforcedCspDirectives = [
    "base-uri 'self'",
    "object-src 'none'",
    "frame-ancestors 'self'",
    "form-action 'self'",
];
if ($cspUpgradeDirective !== '') {
    $safeEnforcedCspDirectives[] = $cspUpgradeDirective;
}
$safeEnforcedCsp = implode('; ', $safeEnforcedCspDirectives);

$publicEnforcedCspDirectives = [
    "default-src 'self'",
    "script-src 'self' 'nonce-" . CSP_NONCE . "' "
        . "https://www.googletagmanager.com https://www.google-analytics.com "
        . "https://www.google.com https://www.gstatic.com "
        . "https://cdnjs.cloudflare.com https://cdn.jsdelivr.net "
        . "https://code.jquery.com https://pagead2.googlesyndication.com "
        . "https://googleads.g.doubleclick.net https://securepubads.g.doubleclick.net "
        . "https://tpc.googlesyndication.com https://js.sentry-cdn.com "
        . "https://static.cloudflareinsights.com",
    "style-src 'self' 'unsafe-inline' https://fonts.googleapis.com "
        . "https://cdnjs.cloudflare.com https://cdn.jsdelivr.net",
    "font-src 'self' https://fonts.gstatic.com https://cdnjs.cloudflare.com "
        . "https://cdn.jsdelivr.net",
    "img-src 'self' data: blob: https:",
    "frame-src 'self' https:",
    "connect-src 'self' https:",
    "frame-ancestors 'self'",
    "object-src 'none'",
    "base-uri 'self'",
    "form-action 'self' https://www.google.com https://www.paypal.com",
];
if ($cspUpgradeDirective !== '') {
    $publicEnforcedCspDirectives[] = $cspUpgradeDirective;
}
$publicEnforcedCsp = implode('; ', $publicEnforcedCspDirectives);
// Shared with the public head template as a defense against hosting layers
// that replace the response CSP header after PHP has emitted it. The meta CSP
// is intentionally limited to public pages; Admin keeps its compatibility
// policy until legacy inline handlers are migrated.
if (!defined('CSP_PUBLIC_POLICY')) {
    define('CSP_PUBLIC_POLICY', $publicEnforcedCsp);
}

if (!$isAdmin && !$isEmbed) {
    header("Content-Security-Policy: " . $publicEnforcedCsp);
    header(
        "Content-Security-Policy-Report-Only: "
        . "default-src 'self'; "
        . "script-src 'self' 'nonce-" . CSP_NONCE . "' "
        . "https://www.googletagmanager.com https://www.google-analytics.com "
        . "https://www.google.com https://www.gstatic.com "
        . "https://cdnjs.cloudflare.com https://cdn.jsdelivr.net "
        . "https://code.jquery.com https://pagead2.googlesyndication.com "
        . "https://googleads.g.doubleclick.net https://securepubads.g.doubleclick.net "
        . "https://tpc.googlesyndication.com https://js.sentry-cdn.com "
        . "https://static.cloudflareinsights.com; "
        . "style-src 'self' 'unsafe-inline' https://fonts.googleapis.com "
        . "https://cdnjs.cloudflare.com https://cdn.jsdelivr.net; "
        . "font-src 'self' https://fonts.gstatic.com https://cdnjs.cloudflare.com "
        . "https://cdn.jsdelivr.net; "
        . "img-src 'self' data: blob: https:; "
        . "frame-src 'self' https://www.google.com https://www.recaptcha.net https:; "
        . "connect-src 'self' https://www.google-analytics.com "
        . "https://www.googletagmanager.com https://stats.g.doubleclick.net https:; "
        . "frame-ancestors 'self'; "
        . "object-src 'none'; "
        . "base-uri 'self'; "
        . "form-action 'self' https://www.google.com https://www.paypal.com; "
        . "report-uri /api/csp-report.php"
    );
    $cspReportOnlyCanarySent = true;
} elseif ($isAdmin) {
    // Admin CSP: 'unsafe-inline' required because Hostinger injects its own
    // 'default-src self' CSP header. With dual CSP, browser enforces BOTH —
    // nonce alone won't work if the other header lacks nonce support.
    // 'unsafe-inline' is safe here: admin is behind auth + CSRF.
    $safeEnforcedCspDirectives[] = "script-src 'self' 'unsafe-inline' 'unsafe-eval' "
        . "https://cdn.jsdelivr.net https://code.jquery.com https://cdnjs.cloudflare.com "
        . "https://static.cloudflareinsights.com";
    header("Content-Security-Policy: " . implode('; ', $safeEnforcedCspDirectives));
    header(
        "Content-Security-Policy-Report-Only: "
        . "default-src 'self'; "
        . "script-src 'self' 'unsafe-inline' 'unsafe-eval' "
        . "https://cdn.jsdelivr.net https://code.jquery.com "
        . "https://cdnjs.cloudflare.com https://static.cloudflareinsights.com; "
        . "style-src 'self' 'unsafe-inline' https://fonts.googleapis.com "
        . "https://cdn.jsdelivr.net https://cdnjs.cloudflare.com; "
        . "font-src 'self' https://fonts.gstatic.com https://cdn.jsdelivr.net "
        . "https://cdnjs.cloudflare.com; "
        . "img-src 'self' data: blob: https:; "
        . "frame-src 'self'; "
        . "connect-src 'self' https:; "
        . "frame-ancestors 'self'; "
        . "report-uri /api/csp-report.php"
    );
    $cspReportOnlyCanarySent = true;
}
unset($safeEnforcedCspDirectives, $safeEnforcedCsp, $publicEnforcedCspDirectives, $publicEnforcedCsp);

// Security Headers (Defense-in-depth: PHP fallback for .htaccess)
// NOTE: .htaccess sets these at Apache level. PHP re-sets them here as fallback
// for environments where mod_headers is disabled (some shared hosting).
// PHP header() REPLACES Apache's — no actual duplication in HTTP response.
header("X-XSS-Protection: 0"); // Modern: CSP handles XSS (CrazyGames pattern)
header("X-Content-Type-Options: nosniff");
if (!$isEmbed) {
    header("X-Frame-Options: SAMEORIGIN"); // Clickjacking protection (defense-in-depth)
}
header("Referrer-Policy: strict-origin-when-cross-origin"); // Matches .htaccess — preserves path for same-origin GA4 tracking
header("X-Download-Options: noopen"); // CrazyGames + Poki
header("X-Permitted-Cross-Domain-Policies: none"); // Flash/PDF embedding protection
rbc_send_permissions_policy_header(rbc_is_game_or_embed_route_for_permissions_policy());
header("Cross-Origin-Opener-Policy: same-origin-allow-popups");
header("Cross-Origin-Resource-Policy: cross-origin");
$securityReportingEnabled = in_array(strtolower((string) (getenv('SECURITY_REPORTING_ENABLED') ?: '')), ['1', 'true', 'yes', 'on'], true);
if ($securityReportingEnabled) {
    header('NEL: {"report_to":"default","max_age":2592000,"include_subdomains":true}');
    header('Report-To: {"group":"default","max_age":2592000,"endpoints":[{"url":"/api/csp-report.php"}]}');
}
header_remove("X-Powered-By"); // Hide PHP version (GeometryLitePC leaks theirs)
ini_set('expose_php', 0); // Extra: hide PHP from response headers

// ==========================================
// 5. REGIONAL SETTINGS
// ==========================================
date_default_timezone_set('Asia/Ho_Chi_Minh');
// ==========================================
// 6. SYSTEM CONSTANTS
// ==========================================
define('GAMES_PER_PAGE', 24);
define('ADMIN_ITEMS_PER_PAGE', 25);
define('RELATED_GAMES_LIMIT', 8);

// ==========================================
// 7. HTML OUTPUT MINIFICATION
// ==========================================
// MOVED TO: includes/header.php (single ob_start with SKIP_MINIFY support)
// Having minification in both config.php AND header.php caused double-processing.
// header.php version is kept because it has SKIP_MINIFY check for admin/API pages.

// ==========================================
// 8. SETTINGS CACHE (Poki INITIAL_STATE pattern)
// ==========================================
// In-memory cache placeholder — must be null (not []) so getSetting() knows to load from DB
// getSetting() checks !isset() to trigger first load; [] would make isset() return true
$_SETTINGS_CACHE = null;

// ==========================================
// 9. CSP VIOLATION REPORTING (#16 Content Security Policy)
// ==========================================
// Disabled by default because report-only noise can flood browsers and surface
// 429s in production consoles. Enable with SECURITY_REPORTING_ENABLED=1 during
// a controlled CSP canary window.
if (!headers_sent() && $securityReportingEnabled && empty($cspReportOnlyCanarySent)) {
    header("Content-Security-Policy-Report-Only: default-src 'self'; report-uri /api/csp-report.php");
}

// ==========================================
// 10. SLOW REQUEST MONITORING
// ==========================================
register_shutdown_function(function () {
    $elapsed = microtime(true) - (defined('APP_START_TIME') ? APP_START_TIME : microtime(true));
    if ($elapsed > 2.0) {
        $clientIp = $_SERVER['REMOTE_ADDR'] ?? '-';
        if (function_exists('getClientIp')) {
            try {
                $clientIp = getClientIp();
            } catch (Throwable $e) {
                error_log('[SLOW] Client IP resolution failed during shutdown: ' . $e->getMessage());
            }
        }
        error_log(sprintf(
            "[SLOW] %.2fs | %s %s | IP: %s",
            $elapsed,
            $_SERVER['REQUEST_METHOD'] ?? 'CLI',
            $_SERVER['REQUEST_URI'] ?? '-',
            $clientIp
        ));
    }
});
