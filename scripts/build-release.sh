#!/usr/bin/env bash
# 本番用の配布物を作る（公開先: さくらのレンタルサーバ・PHP 8.3。サーバーには composer がないため vendor と public/build を手元で作る）。
# サーバーへの送信はしない（手順は README の「公開（さくらのレンタルサーバ）」と #14）。
#
# 使い方: scripts/build-release.sh [git の参照（既定: HEAD）]
#   - コミット済みの内容（git archive）から作る。コミットしていない変更は含まれない
#   - 必要なもの: Docker、起動中の Sail（画面のビルドは Sail の Node.js で行う。Wayfinder が php artisan を使うため）
#   - 出力: build/release/stockflow-laravel-<短いハッシュ>.tar.gz（build/ は .gitignore 済み）
#   - 配布物に .env は入れない（サーバーの .env を上書きしないため）
#   - 途中で失敗・中断したときは、作業ディレクトリと作りかけのアーカイブを消す（成功したときだけ最終名にする）
set -euo pipefail

cd "$(dirname "$0")/.."

REF="${1:-HEAD}"
SHA="$(git rev-parse --short "$REF")"
NAME="stockflow-laravel-${SHA}"
OUT="build/release"
# 作業ディレクトリは Sail から見えるよう、プロジェクトの中（Sail の共有マウントの下）に置く
WORK="${OUT}/${NAME}"
TOOLS="${OUT}/.tools-${SHA}"
ARCHIVE="${OUT}/${NAME}.tar.gz"
ARCHIVE_TMP="${ARCHIVE}.partial"
# 版を固定する（タグ＋ダイジェスト）。composer のイメージは zip 拡張があり、依存を取得・展開する（PHP は 8.5 だが、
# 依存は composer.json の config.platform.php（8.3.36）で解いたロックのとおりに入る）。起動の確認は公開先と同じ PHP 8.3.36 で行う
COMPOSER_IMAGE="composer:2.10.3@sha256:d0c32820c62cc860fddd22adceed4eeda7698b093a7a88d503414172ce400626"
PHP_IMAGE="php:8.3.36-cli@sha256:74caa458ad8d6c5e401eb9f8841a62faeab9b7be42b54cd37986d7685f857618"

cleanup() {
    local status=$?
    rm -f "${WORK}/.env"
    rm -rf "$WORK" "$TOOLS" "$ARCHIVE_TMP"
    if [ "$status" -ne 0 ]; then
        echo "失敗しました（終了コード ${status}）。作業ディレクトリと作りかけのアーカイブは消しました" >&2
    fi
}

if [ -n "$(git status --porcelain --untracked-files=no)" ]; then
    echo "注意: コミットしていない変更は含まれません（${REF} = ${SHA} から作ります）" >&2
fi

if ! ./vendor/bin/sail exec -T laravel.test true >/dev/null 2>&1; then
    echo "Sail が起動していません（./vendor/bin/sail up -d）" >&2
    exit 1
fi

trap cleanup EXIT
trap 'exit 130' INT
trap 'exit 143' TERM

echo "==> ${REF}（${SHA}）を ${WORK} に展開"
rm -rf "$WORK" "$TOOLS" "$ARCHIVE_TMP"
mkdir -p "$WORK" "$TOOLS"
git archive "$REF" | tar -x -C "$WORK"

# PHP 8.3 のコンテナで使う composer（composer のイメージから取り出す）
docker run --rm --entrypoint cat "$COMPOSER_IMAGE" /usr/bin/composer > "${TOOLS}/composer"

php83() {
    docker run --rm \
        -u "$(id -u):$(id -g)" \
        -e COMPOSER_HOME=/tmp/composer \
        -v "$(pwd)/${WORK}":/app \
        -v "$(pwd)/${TOOLS}/composer":/usr/local/bin/composer:ro \
        -w /app \
        "$@"
}

echo "==> PHP の依存（本番のみ）を入れる"
docker run --rm \
    -u "$(id -u):$(id -g)" \
    -e COMPOSER_HOME=/tmp/composer \
    -v "$(pwd)/${WORK}":/app \
    -w /app \
    "$COMPOSER_IMAGE" \
    composer install --no-dev --optimize-autoloader --no-scripts --no-interaction --prefer-dist --no-progress

echo "==> PHP 8.3.36 で拡張の確認と package:discover"
php83 "$PHP_IMAGE" sh -c 'php -v | head -n 1 && php /usr/local/bin/composer --version && php /usr/local/bin/composer check-platform-reqs --no-dev && php artisan package:discover --ansi'

echo "==> 画面のビルド（Sail の Node.js）"
# VITE_APP_NAME（画面のタイトル）を .env.example から読む。ビルドの後で消す（失敗したときも cleanup で消す）
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

echo "==> 確認（PHP 8.3.36）"
test -f "${WORK}/docs/openapi.json"
test -f "${WORK}/public/build/manifest.json"
test ! -e "${WORK}/.env"
# vendor に開発の依存がないこと（installed.json の記録と、主なパッケージのディレクトリ）
php83 "$PHP_IMAGE" php -r '
    $j = json_decode(file_get_contents("vendor/composer/installed.json"), true);
    if ($j["dev"] !== false || $j["dev-package-names"] !== []) { fwrite(STDERR, "vendor に開発の依存があります\n"); exit(1); }
    foreach (["pestphp", "phpunit", "mockery", "fakerphp", "larastan", "laravel/pint", "laravel/sail", "laravel/pail"] as $d) {
        if (file_exists("vendor/$d")) { fwrite(STDERR, "vendor/$d があります\n"); exit(1); }
    }
    echo "vendor: 本番の依存だけ（" . count($j["packages"]) . " パッケージ）\n";
'
# アプリを本番の設定で起動し、ルートとプロバイダーを読み込めること（DB には接続しない。鍵は確認のためだけの使い捨て）
php83 -e APP_ENV=production -e APP_DEBUG=false -e "APP_KEY=base64:$(head -c 32 /dev/urandom | base64)" \
    "$PHP_IMAGE" sh -c 'php artisan --version && php artisan route:list --except-vendor > /dev/null && echo "route:list: OK（$(php artisan route:list --json | php -r "echo count(json_decode(stream_get_contents(STDIN)));") ルート）"'

COPYFILE_DISABLE=1 tar -czf "$ARCHIVE_TMP" -C "$OUT" "$NAME"
# 配布物に .env と macOS の ._* がないこと
if tar -tzf "$ARCHIVE_TMP" | grep -E '(^|/)(\.env$|\._)'; then
    echo "配布物に .env または ._* が入っています" >&2
    exit 1
fi
mv "$ARCHIVE_TMP" "$ARCHIVE"
echo "==> 作成: ${ARCHIVE}（$(du -h "$ARCHIVE" | cut -f1)）"
