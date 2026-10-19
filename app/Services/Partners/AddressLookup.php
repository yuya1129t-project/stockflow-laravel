<?php

namespace App\Services\Partners;

use App\Rules\SingleLine;
use App\Support\Partners\PostalCode;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * 郵便番号 → 住所（zipcloud。docs/spec.md §6）。外部 API はブラウザから直接呼ばず、サーバー側のここから呼ぶ。
 *
 * - API: https://zipcloud.ibsnet.co.jp/doc/api （GET /api/search?zipcode=…。読み取りのみ）
 * - タイムアウト 3 秒（接続から本文の受信まで）。宛先は固定で、リダイレクトはたどらない（3xx は失敗）
 * - 本文は 64KB まで。受信しながら数え、超えたらその時点で受信を打ち切る（Content-Length のない応答・chunked も同じ）
 * - 失敗・該当なし・形式エラーは理由付きの結果を返し、例外にしない（画面は「住所を手入力してください」と出す）
 * - 外部の応答は型・件数・長さ・制御文字・問い合わせた郵便番号との一致を検証してから使う
 * - 郵便番号ごとの結果（住所・該当なし）を Laravel の Cache に 1 日保存する。一時的な失敗（タイムアウトなど）は保存しない
 * - 失敗の原因はサーバーのログに残す。記録するのは決まった形の短い値だけ（HTTP ステータス、cURL のエラー番号、
 *   妥当な範囲の整数の status）で、外部の応答の文字列・例外のメッセージ・郵便番号・利用者の情報は出さない
 * - テストでは Http::fake で差し替える（CI から実 API を呼ばない。tests/TestCase.php で preventStrayRequests）
 */
class AddressLookup
{
    public const string ENDPOINT = 'https://zipcloud.ibsnet.co.jp/api/search';

    /** 外部 API のタイムアウト（秒。spec §6） */
    public const int TIMEOUT_SECONDS = 3;

    /** 結果のキャッシュの保存期間（秒） */
    public const int CACHE_TTL_SECONDS = 24 * 60 * 60;

    /** 住所の最大長（取引先の住所と同じ） */
    private const int MAX_ADDRESS_LENGTH = 200;

    /** 応答の本文の上限（バイト。zipcloud の応答は数 KB 以下） */
    private const int MAX_BODY_BYTES = 64 * 1024;

    /** 候補の件数の上限 */
    private const int MAX_RESULTS = 100;

    /**
     * @param  string  $endpoint  呼び出す先（本番は zipcloud 固定。テストで受信の打ち切りを確かめるときだけローカルのサーバーを渡す）
     */
    public function __construct(private readonly string $endpoint = self::ENDPOINT) {}

    public static function cacheKey(string $postalCode): string
    {
        return "address-lookup:{$postalCode}";
    }

    /** 郵便番号（ハイフン有無・全角どちらでも）から住所の候補を得る。キャッシュを通す */
    public function lookup(string $input): AddressLookupResult
    {
        $postalCode = PostalCode::normalize($input);
        if ($postalCode === null) {
            return AddressLookupResult::failed(AddressLookupFailure::InvalidPostalCode);
        }

        $cached = Cache::get(self::cacheKey($postalCode));
        if (is_array($cached) && isset($cached['addresses']) && is_array($cached['addresses'])) {
            /** @var list<string> $addresses */
            $addresses = array_values(array_filter($cached['addresses'], 'is_string'));

            return $addresses === []
                ? AddressLookupResult::failed(AddressLookupFailure::NotFound, $postalCode)
                : AddressLookupResult::found($postalCode, $addresses);
        }

        $result = $this->fetch($postalCode);

        // 成功と「該当なし」だけを保存する
        if ($result->ok() || $result->failure === AddressLookupFailure::NotFound) {
            Cache::put(self::cacheKey($postalCode), ['addresses' => $result->addresses], self::CACHE_TTL_SECONDS);
        }

        return $result;
    }

