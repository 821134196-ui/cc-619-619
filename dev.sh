#!/usr/bin/env bash
# 一条命令启动：后端 Laravel API + 前端 Vite(React) 开发服务
# 用法：
#   ./dev.sh            首次自动安装依赖、建库、迁移、播种并启动
#   ./dev.sh --fresh    清空数据重新播种后启动
set -euo pipefail

ROOT="$(cd "$(dirname "$0")" && pwd)"
APP="$ROOT/backend"
cd "$APP"

# 解析 PHP：优先 PATH 中的 php，其次使用随仓库环境准备的便携版
if command -v php >/dev/null 2>&1; then
  PHP_BIN="php"
elif [ -x "$HOME/php-portable/bin/php" ]; then
  PHP_BIN="$HOME/php-portable/bin/php"
else
  echo "未找到 PHP（需要 PHP 8.2+，含 pdo_sqlite/mbstring）" >&2
  exit 1
fi
COMPOSER="$(command -v composer || true)"
[ -x "$COMPOSER" ] || [ -x "$HOME/php-portable/bin/composer-wrap" ] && COMPOSER="${COMPOSER:-$HOME/php-portable/bin/composer-wrap}"

echo "== 使用 PHP: $($PHP_BIN -r 'echo PHP_VERSION;')"

[ -d vendor ] || "$COMPOSER" install --no-interaction
[ -d node_modules ] || npm install

[ -f .env ] || cp .env.example .env
$PHP_BIN -r '
$f=".env"; $s=file_get_contents($f);
if (!preg_match("/^APP_KEY=/m",$s) || preg_match("/^APP_KEY=$/m",$s)) { passthru("php artisan key:generate --no-interaction"); }
' 2>/dev/null || $PHP_BIN artisan key:generate --force
if ! grep -q '^DB_CONNECTION=sqlite' .env; then
  sed -i 's/^DB_CONNECTION=.*/DB_CONNECTION=sqlite/' .env
fi
touch database/database.sqlite

if [ "${1:-}" = "--fresh" ] || [ ! -s database/database.sqlite ]; then
  echo "== 初始化数据库（迁移 + 播种）"
  $PHP_BIN artisan migrate:fresh --seed --force
else
  $PHP_BIN artisan migrate --force
fi

echo "== 启动：API 与页面 http://127.0.0.1:8000 （Vite 资源 5173）"
# 直接用 PHP 内建服务器 + Laravel 路由存根（不经 artisan serve，避免其剥离 PHP ini 环境）
# 存根以 getcwd() 为 public 路径，故在 public/ 内启动
exec npx concurrently --kill-others-on-fail --names server,vite --colors blue,magenta \
  "cd '$APP/public' && $PHP_BIN -S 127.0.0.1:8000 ../vendor/laravel/framework/src/Illuminate/Foundation/resources/server.php" \
  "npm run dev -- --host 127.0.0.1 --port 5173"
