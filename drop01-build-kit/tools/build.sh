#!/usr/bin/env bash
# Builds dist/drop01-deploy.zip: copies runtime files only, self-hosts fonts, stamps the version, lints every PHP file, zips.
set -euo pipefail
cd "$(dirname "$0")/.."
ROOT=$(pwd)
BUILD="$ROOT/dist/build"
ZIP="$ROOT/dist/drop01-deploy.zip"

# PHP: use the local binary if there is one, else the Docker image from tools/docker
if command -v php >/dev/null 2>&1; then PHP=(php); else PHP=(docker run --rm -v "$ROOT":/repo -w /repo drop01-php php); fi

echo "→ fonts"
[ -f src/assets/fonts/fonts.css ] || ./tools/fetch-fonts.sh

echo "→ copy runtime files"
rm -rf "$BUILD" "$ZIP"; mkdir -p "$BUILD"
# only src/ ships; never data/, tools/, tests, prototypes or OS junk
rsync -a --exclude 'data/' --exclude '.DS_Store' --exclude '*.sqlite' --exclude '*.log' --exclude 'uploads/products/' src/ "$BUILD/"

echo "→ version"
HASH=$(git rev-parse --short HEAD 2>/dev/null || true)
VERSION="$(date -u +%Y.%m.%d-%H%M)${HASH:+-$HASH}"
printf "<?php\n// Version stamp written by tools/build.sh.\nconst APP_VERSION = '%s';\n" "$VERSION" > "$BUILD/app/version.php"

echo "→ minify (optional)"
if command -v esbuild >/dev/null 2>&1; then
  for f in $(find "$BUILD/assets" -name '*.js' -o -name '*.css'); do esbuild "$f" --minify --allow-overwrite --outfile="$f" --log-level=warning; done
  echo "  minified with esbuild"
else
  echo "  esbuild not installed: shipping unminified (gzip on the server still applies)"
fi

echo "→ php -l"
FAIL=0
while IFS= read -r f; do
  out=$("${PHP[@]}" -l "${f#$ROOT/}" 2>&1) || { echo "$out"; FAIL=1; }
done < <(find "$BUILD" -name '*.php')
[ $FAIL -eq 0 ] || { echo "✕ PHP syntax errors, build stopped"; exit 1; }

echo "→ zip"
(cd "$BUILD" && zip -qr -X "$ZIP" . -x '.DS_Store')
cp DEPLOY-GODADDY.md dist/DEPLOY-GODADDY.md 2>/dev/null || true

COUNT=$(unzip -Z1 "$ZIP" | grep -v '/$' | wc -l | tr -d ' ')
SIZE=$(du -h "$ZIP" | cut -f1)
echo "✓ dist/drop01-deploy.zip  version $VERSION  ·  $SIZE  ·  $COUNT files"
