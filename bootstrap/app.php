<?php

use App\Api\V1\ApiResponse;
use App\Http\Controllers\Api\V1\ApiDocsController;
use App\Http\Middleware\EnsureUserIsActive;
use App\Http\Middleware\HandleAppearance;
use App\Http\Middleware\HandleInertiaRequests;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Middleware\AddLinkHeadersForPreloadedAssets;
use Illuminate\Http\Request;
use Illuminate\Session\Middleware\AuthenticateSession;
use Illuminate\Support\Facades\Route;
use Inertia\ExceptionResponse;
use Inertia\Inertia;
use Symfony\Component\HttpKernel\Exception\HttpException;

/** 画面ではなく JSON で応答する要求か（API と、JSON を求める要求） */
$wantsJson = fn (Request $request): bool => $request->is('api/*') || $request->expectsJson();

/**
 * 外部向け REST API（/api/v1。エラーは RFC 9457 の application/problem+json）か。ルートの照合と同じく、デコードしたパスで判定する
 * （/%61pi/v1/products もルートに一致するため）。比較はバイト列の比較にする（$request->is は /u の正規表現で照合するので、
 * UTF-8 として正しくないパスでは一致しない）
 */
$isRestApi = function (Request $request): bool {
    $path = $request->decodedPath();

    return $path === 'api/v1' || str_starts_with($path, 'api/v1/');
};

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        // 外部向け REST API（docs/openapi.json）。api ミドルウェア（セッションなし）で、接頭辞は api/v1
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
        apiPrefix: 'api/v1',
        // REST API の閲覧画面（ログイン不要の静的な HTML。Web のミドルウェア（セッション・Inertia）を通さない）
        then: function () {
            Route::get('api-docs', ApiDocsController::class)->name('api-docs');
        },
    )
    ->withMiddleware(function (Middleware $middleware) use ($isRestApi): void {
        $middleware->encryptCookies(except: ['appearance', 'sidebar_state']);

        // REST API は本文とクエリを生のまま自前で読む（App\Api\V1\ApiJsonBody・ApiQuery）ので、全体のミドルウェアで
        // 前後の空白の除去・空文字の null 化をしない（認証より前に JSON の全要素を走査するので、要素の多い本文で時間がかかる）
        $middleware->trimStrings(except: [$isRestApi]);
        $middleware->convertEmptyStringsToNull(except: [$isRestApi]);

        $middleware->web(append: [
            HandleAppearance::class,
            // 画面に渡すログイン中のユーザー（HandleInertiaRequests）を決める前に、無効ユーザーをログアウトさせる
            EnsureUserIsActive::class,
            // ログインした時点のパスワードのハッシュ（セッション・「ログインしたままにする」の Cookie に入る）を毎回いまの値と比べ、
            // 違えばログアウトさせる。パスワード再設定と同時に進んでいたログインが、再設定の後に保存したセッションも拒否できる
            AuthenticateSession::class,
            HandleInertiaRequests::class,
            AddLinkHeadersForPreloadedAssets::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions) use ($wantsJson, $isRestApi): void {
        $exceptions->shouldRenderJsonWhen($wantsJson);

        // REST API（/api/v1）: 認証（401・WWW-Authenticate）・認可（403）・未定義のパス（404）・未定義のメソッド（405・Allow）・
        // 本文の大きさ（413）・想定外のエラー（500。内容は出さない）を RFC 9457 の形で返す。下の画面・JSON 向けの描画より先に判定する
        $exceptions->render(fn (Throwable $e, Request $request) => $isRestApi($request) ? ApiResponse::fromException($e) : null);

        // JSON の応答: 未ログインは 401、権限外は 403（メッセージは日本語）。画面の未ログインはログイン画面へ（元の URL に戻る）
        $exceptions->render(function (AuthenticationException $e, Request $request) use ($wantsJson) {
            return $wantsJson($request) ? response()->json(['message' => 'ログインが必要です'], 401) : null;
        });
        $exceptions->render(function (HttpException $e, Request $request) use ($wantsJson) {
            return $e->getStatusCode() === 403 && $wantsJson($request)
                ? response()->json(['message' => 'この操作を行う権限がありません'], 403)
                : null;
        });

        // 画面の権限外（403）は、共通レイアウトの中に日本語のエラーページ（resources/js/pages/Error.vue）を出す
        Inertia::handleExceptionsUsing(function (ExceptionResponse $response) use ($wantsJson) {
            if ($response->statusCode() === 403 && ! $wantsJson($response->request) && $response->request->user() !== null) {
                return $response->render('Error', ['status' => 403])->withSharedData();
            }

            return null;
        });
    })->create();
