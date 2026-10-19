<?php

namespace App\Api\V1;

use Illuminate\Http\Request;
use JsonException;
use stdClass;

/**
 * REST API（/api/v1）の JSON の本文の読み取り。Next.js 版 src/lib/api/v1/request.ts の readJsonBody と同じ規則。
 * 認証・認可の後で呼ぶ（認証されていないリクエストの本文は読まない）。中身の検証は呼び出し側。
 *
 * - Content-Type は application/json（または application/*+json）だけ。それ以外・なしは 415（Accept-Post を付ける）。
 *   charset は utf-8 だけ（JSON は UTF-8。RFC 8259）
 * - 大きさの上限は MAX_BYTES。Content-Length が上限を超えていれば読まずに 413。Content-Length がなくても、
 *   上限 + 1 バイトまでしか読まず、超えたらその時点で 413
 * - UTF-8 として正しくない・JSON として正しくない・空の本文は 400。先頭の BOM は無視する
 * - 文字列（値・キー）に NUL（U+0000）を含む本文は 400（項目ごとのエラー。pointer は JSON Pointer）
 * - JSON のオブジェクトは stdClass、配列は list のまま返す（{} と [] を区別するため）
 *
 * 注意（PHP の制約）: 本文を受信するのは PHP の SAPI（php-fpm・artisan serve）で、アプリの処理より前に終わっている。
 * さらに Laravel の Request::capture は JSON の本文を先に読み込む。受信そのものの上限は Web サーバー（nginx の
 * client_max_body_size など）と PHP の post_max_size で掛ける（README「REST API」）。ここでの上限は、アプリが扱う本文の上限
 *
 * @phpstan-import-type FieldError from ApiResponse
 */
final class ApiJsonBody
{
    /** 本文の大きさの上限（docs/openapi.json の 413。明細 100 行・備考 500 文字でも十分に収まる） */
    public const int MAX_BYTES = 65_536;

    private const int CHUNK_BYTES = 8_192;

    public static function tooLargeDetail(): string
    {
        return '本文が大きすぎます（上限 '.number_format(self::MAX_BYTES).' バイト）';
    }

    /** JSON の本文を読む（Content-Type・大きさ・UTF-8・JSON の構文・NUL を検査する）。問題があれば 4xx の応答で打ち切る */
    public static function read(Request $request): mixed
    {
        if (! self::isJsonMediaType($request->headers->get('Content-Type'))) {
            ApiResponse::abort(ApiResponse::problem(
                415,
                '本文は JSON（Content-Type: application/json、UTF-8）で送ってください',
                headers: ['Accept-Post' => 'application/json'],
            ));
        }
        $length = $request->headers->get('Content-Length');
        if (is_string($length) && preg_match('/\A[0-9]+\z/', $length) === 1 && (strlen(ltrim($length, '0')) > 18 || (int) $length > self::MAX_BYTES)) {
            ApiResponse::abort(ApiResponse::problem(413, self::tooLargeDetail()));
        }

        $text = self::readLimited($request);
        if ($text === null) {
            ApiResponse::abort(ApiResponse::problem(413, self::tooLargeDetail()));
        }
        if (str_starts_with($text, "\xEF\xBB\xBF")) {
            $text = substr($text, 3);
        }
        if (! mb_check_encoding($text, 'UTF-8')) {
            ApiResponse::abort(ApiResponse::problem(400, '本文が UTF-8 として正しくありません'));
        }
        if (trim($text, " \t\n\r") === '') {
            ApiResponse::abort(ApiResponse::problem(400, '本文が空です'));
        }

        try {
            // JSON の型を保つ（JSON_BIGINT_AS_STRING は使わない。引用符のない巨大な整数が文字列になり、文字列の項目の型検査を通ってしまう。
            // PHP の整数を超える数値は float になり、数量などの整数の項目では範囲外として拒否する）
            $value = json_decode($text, false, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            // PHP のオブジェクトは NUL で始まるキーを持てない（JSON_ERROR_INVALID_PROPERTY_NAME）。配列として読み直して位置を示す
            if ($e->getCode() === JSON_ERROR_INVALID_PROPERTY_NAME) {
                $assoc = json_decode($text, true);
                $pointers = self::nulPointers($assoc);
                if ($pointers !== []) {
                    self::abortWithNul($pointers);
                }
            }
            ApiResponse::abort(ApiResponse::problem(400, '本文が JSON として正しくありません'));
        }

        $pointers = self::nulPointers($value);
        if ($pointers !== []) {
            self::abortWithNul($pointers);
        }

        return $value;
    }

    /** JSON の Content-Type か（application/json・application/*+json。charset を付けるなら utf-8） */
    public static function isJsonMediaType(?string $contentType): bool
    {
        if ($contentType === null || $contentType === '') {
            return false;
        }
        $parts = explode(';', $contentType);
        $type = strtolower(trim($parts[0]));
        if ($type !== 'application/json' && preg_match('/\Aapplication\/[a-z0-9.!#$&^_-]+\+json\z/', $type) !== 1) {
            return false;
        }
        foreach (array_slice($parts, 1) as $parameter) {
            [$name, $value] = array_pad(explode('=', $parameter, 2), 2, '');
            if (strtolower(trim($name)) === 'charset' && strtolower(trim(trim($value), '"')) !== 'utf-8') {
                return false;
            }
        }

        return true;
    }

    /**
     * JSON Pointer（RFC 6901）の URI フラグメント表記（例 #/lines/0/quantity）
     *
     * @param  list<string|int>  $path
     */
    public static function pointer(array $path): string
    {
        return '#'.implode('', array_map(fn (string|int $key) => '/'.str_replace(['~', '/'], ['~0', '~1'], (string) $key), $path));
    }

    /** 本文を上限 + 1 バイトまで読む（上限を超えたら null） */
    private static function readLimited(Request $request): ?string
    {
        $stream = $request->getContent(true);
        $body = '';
        while (! feof($stream)) {
            $chunk = fread($stream, self::CHUNK_BYTES);
            if ($chunk === false || $chunk === '') {
                break;
            }
            $body .= $chunk;
            if (strlen($body) > self::MAX_BYTES) {
                return null;
            }
        }

        return $body;
    }

    /**
     * NUL を含む文字列（値・キー）の位置（JSON Pointer。辞書順）
     *
     * @param  list<string|int>  $path
     * @return list<string>
     */
    private static function nulPointers(mixed $value, array $path = []): array
    {
        if (is_string($value)) {
            return str_contains($value, "\0") ? [self::pointer($path)] : [];
        }
        $children = $value instanceof stdClass ? get_object_vars($value) : (is_array($value) ? $value : null);
        if ($children === null) {
            return [];
        }
        $found = [];
        foreach ($children as $key => $child) {
            $childPath = [...$path, $key];
            if (is_string($key) && str_contains($key, "\0")) {
                $found[] = self::pointer($childPath);

                continue;
            }
            array_push($found, ...self::nulPointers($child, $childPath));
        }
        sort($found);

        return $found;
    }

    /** @param  list<string>  $pointers */
    private static function abortWithNul(array $pointers): never
    {
        ApiResponse::abort(ApiResponse::validation(array_map(
            fn (string $pointer) => ['pointer' => $pointer, 'detail' => ApiQuery::NUL_MESSAGE],
            $pointers,
        )));
    }
}
