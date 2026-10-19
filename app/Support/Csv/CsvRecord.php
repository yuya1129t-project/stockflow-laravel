<?php

namespace App\Support\Csv;

/** CSV の 1 レコード */
final readonly class CsvRecord
{
    /**
     * @param  int  $line  このレコードが始まる行番号（1 始まり。項目の中に改行があっても開始行を指す）
     * @param  list<string>  $cells
     */
    public function __construct(
        public int $line,
        public array $cells,
    ) {}

    /** すべてのセルが空（空白だけを含む）か */
    public function isBlank(): bool
    {
        foreach ($this->cells as $cell) {
            if (trim($cell) !== '') {
                return false;
            }
        }

        return true;
    }
}
