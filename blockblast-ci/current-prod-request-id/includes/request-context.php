<?php
/**
 * Per-request correlation context.
 *
 * Goals:
 * - generate one server-controlled opaque request id for each PHP request;
 * - expose it only on sensitive/non-cacheable surfaces (API/Admin) by default;
 * - allow error handlers to force the header on failures;
 * - never trust an inbound request-id header as the canonical id.
 *
 * This file intentionally has no dependency on sessions, database, Logger, or
 * any frontend rendering code.
 */

if (PHP_SAPI !== 'cli' && realpath((string) ($_SERVER['SCRIPT_FILENAME'] ?? '')) === __FILE__) {
    http_response_code(404);
    header('Content-Type: text/plain; charset=UTF-8');
    exit;
}

if (!function_exists('rbc_request_id')) {
    function rbc_request_id(): string
    {
        if (!empty($GLOBALS['_rbc_request_id']) && is_string($GLOBALS['_rbc_request_id'])) {
            return $GLOBALS['_rbc_request_id'];
        }

        try {
            $id = bin2hex(random_bytes(16));
        } catch (Throwable $e) {
            $id = substr(hash('sha256', uniqid('', true) . '|' . microtime(true) . '|' . getmypid()), 0, 32);
        }

        $GLOBALS['_rbc_request_id'] = $id;
        $_SERVER['RBC_REQUEST_ID'] = $id;
        return $id;
    }
}

if (!function_exists('rbc_request_path')) {
    function rbc_request_path(): string
    {
        $path = parse_url((string) ($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH);
        $path = is_string($path) && $path !== '' ? rawurldecode($path) : '/';
        return '/' . ltrim($path, '/');
    }
}

if (!function_exists('rbc_is_request_id_response_surface')) {
    function rbc_is_request_id_response_surface(): bool
    {
        return preg_match('#^/(?:api|admin|antihacker)(?:/|$)#i', rbc_request_path()) === 1;
    }
}

if (!function_exists('rbc_emit_request_id_header')) {
    function rbc_emit_request_id_header(bool $force = false): void
    {
        if (PHP_SAPI === 'cli' || headers_sent()) {
            return;
        }
        if (!$force && !rbc_is_request_id_response_surface()) {
            return;
        }
        header('X-Request-ID: ' . rbc_request_id());
    }
}

rbc_emit_request_id_header(false);
