#!/usr/bin/env bash
set -euo pipefail

PROJECT_ROOT="${PROJECT_ROOT:-/var/www/gosistema}"
PID_DIR="$PROJECT_ROOT/runtime/pids"

mkdir -p "$PID_DIR"

kill_pid_file() {
  local name="$1"
  local pid_file="$PID_DIR/${name}.pid"

  if [[ -f "$pid_file" ]]; then
    local pid
    pid=$(cat "$pid_file" 2>/dev/null || true)
    if [[ -n "${pid:-}" ]] && kill -0 "$pid" 2>/dev/null; then
      kill "$pid" 2>/dev/null || true
      sleep 1
      kill -9 "$pid" 2>/dev/null || true
    fi
    rm -f "$pid_file"
  fi
}

# Stop known managed processes first
kill_pid_file backend
kill_pid_file frontend
kill_pid_file queue

# Fallback: free ports and process patterns
fuser -k 8000/tcp 2>/dev/null || true
fuser -k 3000/tcp 2>/dev/null || true

pkill -f "php artisan serve --host=0.0.0.0 --port=8000" 2>/dev/null || true
pkill -f "php artisan queue:work" 2>/dev/null || true
pkill -f "vite --host 0.0.0.0 --port 3000" 2>/dev/null || true
pkill -f "node .*node_modules/.bin/vite --host 0.0.0.0 --port 3000" 2>/dev/null || true
