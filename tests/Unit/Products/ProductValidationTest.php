<?php

use App\Products\ProductValidation;
use App\Rules\SingleLine;

test('数値の正規化: 全角数字・全角カンマ・3 桁区切り・前後の空白', function (string $input, string $expected) {
    expect(ProductValidation::normalize(['sales_price' => $input])['sales_price'])->toBe($expected);
})->with([
    ['1200', '1200'],
    ['１２００', '1200'],
    ['1,200', '1200'],
    ['１，２００', '1200'],
    ['12,345,678', '12345678'],
    ['  100　', '100'],
    // 3 桁区切りでないカンマは残す（検証でエラーになる）
    ['1,2000', '1,2000'],
    ['12,00', '12,00'],
    ['1.0', '1.0'],
]);

test('商品コードは前後の空白を除いて大文字に揃える', function () {
    expect(ProductValidation::normalize(['code' => ' p-0001 '])['code'])->toBe('P-0001');
});

test('整数で渡された値は文字列にする。商品の 7 項目以外は捨てる', function () {
    expect(ProductValidation::normalize(['sales_price' => 120, 'is_active' => false, 'id' => 9]))->toBe([
        'code' => null,
        'name' => null,
        'category' => null,
        'unit' => null,
        'sales_price' => '120',
        'purchase_price' => null,
        'reorder_point' => null,
    ]);
});

test('1 行の項目: 制御文字・行区切り・表示方向の制御文字・BOM を拒否する', function (string $char) {
    expect(SingleLine::passes("ペン{$char}黒"))->toBeFalse();
})->with([
    'NUL' => "\u{0000}",
    'タブ' => "\t",
    'LF' => "\n",
    'CR' => "\r",
    'DEL' => "\u{007F}",
    'C1' => "\u{0085}",
    'LRM' => "\u{200E}",
    'RLM' => "\u{200F}",
    'LINE SEPARATOR' => "\u{2028}",
    'PARAGRAPH SEPARATOR' => "\u{2029}",
    'RLO' => "\u{202E}",
    'LRI' => "\u{2066}",
    'PDI' => "\u{2069}",
    'BOM' => "\u{FEFF}",
]);

test('1 行の項目: 日本語・記号・絵文字・全角空白は通す', function (string $value) {
    expect(SingleLine::passes($value))->toBeTrue();
})->with(['コピー用紙 A4', '（特価）＠＃', '🖊️ペン', 'ペン　黒', '']);

test('1 行の項目: UTF-8 として不正な文字列は拒否する', function () {
    expect(SingleLine::passes("\x8f\xa4\x95\x69"))->toBeFalse();
});

test('strip は拒否する文字だけを取り除く', function () {
    expect(SingleLine::strip("a\u{202E}b\tc.csv"))->toBe('abc.csv');
});
