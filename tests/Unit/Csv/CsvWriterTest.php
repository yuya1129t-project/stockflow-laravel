<?php

use App\Support\Csv\CsvParser;
use App\Support\Csv\CsvWriter;

test('カンマ区切り・CRLF。カンマ・ダブルクォート・改行・前後の空白を含む項目は囲む', function () {
    $csv = CsvWriter::write([
        ['a', 'b,c', 'say "hi"', "line1\nline2", ' pad', 12, null],
        ['x', '', 0, null, 'y', 'z', 'w'],
    ]);

    expect($csv)->toBe("a,\"b,c\",\"say \"\"hi\"\"\",\"line1\nline2\",\" pad\",12,\r\nx,,0,,y,z,w\r\n");
});

test('数式になる文字列は無害化し、整数はそのまま出力する', function () {
    expect(CsvWriter::write([['=1+1', -5, '@x,y']]))->toBe("'=1+1,-5,\"'@x,y\"\r\n");
});

test('BOM は UTF-8 の EF BB BF', function () {
    expect(bin2hex(CsvWriter::BOM))->toBe('efbbbf');
});

test('出力を読み込むと元の値に戻る', function () {
    $rows = [
        ['code', 'name'],
        ['P-1', "カンマ,と\"引用符\"と\n改行"],
    ];

    $records = CsvParser::parse(CsvWriter::BOM.CsvWriter::write($rows));

    expect(array_map(fn ($r) => $r->cells, $records))->toBe($rows);
});
