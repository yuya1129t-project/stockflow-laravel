#!/usr/bin/env bash
# 本番用の配布物を作る（公開先: さくらのレンタルサーバ・PHP 8.3。サーバーには composer がないため vendor と public/build を手元で作る）。
# サーバーへの送信はしない（手順は README の「公開（さくらのレンタルサーバ）」と #14）。
#
# 使い方: scripts/build-release.sh [git の参照（既定: HEAD）]
#   - コミット済みの内容（git archive）から作る。コミットしていない変更は含まれない
#   - 必要なもの: Docker、起動中の Sail（画面のビルドは Sail の Node.js で行う。Wayfinder が php artisan を使うため）
#   - 出力: build/release/stockflow-laravel-<短いハッシュ>.tar.gz（build/ は .gitignore 済み）
#   - 配布物に .env は入れない（サーバーの .env を上書きしないため）
set -euo pipefail

cd "$(dirname "$0")/.."

REF="${1:-HEAD}"
SHA="$(git rev-parse --short "$REF")"
NAME="stockflow-laravel-${SHA}"
OUT="build/release"
WORK="${OUT}/${NAME}"
ARCHIVE="${OUT}/${NAME}.tar.gz"
# PHP 8.3 のコンテナ（zip 拡張入り）。依存の版は composer.json の config.platform.php（8.3.36）で解決済みのロックのとおり
COMPOSER_IMAGE="laravelsail/php83-composer:latest"

if [ -n "$(git status --porcelain --untracked-files=no)" ]; then
    echo "注意: コミットしていない変更は含まれません（${REF} = ${SHA} から作ります）" >&2
fi

if ! ./vendor/bin/sail exec -T laravel.test true >/dev/null 2>&1; then
    echo "Sail が起動していません（./vendor/bin/sail up -d）" >&2
    exit 1
fi

echo "==> ${REF}（${SHA}）を ${WORK} に展開"
rm -rf "$WORK" "$ARCHIVE"
mkdir -p "$WORK"
git archive "$REF" | tar -x -C "$WORK"

echo "==> PHP の依存（本番のみ）を PHP 8.3 のコンテナで入れる"
docker run --rm \
    -u "$(id -u):$(id -g)" \
    -e COMPOSER_HOME=/tmp/composer \
    -v "$(pwd)/${WORK}":/app \
    -w /app \
    "$COMPOSER_IMAGE" \
    sh -c 'php -v | head -n 1 && composer install --no-dev --optimize-autoloader --no-interaction --prefer-dist --no-progress'

echo "==> 画面のビルド（Sail の Node.js）"
# VITE_APP_NAME（画面のタイトル）を .env.example から読む。ビルドの後で消す
cp "${WORK}/.env.example" "${WORK}/.env"
./vendor/bin/sail exec -T -u sail laravel.test bash -c "cd '/var/www/html/${WORK}' && npm ci --no-audit --no-fund && npm run build"
rm -f "${WORK}/.env"

echo "==> 不要物を除く"
(
    cd "$WORK"
    # docs は openapi.json だけ残す（GET /api/v1/openapi.json と /api-docs が実行時に読む）
    find docs -type f ! -name openapi.json -delete
    rm -rf node_modules tests .github .claude resources/js resources/css bootstrap/ssr public/hot \
        .editorconfig .gitattributes .gitignore .npmrc CLAUDE.md README.md \
        components.json compose.yaml package.json package-lock.json phpstan.neon phpunit.xml pint.json \
        tsconfig.json vite.config.ts
)

echo "==> 確認"
test -f "${WORK}/docs/openapi.json"
test -f "${WORK}/public/build/manifest.json"
test -f "${WORK}/vendor/autoload.php"
test ! -e "${WORK}/vendor/pestphp"
test ! -e "${WORK}/.env"
# PHP 8.3 で起動できること（DB には接続しない）
docker run --rm -u "$(id -u):$(id -g)" -v "$(pwd)/${WORK}":/app -w /app "$COMPOSER_IMAGE" php artisan --version

COPYFILE_DISABLE=1 tar -czf "$ARCHIVE" -C "$OUT" "$NAME"
echo "==> 作成: ${ARCHIVE}（$(du -h "$ARCHIVE" | cut -f1)）"
