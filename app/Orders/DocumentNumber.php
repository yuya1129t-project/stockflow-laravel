<?php

namespace App\Orders;

use App\Enums\DocumentKind;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use LogicException;

/**
 * 伝票番号の採番（受注 SO-YYYYMMDD-NNN／発注 PO-YYYYMMDD-NNN）。docs/spec.md §3
 *
 * - 番号の日付は伝票の日付（受注日・発注日。作成した日の Asia/Tokyo の日付）。NNN はその日の 001 からの連番
 * - 連番は document_sequences（kind＋date ごとに最後の番号）を `INSERT … ON DUPLICATE KEY UPDATE` の 1 文で進める。
 *   行がない日は INSERT、ある日は UPDATE になり、どちらもその行の排他ロックを取る。同じ日の 2 本目以降は行ロックで
 *   順番待ちになり、先のトランザクションの確定後の値に 1 を足すので、同時に作成しても番号は重複しない
 *   （READ COMMITTED で「行がなければ SELECT … FOR UPDATE → INSERT」にすると、ない行はロックできず、
 *   2 本とも INSERT して重複キー（1062）になる。PR #21 のレビュー）
 * - 伝票の INSERT と同じトランザクションで呼ぶ（作成が失敗すれば番号も戻る。欠番を作らない）。トランザクションの外では例外
 * - 採番の行のロックは、取引先・商品の行をロックした後に取る（商品のロックを待つ間、採番の行をつかんだままにしない）
 * - 1 日に 999 件を超えたら桁を増やす（PO-20261019-1000）。エラーにはしない（業務を止めない）
 */
final class DocumentNumber
{
    /** 伝票の日付（Asia/Tokyo の今日。YYYY-MM-DD） */
    public static function today(): string
    {
        return CarbonImmutable::now('Asia/Tokyo')->toDateString();
    }

    /** 伝票番号の書式（date は YYYY-MM-DD、sequence は 1 以上） */
    public static function format(DocumentKind $kind, string $date, int $sequence): string
    {
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) !== 1) {
            throw new InvalidArgumentException("日付は YYYY-MM-DD で指定してください: {$date}");
        }
        if ($sequence < 1) {
            throw new InvalidArgumentException("連番は 1 以上の整数で指定してください: {$sequence}");
        }

        return sprintf('%s-%s-%03d', $kind->value, str_replace('-', '', $date), $sequence);
    }

    /** 次の伝票番号を採番する（伝票を書くトランザクションの中で呼ぶ）。date は伝票の日付（YYYY-MM-DD） */
    public static function next(DocumentKind $kind, string $date): string
    {
        $connection = DB::connection();
        if ($connection->transactionLevel() === 0) {
            throw new LogicException('採番は伝票を書くトランザクションの中で呼んでください（作成が失敗したときに番号も戻すため）');
        }

        $connection->statement(
            'INSERT INTO document_sequences (kind, date, last_number) VALUES (?, ?, 1)
             ON DUPLICATE KEY UPDATE last_number = last_number + 1',
            [$kind->value, $date],
        );
        // 自分が排他ロックを持っている行なので、ほかのトランザクションに変えられることはない（自分の更新後の値を読む）
        $row = $connection->selectOne('SELECT last_number FROM document_sequences WHERE kind = ? AND date = ?', [$kind->value, $date]);
        $number = is_object($row) && isset($row->last_number) && is_numeric($row->last_number) ? (int) $row->last_number : 0;

        return self::format($kind, $date, $number);
    }
}
