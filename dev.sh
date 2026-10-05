#!/usr/bin/env bash
#
# 一条命令启动剧场无障碍字幕设备系统（Laravel API + React 前端）
#
#   ./dev.sh            首次自动安装依赖、迁移、播种，然后启动
#   ./dev.sh --reset    重置数据库并重新播种
#
# 后端 http://127.0.0.1:8000   前端 http://127.0.0.1:5173
# Ctrl-C 同时停止前后端。
set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
BACKEND="$ROOT/backend"
FRONTEND="$ROOT/frontend"

# —— 定位 PHP（支持本机无 root 安装在 ~/php 的场景，~/bin/php 是带库路径的包装器）——
if ! command -v php >/dev/null 2>&1 && [[ -x "$HOME/bin/php" ]]; then
  export PATH="$HOME/bin:$PATH"
  # Composer/PHPUnit 等子进程也需要找到 php.ini（扩展）与动态库
  export PHPRC="${PHPRC:-$HOME/php/php.ini}"
  export LD_LIBRARY_PATH="$HOME/php/usr/lib/x86_64-linux-gnu:${LD_LIBRARY_PATH:-}"
fi
command -v php >/dev/null 2>&1 || { echo "错误：找不到 php，请先安装 PHP 8.2+（含 sqlite/mbstring/xml/curl/zip 扩展）"; exit 1; }

if [[ ! -d "$BACKEND/vendor" ]]; then
  echo "==> 安装后端 Composer 依赖"
  (cd "$BACKEND" && composer install --no-interaction)
fi

if [[ ! -f "$BACKEND/.env" ]]; then
  cp "$BACKEND/.env.example" "$BACKEND/.env"
  (cd "$BACKEND" && php artisan key:generate)
fi
# 确保使用 SQLite
if ! grep -q '^DB_CONNECTION=sqlite' "$BACKEND/.env"; then
  sed -i 's/^DB_CONNECTION=.*/DB_CONNECTION=sqlite/' "$BACKEND/.env"
fi
touch "$BACKEND/database/database.sqlite"

if [[ "${1:-}" == "--reset" ]] || [[ ! -f "$BACKEND/database/seeded.flag" ]]; then
  echo "==> 迁移并播种演示数据"
  (cd "$BACKEND" && php artisan migrate:fresh --seed --force)
  touch "$BACKEND/database/seeded.flag"
fi

if [[ ! -d "$FRONTEND/node_modules" ]]; then
  echo "==> 安装前端 npm 依赖"
  (cd "$FRONTEND" && npm install)
fi

cleanup() {
  echo ""
  echo "==> 正在停止服务…"
  [[ -n "${PHP_PID:-}" ]] && kill "$PHP_PID" 2>/dev/null || true
  [[ -n "${VITE_PID:-}" ]] && kill "$VITE_PID" 2>/dev/null || true
  # 兜底：清理可能残留的端口占用进程
  pkill -f "php.*server.php" 2>/dev/null || true
  pkill -f "vite" 2>/dev/null || true
}
trap cleanup EXIT INT TERM

echo "==> 启动后端  http://127.0.0.1:8000"
( cd "$BACKEND" && php -S 127.0.0.1:8000 server.php ) &
PHP_PID=$!

echo "==> 启动前端  http://127.0.0.1:5173"
( cd "$FRONTEND" && npm run dev ) &
VITE_PID=$!

echo ""
echo "============================================================"
echo " 观众预约 : http://127.0.0.1:5173/"
echo " 前台工作 : http://127.0.0.1:5173/frontdesk"
echo " 技术员   : http://127.0.0.1:5173/technician"
echo " 负责人   : http://127.0.0.1:5173/approvals"
echo " 后台总览 : http://127.0.0.1:5173/admin"
echo "============================================================"

wait
