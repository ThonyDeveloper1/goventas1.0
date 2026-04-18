#!/usr/bin/env bash
set -euo pipefail

PROJECT_ROOT="${PROJECT_ROOT:-/var/www/gosistema}"
PID_DIR="$PROJECT_ROOT/runtime/pids"

check_pid() {
  local name="$1"
  local file="$PID_DIR/${name}.pid"

  if [[ ! -f "$file" ]]; then
    echo "[FAIL] Missing PID file for $name"
    return 1
  fi

  local pid
  pid=$(cat "$file")
  if ! kill -0 "$pid" 2>/dev/null; then
    echo "[FAIL] Process not running for $name (pid=$pid)"
    return 1
  fi

  echo "[OK] $name running (pid=$pid)"
}

check_http() {
  local url="$1"
  local name="$2"

  local code
  code=$(curl -s -o /dev/null -w "%{http_code}" "$url" || true)
  if [[ "$code" != "200" && "$code" != "302" ]]; then
    echo "[FAIL] $name HTTP check failed ($code)"
    return 1
  fi

  echo "[OK] $name HTTP $code"
}

check_port() {
  local port="$1"
  if ! ss -ltn | awk '{print $4}' | grep -q ":${port}$"; then
    echo "[FAIL] Port $port is not listening"
    return 1
  fi

  echo "[OK] Port $port listening"
}

check_pid backend
check_pid queue
check_pid frontend

check_port 8000
check_port 3000

check_http "http://127.0.0.1:8000" "backend"
check_http "http://127.0.0.1:3000" "frontend"

echo "All GO Sistema health checks passed."
