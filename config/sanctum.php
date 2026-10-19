<?php

use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Laravel\Sanctum\Http\Middleware\AuthenticateSession;

/**
 * Sanctum（外部向け REST API の Bearer トークン。docs/spec.md §6-2）。
 *
 * - Cookie のセッションでは API に入れない: stateful（SPA の Cookie 認証を許すドメイン）を空にし、guard（トークンより先に
 *   試すセッションのガード）も空にする。API の認証は Authorization: Bearer のトークンだけ（App\Users\ApiTokenAuth）
 * - トークンは Sanctum の標準の形式（<id>|<接頭辞><40 文字の乱数><CRC32>）。DB には SHA-256 のハッシュだけを保存する
 * - 有効期限は設けない（admin が失効させる）
 */
return [

    // SPA 用の /sanctum/csrf-cookie は使わない（Cookie 認証の API を提供しない）
    'routes' => false,

    'stateful' => [],

    'guard' => [],

    'expiration' => null,

    // 接頭辞で StockFlow のトークンだと分かる（ログやリポジトリに紛れ込んだときに見つけやすい）
    'token_prefix' => env('SANCTUM_TOKEN_PREFIX', 'sf_'),

    'middleware' => [
        'authenticate_session' => AuthenticateSession::class,
        'encrypt_cookies' => EncryptCookies::class,
        'validate_csrf_token' => ValidateCsrfToken::class,
    ],

];
