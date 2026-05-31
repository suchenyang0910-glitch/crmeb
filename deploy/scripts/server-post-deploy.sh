#!/usr/bin/env bash
set -euo pipefail

APP_ROOT="${APP_ROOT:-/data/www/crmeb-official_v6}"
APP_USER="${APP_USER:-www-data}"
APP_GROUP="${APP_GROUP:-www-data}"
PHP_BIN="${PHP_BIN:-/usr/bin/php}"
SUPERVISOR_SERVICE="${SUPERVISOR_SERVICE:-supervisor}"
NGINX_SERVICE="${NGINX_SERVICE:-nginx}"

echo "[1/7] 检查目录"
test -d "${APP_ROOT}"
cd "${APP_ROOT}"

echo "[2/7] 安装 Composer 依赖"
composer install --no-dev --optimize-autoloader

echo "[3/7] 执行框架发现"
"${PHP_BIN}" think service:discover
"${PHP_BIN}" think vendor:publish

echo "[4/7] 创建运行目录"
mkdir -p runtime
mkdir -p public/uploads
mkdir -p /data/logs/crmeb
mkdir -p /data/logs/supervisor

echo "[5/7] 修正权限"
chown -R "${APP_USER}:${APP_GROUP}" "${APP_ROOT}"
chmod -R 775 runtime public/uploads

echo "[6/7] 检查关键文件"
test -f .env
test -f .constant
test -f public/install.lock
test -f public/index.php

echo "[7/7] 重载服务"
systemctl reload "${NGINX_SERVICE}"
systemctl restart "${SUPERVISOR_SERVICE}"

echo "部署后处理完成"
