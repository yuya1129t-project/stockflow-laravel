<?php

namespace App\Api\V1;

use Illuminate\Auth\AuthenticationException;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\Exceptions\MalformedUrlException;
use Illuminate\Http\JsonResponse;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Symfony\Component\HttpKernel\Exception\MethodNotAllowedHttpException;
use Throwable;

/**
 * 外部向け REST API（/api/v1。docs/spec.md §6-2・docs/openapi.json）の応答の組み立て。Next.js 版 src/lib/api/v1/responses.ts と同じ形。
 *
 * - 成功は application/json、エラーは RFC 9457（application/problem+json）。type は about:blank（HTTP のステータスどおりの意味）、
 *   title は HTTP のステータスの説明（英語。RFC 9457 §4.2.1）、内容は detail（日本語）。400 の検証エラーは項目ごとの内容を
 *   拡張メンバー errors に入れる（本文の項目は pointer＝JSON Pointer、クエリ・パスのパラメーターは parameter）
 * - API の応答はキャッシュさせない（Cache-Control: no-store）
 * - /api/v1 の下の例外（未定義のパスの 404・405・認証・認可・本文の大きさ・想定外のエラー）は、bootstrap/app.php で
 *   fromException に通して problem+json にする（Laravel の既定の HTML・{message} の JSON にしない）
 *
 * @phpstan-type FieldError array{pointer?: string, parameter?: string, detail: string}
 */
final class ApiResponse
{
    public const string JSON_CONTENT_TYPE = 'application/json';

    public const string PROBLEM_CONTENT_TYPE = 'application/problem+json';

    /** 401 の WWW-Authenticate（RFC 6750。理由を区別しないため error 属性は付けない） */
    public const string WWW_AUTHENTICATE = 'Bearer realm="StockFlow API"';

    /** HTTP のステータスの説明（problem の title） */
    public const array TITLES = [
        400 => 'Bad Request',
        401 => 'Unauthorized',
        403 => 'Forbidden',
        404 => 'Not Found',
        405 => 'Method Not Allowed',
        413 => 'Content Too Large',
        415 => 'Unsupported Media Type',
        429 => 'Too Many Requests',
        500 => 'Internal Server Error',
        503 => 'Service Unavailable',
    ];

    public const string UNAUTHORIZED_DETAIL = '認証が必要です。有効な API トークンを Authorization: Bearer で指定してください';

    public const string FORBIDDEN_DETAIL = 'この操作を行う権限がありません';

    public const string NOT_FOUND_DETAIL = 'この URL の API はありません（/api-docs を参照してください）';

    public const string VALIDATION_DETAIL = '入力内容に誤りがあります。errors の各項目を確認してください';

    private const int JSON_FLAGS = JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES;

    /**
     * 成功の応答（JSON）
     *
     * @param  array<string, string>  $headers
     */
    public static function json(mixed $body, int $status = 200, array $headers = []): JsonResponse
    {
        return new JsonResponse($body, $status, [
            'Content-Type' => self::JSON_CONTENT_TYPE,
            'Cache-Control' => 'no-store',
            ...$headers,
        ], self::JSON_FLAGS);
    }

    /**
     * エラーの応答（RFC 9457）
     *
     * @param  list<FieldError>|null  $errors
     * @param  array<string, string>  $headers
     */
    public static function problem(int $status, string $detail, ?array $errors = null, array $headers = []): JsonResponse
    {
        $body = [
            'type' => 'about:blank',
            'title' => self::TITLES[$status] ?? 'Error',
            'status' => $status,
            'detail' => $detail,
        ];
        if ($errors !== null) {
            $body['errors'] = $errors;
        }

        return new JsonResponse($body, $status, [
            'Content-Type' => self::PROBLEM_CONTENT_TYPE,
            'Cache-Control' => 'no-store',
            ...$headers,
        ], self::JSON_FLAGS);
    }

    /**
     * 400 の検証エラー（項目ごとの内容つき）
     *
     * @param  list<FieldError>  $errors
     */
    public static function validation(array $errors): JsonResponse
    {
        return self::problem(400, self::VALIDATION_DETAIL, $errors);
    }

    /** 401（ヘッダーなし・形式違い・存在しない・失効済み・持ち主が無効。理由は区別しない） */
    public static function unauthorized(): JsonResponse
    {
        return self::problem(401, self::UNAUTHORIZED_DETAIL, headers: ['WWW-Authenticate' => self::WWW_AUTHENTICATE]);
    }

    /** 応答を返して処理を打ち切る（クエリ・本文の読み取りの誤り） */
    public static function abort(JsonResponse $response): never
    {
        throw new HttpResponseException($response);
    }

    /**
     * /api/v1 の下で投げられた例外の応答（bootstrap/app.php の render から呼ぶ）。HttpResponseException は持っている応答のまま返す（null）。
     * Laravel が先に変換する（ModelNotFound → 404、AuthorizationException → 403 など）ので、ここには HTTP の例外として届く
     */
    public static function fromException(Throwable $e): ?JsonResponse
    {
        if ($e instanceof HttpResponseException) {
            return null;
        }
        if ($e instanceof AuthenticationException) {
            return self::unauthorized();
        }
        if ($e instanceof ValidationException) {
            // コントローラーで項目ごとのエラーに直して返すので、通常は届かない（念のため。項目のキーを JSON Pointer にする）
            $errors = [];
            foreach ($e->errors() as $key => $messages) {
                foreach ($messages as $message) {
                    $errors[] = ['pointer' => '#/'.str_replace('.', '/', (string) $key), 'detail' => $message];
                }
            }

            return self::validation($errors);
        }
        if ($e instanceof MethodNotAllowedHttpException) {
            $allow = $e->getHeaders()['Allow'] ?? '';
            $allowed = array_values(array_filter(
                array_map(trim(...), explode(',', is_string($allow) ? $allow : '')),
                fn (string $method) => $method !== '' && $method !== 'HEAD',
            ));

            return self::problem(405, 'このメソッドは使えません（使えるメソッド: '.implode(', ', $allowed).'）', headers: self::stringHeaders($e->getHeaders()));
        }
        if ($e instanceof MalformedUrlException) {
            return self::problem(400, 'URL に UTF-8 として正しくない文字が含まれています');
        }
        if ($e instanceof HttpExceptionInterface) {
            $status = $e->getStatusCode();

            return self::problem($status, self::defaultDetail($status), headers: self::stringHeaders($e->getHeaders()));
        }

        // 想定外の例外（内容は応答に出さない。ログには Laravel の report で残る）
        return self::problem(500, self::defaultDetail(500));
    }

    private static function defaultDetail(int $status): string
    {
        return match ($status) {
            400 => 'リクエストが正しくありません',
            403 => self::FORBIDDEN_DETAIL,
            404 => self::NOT_FOUND_DETAIL,
            413 => ApiJsonBody::tooLargeDetail(),
            429 => 'リクエストが多すぎます。しばらくしてからもう一度お試しください',
            500 => 'サーバーでエラーが発生しました。時間をおいてもう一度お試しください',
            503 => 'メンテナンス中です。しばらくしてからもう一度お試しください',
            default => 'リクエストを処理できませんでした',
        };
    }

    /**
     * @param  array<array-key, mixed>  $headers
     * @return array<string, string>
     */
    private static function stringHeaders(array $headers): array
    {
        $result = [];
        foreach ($headers as $name => $value) {
            if (is_string($name) && (is_string($value) || is_int($value))) {
                $result[$name] = (string) $value;
            }
        }

        return $result;
    }
}
