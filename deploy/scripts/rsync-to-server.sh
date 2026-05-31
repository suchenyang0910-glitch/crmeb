#!/usr/bin/env bash
set -euo pipefail

LOCAL_ROOT="${LOCAL_ROOT:-$(cd "$(dirname "$0")/../.." && pwd)}"
REMOTE_USER="${REMOTE_USER:-root}"
REMOTE_HOST="${REMOTE_HOST:-127.0.0.1}"
REMOTE_PATH="${REMOTE_PATH:-/data/www/crmeb-official_v6}"
SSH_PORT="${SSH_PORT:-22}"

echo "同步代码到 ${REMOTE_USER}@${REMOTE_HOST}:${REMOTE_PATH}"

rsync -avz --delete \
  --exclude ".git" \
  --exclude "runtime" \
  --exclude "node_modules" \
  --exclude ".idea" \
  --exclude ".vscode" \
  --exclude "vendor" \
  "${LOCAL_ROOT}/" \
  -e "ssh -p ${SSH_PORT}" \
  "${REMOTE_USER}@${REMOTE_HOST}:${REMOTE_PATH}/"

echo "同步完成"
