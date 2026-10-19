<?php

namespace App\Support\Csv;

/**
 * CSV の読み込み（RFC 4180。docs/spec.md §7）。DB やフレームワークに依存しない。
 *
 * - 区切りはカンマ。改行は CRLF・LF・CR のどれでもよい（混在も可）。末尾の改行の有無は問わない。先頭の BOM は取り除く
 * - ダブルクォートで囲んだ項目（カンマ・改行・"" を含む）に対応する。各レコードの開始行番号を返す
 * - RFC 4180 に沿わない引用は行番号付きのエラー（CsvParseException）にする。列がずれたり、後続の行が
 *   1 つの項目に飲み込まれたりしたまま取り込まないため
 *     - 閉じた " の直後に区切り・改行・終端以外の文字がある（"abc"def）
 *     - 囲んでいない項目の途中に " がある（a,b"c や、区切りの後に空白がある a, "b"）
 *     - 閉じていない "
 * - 区切り・改行・引用符はすべて ASCII なので、UTF-8 の文字列をバイト単位で走査してよい
 *   （UTF-8 の多バイト文字のバイトは ASCII の範囲と重ならない）
 */
final class CsvParser
{
    /**
     * @return list<CsvRecord>
     *
     * @throws CsvParseException
     */
    public static function parse(string $input): array
    {
        $text = str_starts_with($input, CsvWriter::BOM) ? substr($input, strlen(CsvWriter::BOM)) : $input;
        $length = strlen($text);

        $records = [];
        $cells = [];
        $cell = '';
        $line = 1;
        $recordLine = 1;
        $quoteStartLine = 1;
        $inQuotes = false;
        // 現在のレコードに何か（文字・区切り）が出てきたか
        $started = false;

        for ($i = 0; $i < $length; $i++) {
            $c = $text[$i];
            $next = $i + 1 < $length ? $text[$i + 1] : null;

            if ($inQuotes) {
                if ($c === '"') {
                    if ($next === '"') {
                        $cell .= '"';
                        $i++;

                        continue;
                    }
                    $inQuotes = false;
                    // 閉じた " の直後は区切り・改行・終端のどれか（"abc"def のような入力は受け付けない）
                    if ($next !== null && $next !== ',' && $next !== "\r" && $next !== "\n") {
                        throw new CsvParseException($line, $quoteStartLine === $line
                            ? 'ダブルクォート（"）で囲んだ項目の直後に文字があります。項目全体を " で囲み、中の " は "" と書いてください'
                            : "ダブルクォート（\"）で囲んだ項目の直後に文字があります。{$quoteStartLine} 行目の \" が閉じられていない可能性があります");
                    }
                } else {
                    if ($c === "\n" || ($c === "\r" && $next !== "\n")) {
                        $line++;
                    }
                    $cell .= $c;
                }

                continue;
            }

            if (! $started) {
                $recordLine = $line;
                $started = true;
            }

            if ($c === '"') {
                // 引用は項目の先頭からだけ（a,b"c や a, "b" のような入力は受け付けない）
                if ($cell !== '') {
                    throw new CsvParseException($line, 'ダブルクォート（"）を含む値は、項目全体を " で囲み、中の " は "" と書いてください（区切りのカンマの後に空白を入れないでください）');
                }
                $inQuotes = true;
                $quoteStartLine = $line;
            } elseif ($c === ',') {
                $cells[] = $cell;
                $cell = '';
            } elseif ($c === "\r" || $c === "\n") {
                if ($c === "\r" && $next === "\n") {
                    $i++;
                }
                $cells[] = $cell;
                $records[] = new CsvRecord($recordLine, $cells);
                $cells = [];
                $cell = '';
                $started = false;
                $line++;
            } else {
                $cell .= $c;
            }
        }

        if ($inQuotes) {
            throw new CsvParseException($quoteStartLine, 'ダブルクォート（"）で始まる項目が閉じられていません');
        }
        if ($started) {
            $cells[] = $cell;
            $records[] = new CsvRecord($recordLine, $cells);
        }

        return $records;
    }
}
