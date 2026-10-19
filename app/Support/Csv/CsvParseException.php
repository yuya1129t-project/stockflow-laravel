<?php

namespace App\Support\Csv;

use RuntimeException;

/** CSV の書式の誤り（行番号付き） */
final class CsvParseException extends RuntimeException
{
    public function __construct(
        public readonly int $csvLine,
        string $message,
    ) {
        parent::__construct($message);
    }
}
