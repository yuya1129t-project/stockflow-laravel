<?php

use App\Support\Csv\CsvFormula;

/** CSV インジェクション対策（数式として解釈されうるセルの無害化。出力と取込で対称） */
test('数式になる文字で始まる値は先頭に \' を付ける', function (string $value) {
    expect(CsvFormula::sanitize($value))->toBe("'".$value);
})->with(['=SUM(A1:A2)', '+81', '-1+1', '@cmd', "\tx", "\rx"]);

test('危険な文字で始まらない値はそのまま', function (string $value) {
    expect(CsvFormula::sanitize($value))->toBe($value);
})->with(['P-0001', 'コピー用紙 A4', '', '1=1', 'a-b', "'abc"]);

test('\' が並んだ後に危険な文字が続く値にも \' を 1 つ付ける', function () {
    expect(CsvFormula::sanitize("'-特価品"))->toBe("''-特価品")
        ->and(CsvFormula::sanitize("''=x"))->toBe("'''=x");
});

test('出力 → 取込（sanitize → unsanitize）で元の値に戻る', function (string $value) {
    expect(CsvFormula::unsanitize(CsvFormula::sanitize($value)))->toBe($value);
})->with(['=1+2', '-x', "'-特価品", "'=分類", "''@x", "'abc", 'abc', "'", '']);

test('unsanitize は \' の後が危険な文字のときだけ 1 つ外す', function () {
    expect(CsvFormula::unsanitize("'=1"))->toBe('=1')
        ->and(CsvFormula::unsanitize("''-x"))->toBe("'-x")
        ->and(CsvFormula::unsanitize("'abc"))->toBe("'abc")
        ->and(CsvFormula::unsanitize('abc'))->toBe('abc');
});
