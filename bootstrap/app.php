<?php

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
use Inertia\ExceptionResponse;
use Inertia\Inertia;
use Symfony\Component\HttpKernel\Exception\HttpException;

/** 画面ではなく JSON で応答する要求か（API と、JSON を求める要求） */
$wantsJson = fn (Request $request): bool => $request->is('api/*') || $request->expectsJson();

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->encryptCookies(except: ['appearance', 'sidebar_state']);

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
    ->withExceptions(function (Exceptions $exceptions) use ($wantsJson): void {
        $exceptions->shouldRenderJsonWhen($wantsJson);

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
