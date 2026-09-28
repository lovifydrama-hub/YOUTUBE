#!/usr/bin/env bash
set -euo pipefail

BASE="https://blockblast-unblocked.io"
TMP="$(mktemp -d)"
trap 'rm -rf "$TMP"' EXIT

probe() {
  local name="$1"
  local path="$2"
  shift 2
  curl -sS --max-time 30 -D "$TMP/$name.headers" -o "$TMP/$name.body" -w "%{http_code}" "$@" "$BASE$path" >"$TMP/$name.status"
}

header_value() {
  local file="$1"
  local name="$2"
  awk -v n="$name" 'BEGIN{IGNORECASE=1} {
    line=$0
    sub("\r$","",line)
    if (index(tolower(line),tolower(n)":")==1) {
      sub(/^[^:]+:[[:space:]]*/,"",line)
      value=line
    }
  } END{print value}' "$file"
}

probe health /api/health.php
probe health_inbound /api/health.php -H "X-Request-ID: aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa"
probe public /
probe admin /antihacker/

HEALTH_STATUS="$(cat "$TMP/health.status")"
HEALTH_ID="$(header_value "$TMP/health.headers" "X-Request-ID")"
HEALTH_INBOUND_ID="$(header_value "$TMP/health_inbound.headers" "X-Request-ID")"
PUBLIC_STATUS="$(cat "$TMP/public.status")"
PUBLIC_ID="$(header_value "$TMP/public.headers" "X-Request-ID")"
ADMIN_STATUS="$(cat "$TMP/admin.status")"
ADMIN_ID="$(header_value "$TMP/admin.headers" "X-Request-ID")"
ADMIN_SERVER="$(header_value "$TMP/admin.headers" "Server")"
ADMIN_CF_RAY="$(header_value "$TMP/admin.headers" "CF-Ray")"

echo "OBS health_status=$HEALTH_STATUS"
echo "OBS health_request_id=${HEALTH_ID:-absent}"
echo "OBS health_inbound_request_id=${HEALTH_INBOUND_ID:-absent}"
echo "OBS public_status=$PUBLIC_STATUS"
echo "OBS public_request_id=${PUBLIC_ID:-absent}"
echo "OBS admin_status=$ADMIN_STATUS"
echo "OBS admin_request_id=${ADMIN_ID:-absent}"
echo "OBS admin_server=${ADMIN_SERVER:-absent}"
echo "OBS admin_cf_ray=${ADMIN_CF_RAY:-absent}"

blocked=0

if [[ -z "$HEALTH_ID" ]]; then
  echo "BLOCKER DEPLOYED_REQUEST_ID_MISSING: /api/health.php does not emit X-Request-ID"
  blocked=1
elif [[ ! "$HEALTH_ID" =~ ^[a-f0-9]{32}$ ]]; then
  echo "BLOCKER DEPLOYED_REQUEST_ID_FORMAT: health request id is not 32 lowercase hex chars"
  blocked=1
fi

if [[ -n "$HEALTH_INBOUND_ID" && "$HEALTH_INBOUND_ID" == "aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa" ]]; then
  echo "BLOCKER DEPLOYED_INBOUND_REQUEST_ID_TRUSTED: inbound id became canonical"
  blocked=1
fi

if [[ -n "$PUBLIC_ID" ]]; then
  echo "BLOCKER PUBLIC_CACHEABLE_REQUEST_ID: public home emitted X-Request-ID"
  blocked=1
fi

if [[ "$ADMIN_STATUS" == "403" && ( -n "$ADMIN_CF_RAY" || "${ADMIN_SERVER,,}" == *cloudflare* ) ]]; then
  echo "BLOCKER EDGE_WAF_ADMIN_OBSERVABILITY: /antihacker/ was denied at edge before application headers could be observed"
  blocked=1
elif [[ -z "$ADMIN_ID" ]]; then
  echo "BLOCKER ADMIN_APPLICATION_REQUEST_ID_MISSING: application surface did not expose X-Request-ID"
  blocked=1
elif [[ ! "$ADMIN_ID" =~ ^[a-f0-9]{32}$ ]]; then
  echo "BLOCKER ADMIN_REQUEST_ID_FORMAT: admin request id is not 32 lowercase hex chars"
  blocked=1
fi

if [[ "$blocked" -ne 0 ]]; then
  echo "VERDICT BLOCKED_NOT_CERTIFIED"
  exit 20
fi

echo "VERDICT PASS_DEPLOYED_SECURITY_REQUEST_ID"
