#!/usr/bin/env bash
set -euo pipefail

ROOT="$(cd "$(dirname "$0")/.." && pwd)"
cd "$ROOT"

PORT=18080
BASE="http://127.0.0.1:${PORT}"
TMP="$(mktemp -d)"
SERVER_PID=""

cleanup() {
  if [[ -n "${SERVER_PID}" ]]; then
    kill "${SERVER_PID}" >/dev/null 2>&1 || true
    wait "${SERVER_PID}" 2>/dev/null || true
  fi
  rm -rf "${TMP}"
}
trap cleanup EXIT

php -S "127.0.0.1:${PORT}" tests/request-id-router.php >"${TMP}/server.log" 2>&1 &
SERVER_PID=$!

for _ in $(seq 1 50); do
  if curl -fsS "${BASE}/public" >/dev/null 2>&1; then
    break
  fi
  sleep 0.1
done

extract_id() {
  awk 'BEGIN{IGNORECASE=1} /^X-Request-ID:/ {gsub("\r","",$2); id=$2} END{print id}' "$1"
}

probe() {
  local name="$1"
  local path="$2"
  shift 2
  curl -sS -D "${TMP}/${name}.headers" -o "${TMP}/${name}.body" "$@" "${BASE}${path}"
}

assert_hex32() {
  local id="$1"
  local label="$2"
  if [[ ! "$id" =~ ^[a-f0-9]{32}$ ]]; then
    echo "FAIL ${label}: expected 32 lowercase hex chars, got '${id}'" >&2
    exit 1
  fi
}

assert_body_same() {
  local body="$1"
  php -r '
    $j=json_decode(file_get_contents($argv[1]), true);
    if (!is_array($j) || empty($j["same"]) || !isset($j["id1"],$j["id2"]) || $j["id1"] !== $j["id2"]) {
      fwrite(STDERR, "FAIL same-request canonical ID invariant\n");
      exit(1);
    }
  ' "$body"
}

assert_header_matches_body() {
  local header_id="$1"
  local body="$2"
  php -r '
    $j=json_decode(file_get_contents($argv[1]), true);
    $h=$argv[2];
    if (!is_array($j) || !isset($j["id1"]) || !hash_equals($j["id1"], $h)) {
      fwrite(STDERR, "FAIL response header/body canonical ID mismatch\n");
      exit(1);
    }
  ' "$body" "$header_id"
}

probe api /api/probe
API_ID="$(extract_id "${TMP}/api.headers")"
assert_hex32 "$API_ID" "api"
assert_body_same "${TMP}/api.body"
assert_header_matches_body "$API_ID" "${TMP}/api.body"

probe admin /admin/probe
ADMIN_ID="$(extract_id "${TMP}/admin.headers")"
assert_hex32 "$ADMIN_ID" "admin"
assert_body_same "${TMP}/admin.body"
assert_header_matches_body "$ADMIN_ID" "${TMP}/admin.body"

probe antihacker /antihacker/probe
ANTIHACKER_ID="$(extract_id "${TMP}/antihacker.headers")"
assert_hex32 "$ANTIHACKER_ID" "antihacker"
assert_body_same "${TMP}/antihacker.body"
assert_header_matches_body "$ANTIHACKER_ID" "${TMP}/antihacker.body"

probe public /public
PUBLIC_ID="$(extract_id "${TMP}/public.headers")"
if [[ -n "$PUBLIC_ID" ]]; then
  echo "FAIL public: normal public page emitted X-Request-ID '${PUBLIC_ID}'" >&2
  exit 1
fi
assert_body_same "${TMP}/public.body"

probe health /health
HEALTH_ID="$(extract_id "${TMP}/health.headers")"
assert_hex32 "$HEALTH_ID" "health-force"
assert_body_same "${TMP}/health.body"
assert_header_matches_body "$HEALTH_ID" "${TMP}/health.body"

INBOUND="aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa"
probe inbound /api/inbound -H "X-Request-ID: ${INBOUND}"
INBOUND_RESULT="$(extract_id "${TMP}/inbound.headers")"
assert_hex32 "$INBOUND_RESULT" "inbound-ignore"
if [[ "$INBOUND_RESULT" == "$INBOUND" ]]; then
  echo "FAIL inbound: client X-Request-ID became canonical ID" >&2
  exit 1
fi
assert_header_matches_body "$INBOUND_RESULT" "${TMP}/inbound.body"

probe api2 /api/probe
API_ID_2="$(extract_id "${TMP}/api2.headers")"
assert_hex32 "$API_ID_2" "api-second-request"
if [[ "$API_ID_2" == "$API_ID" ]]; then
  echo "FAIL uniqueness: two requests reused the same ID" >&2
  exit 1
fi

echo "PASS request-context HTTP behavior"
echo "  api=${API_ID}"
echo "  admin=${ADMIN_ID}"
echo "  antihacker=${ANTIHACKER_ID}"
echo "  health=${HEALTH_ID}"
echo "  inbound_client=${INBOUND}"
echo "  inbound_server=${INBOUND_RESULT}"
echo "  public_header=absent"
echo "  same_request=stable"
echo "  cross_request=unique"
