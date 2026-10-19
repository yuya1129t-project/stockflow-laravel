<?php

namespace App\Api\V1;

use App\Orders\OrderValidation;
use Illuminate\Http\Request;

/**
 * REST API（/api/v1）のクエリ・パスのパラメーターの読み取り。Next.js 版 src/lib/api/v1/request.ts の QueryReader と同じ規則。
 *
 * - 画面の一覧（不正な値は既定値に置き換える）と違い、API では不正な値を 400 にする（呼び出し側の誤りに気づけるように）。
 *   エラーは parameter（パラメーター名）ごとにためて、throwIfInvalid でまとめて 400 にする
 * - 同じパラメーターを 2 回以上指定したときも 400。そのために PHP の解釈（後の値で上書き）ではなく、生のクエリ文字列を読む。
 *   知らないパラメーターは無視する
 * - NUL（U+0000）・UTF-8 として正しくない値は 400（DB に渡さない）
 * - 日付は YYYY-MM-DD の実在する日付で、2000〜2099 年（docs/openapi.json の pattern と画面の受付範囲 OrderValidation と同じ）
 *
 * @phpstan-import-type FieldError from ApiResponse
 */
final class ApiQuery
{
    /** 1 ページの件数（spec §6-2: 既定 20・上限 100） */
    public const int PER_PAGE_DEFAULT = 20;

    public const int PER_PAGE_MAX = 100;

    /** ページ番号の上限（極端な OFFSET を避ける） */
    public const int PAGE_MAX = 1_000_000;

    /** 検索語（q）の最大長（画面の検索と同じ） */
    public const int QUERY_MAX_LENGTH = 100;

    public const string NUL_MESSAGE = 'NUL 文字（U+0000）は使えません';

    public const string UTF8_MESSAGE = 'UTF-8 として正しくない文字が含まれています';

    public const string DATE_RANGE_TEXT = '2000〜2099 年';

    /** @var list<FieldError> */
    public array $errors = [];

    /** @param  array<string, list<string>>  $params  パラメーター名 → 値（指定した順） */
    private function __construct(private readonly array $params) {}

    public static function fromRequest(Request $request): self
    {
        $raw = $request->server->get('QUERY_STRING');

        return new self(self::parse(is_string($raw) ? $raw : ''));
    }

    /**
     * クエリ文字列を名前ごとの値の一覧にする（application/x-www-form-urlencoded。+ は空白）
     *
     * @return array<string, list<string>>
     */
    public static function parse(string $queryString): array
    {
        $params = [];
        foreach (explode('&', $queryString) as $pair) {
            if ($pair === '') {
                continue;
            }
            [$name, $value] = array_pad(explode('=', $pair, 2), 2, '');
            $params[urldecode($name)][] = urldecode($value);
        }

        return $params;
    }

    /**
     * パスのパラメーター（NUL・不正な UTF-8 なら 400 で打ち切る）。前後の空白を除いて返す
     */
    public static function pathParameter(string $name, string $value): string
    {
        $detail = self::invalidText($value);
        if ($detail !== null) {
            ApiResponse::abort(ApiResponse::validation([['parameter' => $name, 'detail' => $detail]]));
        }

        return mb_trim($value);
    }

    /** 文字列（前後の空白を除く。省略なら ""） */
    public function string(string $name, int $maxLength): string
    {
        $value = mb_trim($this->single($name) ?? '');
        if (mb_strlen($value) > $maxLength) {
            $this->errors[] = ['parameter' => $name, 'detail' => "{$maxLength} 文字以内で指定してください"];

            return '';
        }

        return $value;
    }

    /** min 以上 max 以下の整数（省略時は fallback） */
    public function integer(string $name, int $min, int $max, int $fallback): int
    {
        $value = $this->single($name);
        if ($value === null) {
            return $fallback;
        }
        if (preg_match('/\A[0-9]{1,9}\z/', $value) !== 1 || (int) $value < $min || (int) $value > $max) {
            $this->errors[] = ['parameter' => $name, 'detail' => "{$min}〜".number_format($max).' の整数で指定してください'];

            return $fallback;
        }

        return (int) $value;
    }

    /**
     * 選択肢のどれか（省略・空なら ""）
     *
     * @param  list<string>  $options
     */
    public function oneOf(string $name, array $options): string
    {
        $value = $this->single($name);
        if ($value === null || $value === '') {
            return '';
        }
        if (! in_array($value, $options, true)) {
            $this->errors[] = ['parameter' => $name, 'detail' => implode('・', $options).' のどれかで指定してください'];

            return '';
        }

        return $value;
    }

    /** 日付（YYYY-MM-DD の実在する日付で 2000〜2099 年。省略・空なら ""） */
    public function date(string $name): string
    {
        $value = $this->single($name);
        if ($value === null || $value === '') {
            return '';
        }
        if (! self::isDate($value)) {
            $this->errors[] = ['parameter' => $name, 'detail' => self::dateMessage()];

            return '';
        }

        return $value;
    }

    /**
     * page・perPage（spec §6-2: perPage は既定 20・上限 100）
     *
     * @return array{page: int, perPage: int}
     */
    public function paging(): array
    {
        return [
            'page' => $this->integer('page', 1, self::PAGE_MAX, 1),
            'perPage' => $this->integer('perPage', 1, self::PER_PAGE_MAX, self::PER_PAGE_DEFAULT),
        ];
    }

    /** エラーがあれば 400 で打ち切る */
    public function throwIfInvalid(): void
    {
        if ($this->errors !== []) {
            ApiResponse::abort(ApiResponse::validation($this->errors));
        }
    }

    /** YYYY-MM-DD の実在する日付で、受け付ける範囲（2000〜2099 年）の中か */
    public static function isDate(string $value): bool
    {
        if (preg_match('/\A(20[0-9]{2})-([0-9]{2})-([0-9]{2})\z/', $value, $m) !== 1 || ! checkdate((int) $m[2], (int) $m[3], (int) $m[1])) {
            return false;
        }

        return $value >= OrderValidation::MIN_DATE && $value <= OrderValidation::MAX_DATE;
    }

    public static function dateMessage(): string
    {
        return 'YYYY-MM-DD の形式の実在する日付（'.self::DATE_RANGE_TEXT.'）で指定してください';
    }

    /** NUL を含む・UTF-8 として正しくない文字列ならそのエラー文 */
    public static function invalidText(string $value): ?string
    {
        if (str_contains($value, "\0")) {
            return self::NUL_MESSAGE;
        }

        return mb_check_encoding($value, 'UTF-8') ? null : self::UTF8_MESSAGE;
    }

    /** 1 回だけ指定された値（省略は null。重複・NUL・不正な UTF-8 はエラーにためて null） */
    private function single(string $name): ?string
    {
        $values = $this->params[$name] ?? [];
        if (count($values) > 1) {
            $this->errors[] = ['parameter' => $name, 'detail' => '1 回だけ指定してください'];

            return null;
        }
        if ($values === []) {
            return null;
        }
        $detail = self::invalidText($values[0]);
        if ($detail !== null) {
            $this->errors[] = ['parameter' => $name, 'detail' => $detail];

            return null;
        }

        return $values[0];
    }
}
