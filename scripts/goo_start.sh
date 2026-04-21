#!/usr/bin/env bash
set -euo pipefail

PROJECT_ROOT="${PROJECT_ROOT:-/var/www/gosistema}"
BACKEND_DIR="$PROJECT_ROOT/backend"
FRONTEND_DIR="$PROJECT_ROOT/frontend"
LOG_DIR="$PROJECT_ROOT/runtime/logs"
PID_DIR="$PROJECT_ROOT/runtime/pids"

mkdir -p "$LOG_DIR" "$PID_DIR"

start_backend() {
  nohup bash -lc "cd '$BACKEND_DIR' && php artisan serve --host=0.0.0.0 --port=8000" \
    >"$LOG_DIR/backend.log" 2>&1 &
  echo $! > "$PID_DIR/backend.pid"
}
start_queue() {
  nohup bash -lc "cd '$BACKEND_DIR' && php artisan queue:work --tries=3 --timeout=120" \
    >"$LOG_DIR/queue.log" 2>&1 &
  echo $! > "$PID_DIR/queue.pid"
}
start_frontend() {
  nohup bash -lc "cd '$FRONTEND_DIR' && npm run dev -- --host 0.0.0.0 --port 3000" \
    >"$LOG_DIR/frontend.log" 2>&1 &
  echo $! > "$PID_DIR/frontend.pid"
}

start_backend
start_queue
start_frontend