    /** zipcloud を呼ぶ（キャッシュなし） */
    private function fetch(string $postalCode): AddressLookupResult
    {
        $tooLarge = false;
        try {
            $response = Http::timeout(self::TIMEOUT_SECONDS)
                ->connectTimeout(self::TIMEOUT_SECONDS)
                ->withoutRedirecting()
                ->acceptJson()
                ->withOptions([
                    // 受信の途中で呼ばれる。Content-Length が上限を超えるか、受信した量が上限を超えたら true を返して打ち切る
                    // （ヘッダーのない応答・chunked も受信した量で止まる）
                    'progress' => function (int $downloadTotal, int $downloaded) use (&$tooLarge): bool {
                        $tooLarge = $tooLarge || $downloadTotal > self::MAX_BODY_BYTES || $downloaded > self::MAX_BODY_BYTES;

                        return $tooLarge;
                    },
                ])
                ->get($this->endpoint, ['zipcode' => $postalCode]);
        } catch (ConnectionException|RequestException $e) {
            if ($tooLarge) {
                return $this->fail(AddressLookupFailure::InvalidResponse, $postalCode, '応答が大きすぎます');
            }
            if ($e instanceof RequestException) {
                // 受信の途中で失敗した、エラーの HTTP ステータスの応答
                return $this->fail(AddressLookupFailure::Unavailable, $postalCode, "HTTP {$e->response->status()}");
            }
            $curlError = self::curlErrorNumber($e->getMessage());
            $timeout = $curlError === 28;

            return $this->fail(
                $timeout ? AddressLookupFailure::Timeout : AddressLookupFailure::Unavailable,
                $postalCode,
                $curlError !== null ? "cURL error {$curlError}" : '通信エラー',
            );
        }

        if ($response->status() !== 200) {
            return $this->fail(AddressLookupFailure::Unavailable, $postalCode, "HTTP {$response->status()}");
        }

        $body = $response->body();
        if (strlen($body) > self::MAX_BODY_BYTES) {
            return $this->fail(AddressLookupFailure::InvalidResponse, $postalCode, '応答が大きすぎます');
        }

        $decoded = json_decode($body, true);
        $result = self::parse($postalCode, $decoded);
        if (! $result->ok() && $result->failure !== AddressLookupFailure::NotFound) {
            // 外部の値は、妥当な範囲の整数のときだけ記録する（文字列などは決まった識別子にする）
            $status = is_array($decoded) ? ($decoded['status'] ?? null) : null;
            $status = match (true) {
                $status === null => '（なし）',
                is_int($status) && $status >= 100 && $status <= 599 => (string) $status,
                default => '（不正な値）',
            };

            return $this->fail($result->failure ?? AddressLookupFailure::InvalidResponse, $postalCode, "応答の status: {$status}");
        }

        return $result;
    }

    /**
     * zipcloud の応答（JSON をデコードした値）を解釈する。純粋な関数。
     * 本文の status が 200 以外（zipcloud は HTTP 200 のまま本文の status でエラーを返す）は unavailable
     */
    public static function parse(string $postalCode, mixed $body): AddressLookupResult
    {
        $invalid = AddressLookupResult::failed(AddressLookupFailure::InvalidResponse, $postalCode);

        if (! is_array($body) || ! is_int($body['status'] ?? null) || ! array_key_exists('results', $body)) {
            return $invalid;
        }
        if ($body['status'] !== 200) {
            return AddressLookupResult::failed(AddressLookupFailure::Unavailable, $postalCode);
        }

        $results = $body['results'];
        if ($results === null || $results === []) {
            return AddressLookupResult::failed(AddressLookupFailure::NotFound, $postalCode);
        }
        if (! is_array($results) || ! array_is_list($results) || count($results) > self::MAX_RESULTS) {
            return $invalid;
        }

        $addresses = [];
        foreach ($results as $row) {
            if (! is_array($row) || ($row['zipcode'] ?? null) !== $postalCode) {
                // 問い合わせた郵便番号と違う結果は使わない
                return $invalid;
            }
            $parts = [];
            foreach (['address1', 'address2', 'address3'] as $key) {
                $part = $row[$key] ?? null;
                if (! is_string($part) || mb_strlen($part) > self::MAX_ADDRESS_LENGTH || ! SingleLine::passes($part)) {
                    return $invalid;
                }
                $parts[] = $part;
            }
            $address = implode('', $parts);
            if ($address !== '' && mb_strlen($address) <= self::MAX_ADDRESS_LENGTH) {
                $addresses[] = $address;
            }
        }

        $addresses = array_values(array_unique($addresses));

        return $addresses === [] ? $invalid : AddressLookupResult::found($postalCode, $addresses);
    }

    /** 例外のメッセージから cURL のエラー番号だけを取り出す（メッセージ自体は URL などを含むので記録しない） */
    private static function curlErrorNumber(string $message): ?int
    {
        return preg_match('/cURL error (\d{1,3})\b/', $message, $m) === 1 ? (int) $m[1] : null;
    }

    /**
     * 失敗の原因をログに残す。$detail はこのクラスが組み立てた決まった形の短い値だけ（外部の文字列を入れない）。
     * 利用者への表示は決まった文言だけ（AddressLookupFailure::message）
     */
    private function fail(AddressLookupFailure $failure, string $postalCode, string $detail): AddressLookupResult
    {
        Log::warning("[address-lookup] zipcloud の呼び出しに失敗しました: {$failure->value}（{$detail}）");

        return AddressLookupResult::failed($failure, $postalCode);
    }
}
