<?php

use App\Api\V1\ApiDocsPage;

/**
 * REST API の閲覧画面（GET /api-docs。Swagger UI）。ログイン不要、Swagger UI は CDN（版固定・SRI）、CSP はこの応答だけ
 */
test('ログインなしで Swagger UI の画面を返す（/api/v1/openapi.json を読む。Cookie を発行しない）', function () {
    $response = $this->get('/api-docs');

    $response->assertOk()
        ->assertHeader('Content-Type', 'text/html; charset=utf-8')
        ->assertHeader('Referrer-Policy', 'no-referrer')
        ->assertHeader('X-Content-Type-Options', 'nosniff')
        ->assertHeader('Cache-Control', 'no-store, private')
        ->assertHeaderMissing('Set-Cookie')
        ->assertSee('url: "/api/v1/openapi.json"', false);
});

test('Swagger UI は版を固定した CDN から SRI 付きで読み込む', function () {
    $html = (string) $this->get('/api-docs')->getContent();

    foreach (ApiDocsPage::ASSETS as $asset) {
        expect($asset['url'])->toStartWith('https://cdn.jsdelivr.net/npm/swagger-ui-dist@'.ApiDocsPage::SWAGGER_UI_VERSION.'/')
            ->and($asset['integrity'])->toMatch('/\Asha384-[A-Za-z0-9+\/]{64}\z/')
            ->and($html)->toContain("\"{$asset['url']}\" integrity=\"{$asset['integrity']}\" crossorigin=\"anonymous\"");
    }
});

test('CSP: スクリプトは版を固定した CDN と、初期化スクリプト（ハッシュ）だけ。通信は同じオリジンだけ', function () {
    $response = $this->get('/api-docs');
    $csp = (string) $response->headers->get('Content-Security-Policy');
    $directives = [];
    foreach (explode(';', $csp) as $directive) {
        $parts = preg_split('/\s+/', trim($directive)) ?: [];
        $directives[array_shift($parts)] = $parts;
    }
    // インラインのスクリプトはページの中の 1 つだけで、その中身のハッシュが CSP に入っている
    preg_match_all('/<script>(.*?)<\/script>/s', (string) $response->getContent(), $inline);
    expect($inline[1])->toBe([ApiDocsPage::INIT_SCRIPT]);
    $hash = "'sha256-".base64_encode(hash('sha256', $inline[1][0], true))."'";

    expect($directives['default-src'])->toBe(["'none'"])
        ->and($directives['script-src'])->toBe([ApiDocsPage::CDN_BASE, $hash])
        ->and($directives['style-src'])->toBe([ApiDocsPage::CDN_BASE, "'unsafe-inline'"])
        ->and($directives['connect-src'])->toBe(["'self'"])
        ->and($directives['img-src'])->toBe(["'self'", 'data:'])
        ->and($directives['frame-ancestors'])->toBe(["'none'"])
        ->and($directives['base-uri'])->toBe(["'none'"])
        ->and($directives['form-action'])->toBe(["'none'"])
        ->and($csp)->not->toContain('unsafe-eval');
});

test('ほかの画面には /api-docs の CSP を付けない', function () {
    $this->get('/login')->assertOk()->assertHeaderMissing('Content-Security-Policy');
});
