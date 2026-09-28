#!/usr/bin/env bash
set -euo pipefail

root="blockblast-ci/current-prod-request-id"
php -l "$root/includes/request-context.php"
php -l "$root/config/config.php"
php -l "$root/api/health.php"

php -S 127.0.0.1:8099 "$root/router.php" >/tmp/blockblast-request-id-server.log 2>&1 &
server_pid=$!
trap 'kill "$server_pid" 2>/dev/null || true' EXIT

for _ in $(seq 1 20); do
  if curl -fsS http://127.0.0.1:8099/ >/dev/null; then break; fi
  sleep 0.2
done

extract_id() {
  tr -d '\r' | awk -F': ' 'tolower($1)=="x-request-id"{print $2}'
}

for path in /api/test /admin/test /antihacker/test /health-force; do
  headers=$(curl -sS -D - -o /tmp/body "http://127.0.0.1:8099$path")
  rid=$(printf '%s' "$headers" | extract_id)
  if [[ ! "$rid" =~ ^[a-f0-9]{32}$ ]]; then
    echo "FAIL $path request id: $rid" >&2
    exit 1
  fi
done

headers=$(curl -sS -D - -o /tmp/body http://127.0.0.1:8099/)
if printf '%s' "$headers" | tr -d '\r' | grep -qi '^x-request-id:'; then
  echo "FAIL public route unexpectedly emitted X-Request-ID" >&2
  exit 1
fi

headers=$(curl -sS -H 'X-Request-ID: aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa' -D - -o /tmp/body http://127.0.0.1:8099/api/test)
rid=$(printf '%s' "$headers" | extract_id)
if [[ "$rid" == "aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa" ]]; then
  echo "FAIL inbound request id was trusted" >&2
  exit 1
fi
if [[ ! "$rid" =~ ^[a-f0-9]{32}$ ]]; then
  echo "FAIL generated request id invalid: $rid" >&2
  exit 1
fi

echo "request-id-recert: PASS"
