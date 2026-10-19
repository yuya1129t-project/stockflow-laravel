<?php

namespace App\Api\V1;

use Illuminate\Http\Response;

/**
 * REST API の閲覧画面（GET /api-docs）。Swagger UI で /api/v1/openapi.json を表示する（docs/spec.md §6-2）。
 * Next.js 版 src/lib/api/v1/docs-page.ts と同じ考え方・同じ版・同じ SRI。
 *
 * - ログインなしで閲覧できる（API の形だけで秘密やデータは含まない。中身は公開している openapi.json と同じ）
 * - Swagger UI は依存に入れず、jsDelivr（npm の swagger-ui-dist、版を固定）から読み込む。SRI（integrity）で
 *   CDN の内容が改ざん・差し替えされていたら読み込まない。版を変えるときは integrity も計算し直す
 *   （`curl -s https://cdn.jsdelivr.net/npm/swagger-ui-dist@<版>/swagger-ui-bundle.js | openssl dgst -sha384 -binary | openssl base64 -A`）
 * - この応答だけに CSP を付ける（スクリプトはその版の CDN のパスと、下の初期化スクリプト（ハッシュで許可）だけ。
 *   通信は同じオリジンだけ）。アプリのほかの画面の CSP は変えない
 * - 「Try it out」で入力したトークンはブラウザのメモリ上だけに置く（persistAuthorization は false）
 * - Web のミドルウェア（セッション・Inertia）を通さない（bootstrap/app.php の then で登録。Cookie を発行しない）
 */
final class ApiDocsPage
{
    public const string SWAGGER_UI_VERSION = '5.33.1';

    public const string CDN_BASE = 'https://cdn.jsdelivr.net/npm/swagger-ui-dist@'.self::SWAGGER_UI_VERSION.'/';

    public const array ASSETS = [
        'script' => [
            'url' => self::CDN_BASE.'swagger-ui-bundle.js',
            'integrity' => 'sha384-ZPehFMQommnnuaZ4rpxgkgTT2DKFVp4hZC/7pLit+9Lek9T1YGSo23eHFbvNkXkw',
        ],
        'style' => [
            'url' => self::CDN_BASE.'swagger-ui.css',
            'integrity' => 'sha384-Ov4/wv3j2bmct8cDc5X4ngJZohVPzEmc6uDPH8WeljUxO5vtoykvMEfbu9Vh6RaW',
        ],
    ];

    /** 初期化スクリプト（インライン。CSP ではハッシュで許可する） */
    public const string INIT_SCRIPT = <<<'JS'
        window.addEventListener("load", function () {
          window.ui = SwaggerUIBundle({
            url: "/api/v1/openapi.json",
            dom_id: "#swagger-ui",
            deepLinking: true,
            validatorUrl: null,
            tryItOutEnabled: false,
            persistAuthorization: false
          });
        });
        JS;

    /** 初期化スクリプトの CSP のハッシュ（'sha256-…'） */
    public static function scriptHash(): string
    {
        return "'sha256-".base64_encode(hash('sha256', self::INIT_SCRIPT, true))."'";
    }

    public static function contentSecurityPolicy(): string
    {
        return implode('; ', [
            "default-src 'none'",
            'script-src '.self::CDN_BASE.' '.self::scriptHash(),
            // Swagger UI が style 属性を使うため 'unsafe-inline' が要る（スクリプトは上で止めている。Next.js 版と同じ）
            'style-src '.self::CDN_BASE." 'unsafe-inline'",
            "img-src 'self' data:",
            "connect-src 'self'",
            "base-uri 'none'",
            "form-action 'none'",
            "frame-ancestors 'none'",
        ]);
    }

    public static function html(): string
    {
        $style = self::ASSETS['style'];
        $script = self::ASSETS['script'];

        return <<<HTML
            <!doctype html>
            <html lang="ja">
              <head>
                <meta charset="utf-8" />
                <meta name="viewport" content="width=device-width, initial-scale=1" />
                <title>StockFlow API ドキュメント</title>
                <link rel="stylesheet" href="{$style['url']}" integrity="{$style['integrity']}" crossorigin="anonymous" />
              </head>
              <body>
                <div id="swagger-ui"></div>
                <script src="{$script['url']}" integrity="{$script['integrity']}" crossorigin="anonymous"></script>
                <script>
            HTML.self::INIT_SCRIPT.<<<'HTML'
            </script>
              </body>
            </html>

            HTML;
    }

    public static function response(): Response
    {
        return new Response(self::html(), 200, [
            'Content-Type' => 'text/html; charset=utf-8',
            'Content-Security-Policy' => self::contentSecurityPolicy(),
            'Referrer-Policy' => 'no-referrer',
            'X-Content-Type-Options' => 'nosniff',
            'Cache-Control' => 'no-store',
        ]);
    }
}
