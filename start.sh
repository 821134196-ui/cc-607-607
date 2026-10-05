#!/usr/bin/env bash
#
# 一条命令启动「剧场无障碍字幕设备预约与归还系统」
#   后端 Laravel (PHP 内置服务器) :8000
#   前端 React + Vite             :5173
#
# 用法:
#   ./start.sh            首次运行会自动安装依赖、迁移并填充演示数据
#   ./start.sh --reset    重置数据库并重新填充演示数据
#   ./start.sh --no-seed  不重置数据，直接启动
#
set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
BACKEND="$ROOT/backend"
FRONTEND="$ROOT/frontend"

# ---- 定位 PHP 工具链（优先使用用户目录内置的 PHP 8.2）----
if [ -x "$HOME/.local/php/bin/php" ]; then
  export PATH="$HOME/.local/php/bin:$PATH"
  export PHPRC="$HOME/.local/php/conf"
  export LD_LIBRARY_PATH="$HOME/.local/php/usr/lib/x86_64-linux-gnu:${LD_LIBRARY_PATH:-}"
fi
PHP="$(command -v php)"
COMPOSER="$(command -v composer || true)"

echo "→ PHP: $($PHP -r 'echo PHP_VERSION;')  ($PHP)"

# ---- 后端依赖 ----
if [ ! -d "$BACKEND/vendor" ]; then
  echo "→ 首次运行：安装后端 Composer 依赖…"
  if [ -z "$COMPOSER" ]; then
    echo "  未找到 composer，请先安装：https://getcomposer.org/download/" >&2
    exit 1
  fi
  (cd "$BACKEND" && "$COMPOSER" install --no-interaction)
fi

# ---- 前端依赖 ----
if [ ! -d "$FRONTEND/node_modules" ]; then
  echo "→ 首次运行：安装前端 npm 依赖…"
  (cd "$FRONTEND" && npm install)
fi

# ---- 环境与密钥 ----
if [ ! -f "$BACKEND/.env" ]; then
  cp "$BACKEND/.env.example" "$BACKEND/.env"
  sed -i 's/^DB_CONNECTION=.*/DB_CONNECTION=sqlite/' "$BACKEND/.env"
fi
if ! grep -q '^DB_CONNECTION=sqlite' "$BACKEND/.env"; then
  sed -i 's/^DB_CONNECTION=.*/DB_CONNECTION=sqlite/' "$BACKEND/.env"
fi
touch "$BACKEND/database/database.sqlite"

if ! grep -q '^APP_KEY=base64:' "$BACKEND/.env"; then
  echo "→ 生成 APP_KEY…"
  (cd "$BACKEND" && "$PHP" artisan key:generate --force)
fi

# ---- 数据库迁移与演示数据 ----
RESET=0
for arg in "$@"; do
  case "$arg" in
    --reset) RESET=1 ;;
  esac
done

# 无任何业务表时自动迁移填充；--reset 强制重建（用 PHP 检测，不依赖 sqlite3 CLI）
TABLE_COUNT=$(cd "$BACKEND" && "$PHP" -r '
$f = getenv("DB_DATABASE") ?: (__DIR__."/database/database.sqlite");
try {
  $pdo = new PDO("sqlite:".$f);
  $n = (int) $pdo->query("SELECT COUNT(*) FROM sqlite_master WHERE type=\"table\"")->fetchColumn();
  echo $n;
} catch (Throwable $e) { echo 0; }
' 2>/dev/null)
if [ "$RESET" = "1" ] || [ "${TABLE_COUNT:-0}" -lt 3 ]; then
  echo "→ 迁移并填充演示数据…"
  (cd "$BACKEND" && "$PHP" artisan migrate:fresh --seed --force)
else
  (cd "$BACKEND" && "$PHP" artisan migrate --force)
fi

# ---- 端口占用提示 ----
check_port() {
  if curl -s -o /dev/null "http://127.0.0.1:$1/" 2>/dev/null; then
    echo "  ⚠ 端口 $1 已有服务在响应，请先停止后重试（或更换端口）。" >&2
  fi
}

# ---- 启动前后端，一起退出 ----
PIDS=()
cleanup() {
  echo ""
  echo "→ 正在停止服务…"
  for pid in "${PIDS[@]:-}"; do
    [ -n "$pid" ] && kill "$pid" 2>/dev/null || true
  done
}
trap cleanup EXIT INT TERM

echo "→ 启动后端  http://127.0.0.1:8000"
(cd "$BACKEND" && "$PHP" -S 127.0.0.1:8000 -t public router.php) &
PIDS+=($!)

echo "→ 启动前端  http://127.0.0.1:5173"
(cd "$FRONTEND" && npm run dev) &
PIDS+=($!)

cat <<EOF

════════════════════════════════════════════════════════════
  🎭 剧场无障碍字幕设备预约与归还系统已启动

  观众端 / 前台 / 技术员 / 负责人（同一入口，按角色显示菜单）:
      http://127.0.0.1:5173

  演示账号（密码均为 password）:
      观众    audience@example.com
      前台    desk@example.com
      技术员  tech@example.com
      负责人  manager@example.com

  模拟票号格式:  T-<场次ID>-XXXX   例如 T-1-A1B2

  后端 API:  http://127.0.0.1:8000/api
  健康检查:  http://127.0.0.1:8000/up

  按 Ctrl+C 可同时停止前后端。
════════════════════════════════════════════════════════════
EOF

wait
