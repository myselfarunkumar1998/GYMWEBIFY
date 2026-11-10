#!/usr/bin/env bash
# Builds build/public_html and build/gymapp-bluehost.zip ready to upload to cPanel.
set -euo pipefail
cd "$(dirname "$0")/.."
ROOT=$(pwd)
OUT=$ROOT/build/public_html
rm -rf "$ROOT/build" && mkdir -p "$OUT"

echo "› Building web app"
(cd web && npm ci --no-audit --no-fund >/dev/null 2>&1 || npm install --no-audit --no-fund >/dev/null; npx vite build >/dev/null)
cp -r web/dist/. "$OUT/"

echo "› Copying API"
mkdir -p "$OUT/api"
cp -r api/src api/db api/index.php api/adms.php api/cron.php api/install.php "$OUT/api/"
mkdir -p "$OUT/api/uploads" "$OUT/api/logs"
cp deploy/htaccess-root "$OUT/.htaccess"
cp deploy/htaccess-api "$OUT/api/.htaccess"
for d in src db uploads logs; do cp deploy/htaccess-deny "$OUT/api/$d/.htaccess"; done
cp deploy/user.ini "$OUT/.user.ini"
cp deploy/user.ini "$OUT/api/.user.ini"

KEY=$(php -r 'echo bin2hex(random_bytes(12));')
echo "$KEY" > "$OUT/api/INSTALL_KEY.txt"

echo "› Checking PHP syntax"
find "$OUT/api" -name '*.php' -print0 | xargs -0 -n1 php -l | grep -v "No syntax errors" || true

(cd "$ROOT/build" && rm -f gymapp-bluehost.zip && cd public_html && zip -qr ../gymapp-bluehost.zip . -x '*.DS_Store')
echo "✓ build/gymapp-bluehost.zip  ($(du -h "$ROOT/build/gymapp-bluehost.zip" | cut -f1))"
echo "  Install key: $KEY  (also in api/INSTALL_KEY.txt inside the zip)"
