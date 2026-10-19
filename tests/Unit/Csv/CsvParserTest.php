<?php

use App\Support\Csv\CsvParseException;
use App\Support\Csv\CsvParser;
use App\Support\Csv\CsvRecord;

/** @return list<array{line: int, cells: list<string>}> */
function parsed(string $input): array
{
    return array_map(fn (CsvRecord $r) => ['line' => $r->line, 'cells' => $r->cells], CsvParser::parse($input));
}

test('基本の形（末尾の改行あり・なし、CRLF・LF・CR）', function (string $input) {
    expect(parsed($input))->toBe([
        ['line' => 1, 'cells' => ['a', 'b']],
        ['line' => 2, 'cells' => ['1', '2']],
    ]);
})->with([
    'CRLF・末尾あり' => "a,b\r\n1,2\r\n",
    'LF・末尾なし' => "a,b\n1,2",
    'CR だけ' => "a,b\r1,2\r",
    '混在' => "a,b\r\n1,2\n",
]);

test('先頭の BOM を取り除く', function () {
    expect(parsed("\u{FEFF}商品コード,商品名\n")[0]['cells'])->toBe(['商品コード', '商品名']);
});

test('囲みの中のカンマ・""・改行を扱い、レコードの開始行を返す', function () {
    expect(parsed("h1,h2\n\"a,b\",\"x\r\ny \"\"z\"\"\"\nlast,\"\"\n"))->toBe([
        ['line' => 1, 'cells' => ['h1', 'h2']],
        ['line' => 2, 'cells' => ['a,b', "x\r\ny \"z\""]],
        ['line' => 4, 'cells' => ['last', '']],
    ]);
});

test('囲みの中の CR だけの改行も行として数える', function () {
    expect(parsed("h\n\"a\rb\"\nc\n"))->toBe([
        ['line' => 1, 'cells' => ['h']],
        ['line' => 2, 'cells' => ["a\rb"]],
        ['line' => 4, 'cells' => ['c']],
    ]);
});

test('空の項目・空行も 1 レコードとして返す（空行の扱いは呼び出し側で決める）', function () {
    expect(parsed("a,,c\n\nd"))->toBe([
        ['line' => 1, 'cells' => ['a', '', 'c']],
        ['line' => 2, 'cells' => ['']],
        ['line' => 3, 'cells' => ['d']],
    ]);
});

test('RFC 4180 に沿わない引用はエラー（行番号付き）', function (string $input, int $line, string $message) {
    try {
        CsvParser::parse($input);
        $this->fail('エラーになりませんでした');
    } catch (CsvParseException $e) {
        expect($e->csvLine)->toBe($line)
            ->and($e->getMessage())->toContain($message);
    }
})->with([
    '閉じた " の直後に文字' => ['"abc"def,x', 1, '直後に文字があります'],
    '閉じた " の直後に文字（2 行目）' => ["h\n\"a\")", 2, '直後に文字があります'],
    '囲んでいない項目の途中の "' => ['a,b"c,d', 1, '項目全体を'],
    '区切りの後の空白と "' => ['a, "b,c",d', 1, '項目全体を'],
    '閉じていない "' => ["a,b\n1,\"open\n2,3\n", 2, '閉じられていません'],
]);

test('閉じ忘れの " が後ろの行の " で閉じられても、行を飲み込まずにエラーにする（閉じ忘れの行を示す）', function () {
    try {
        CsvParser::parse("h1,h2,h3\nZR-2,\"ペン,文具\nZR-3,ノート,文具\nZR-4,\"消しゴム\",文具\n");
        $this->fail('エラーになりませんでした');
    } catch (CsvParseException $e) {
        expect($e->csvLine)->toBe(4)
            ->and($e->getMessage())->toContain('2 行目の " が閉じられていない');
    }
});

test('多バイト文字（日本語・絵文字）をそのまま読む', function () {
    expect(parsed("名前,備考\n\"ペン,黒\",🖊️\n"))->toBe([
        ['line' => 1, 'cells' => ['名前', '備考']],
        ['line' => 2, 'cells' => ['ペン,黒', '🖊️']],
    ]);
});

test('空の文字列はレコードなし', function () {
    expect(CsvParser::parse(''))->toBe([]);
});
