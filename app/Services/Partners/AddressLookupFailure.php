<?php

namespace App\Services\Partners;

/** 住所検索の失敗の理由（docs/spec.md §6）。どの失敗でも画面では「住所を手入力してください」と出し、手入力を続けられる */
enum AddressLookupFailure: string
{
    /** 郵便番号の形式が違う（外部 API は呼ばない） */
    case InvalidPostalCode = 'invalid_postal_code';
    /** 該当する住所がない */
    case NotFound = 'not_found';
    /** 3 秒以内に応答がない */
    case Timeout = 'timeout';
    /** 通信エラー・HTTP エラー（リダイレクトを含む）・zipcloud のエラー応答 */
    case Unavailable = 'unavailable';
    /** 応答の形が想定と違う */
    case InvalidResponse = 'invalid_response';

    /** 画面に出すメッセージ */
    public function message(): string
    {
        return match ($this) {
            self::InvalidPostalCode => '郵便番号は7桁の数字で入力してください（例 123-4567）。住所を手入力してください',
            self::NotFound => '該当する住所が見つかりませんでした。住所を手入力してください',
            self::Timeout => '住所の検索がタイムアウトしました。住所を手入力してください',
            self::Unavailable, self::InvalidResponse => '住所の検索に失敗しました。住所を手入力してください',
        };
    }

    /** 住所検索の API（GET /partners/address-lookup）の HTTP ステータス */
    public function httpStatus(): int
    {
        return match ($this) {
            self::InvalidPostalCode => 400,
            self::NotFound => 404,
            self::Timeout => 504,
            self::Unavailable, self::InvalidResponse => 502,
        };
    }
}
