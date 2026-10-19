<?php

use App\ReorderNotifications\NotifyFailureReason;
use App\ReorderNotifications\ReorderPointMessage;
use App\ReorderNotifications\ReorderPointTrigger;
use App\ReorderNotifications\WebhookUrl;
use App\ReorderNotifications\WebhookUrlProblem;
use App\Stock\StockChange;
use App\Stock\StockProduct;

/** 発注点割れの通知の本文（Slack の {text, blocks}）・リンクの基準・Webhook URL の検証・失敗の要約（docs/spec.md §6-3） */
function reorderChange(int $id, string $code = 'P-0001', string $name = '商品A', string $unit = '個', int $reorderPoint = 5, int $before = 8, int $after = 3): StockChange
{
    return new StockChange(new StockProduct($id, $code, $name, $unit, $reorderPoint, true, $before), $before, $after);
}

/** @return list<array{type: string, text: string, verbatim: bool}> 本文のすべての mrkdwn */
function reorderMrkdwns(array $message): array
{
    $all = [];
    foreach ($message['blocks'] as $block) {
        foreach ($block['type'] === 'context' ? $block['elements'] : [$block['text']] as $text) {
            $all[] = $text;
        }
    }

    return $all;
}

test('本文: 見出し（件数ときっかけ）と商品ごとの行（在庫画面へのリンク・現在庫・発注点・変更前）。text は見出しだけ', function () {
    $message = ReorderPointMessage::build(
        [reorderChange(7, 'P-0007', '封筒 長形3号', '箱', 10, 12, 1_234), reorderChange(9, 'P-0009', 'ボールペン', '本', 1_000, 1_000, 999)],
        ReorderPointTrigger::salesOrderShipped(3, 'SO-20261019-001'),
        'https://stockflow.example.test',
    );

    expect($message)->toBe([
        'text' => '発注点を下回った商品が 2 件あります（受注 SO-20261019-001 の出荷）',
        'blocks' => [
            ['type' => 'section', 'text' => ['type' => 'mrkdwn', 'text' => '*発注点を下回った商品が 2 件あります（受注 SO-20261019-001 の出荷）*', 'verbatim' => true]],
            ['type' => 'section', 'text' => ['type' => 'mrkdwn', 'text' => "<https://stockflow.example.test/stock/7|P-0007 封筒 長形3号>\n現在庫 1,234箱（発注点 10箱・変更前 12箱）", 'verbatim' => true]],
            ['type' => 'section', 'text' => ['type' => 'mrkdwn', 'text' => "<https://stockflow.example.test/stock/9|P-0009 ボールペン>\n現在庫 999本（発注点 1,000本・変更前 1,000本）", 'verbatim' => true]],
        ],
    ]);
});

test('本文: 棚卸調整のきっかけ。リンクの基準がなければリンクを付けない', function () {
    $message = ReorderPointMessage::build([reorderChange(1)], ReorderPointTrigger::stockAdjust(1), null);

    expect($message['text'])->toBe('発注点を下回った商品が 1 件あります（棚卸調整）')
        ->and($message['blocks'][1]['text']['text'])->toBe("P-0001 商品A\n現在庫 3個（発注点 5個・変更前 8個）");
});

test('利用者の入力（商品名・単位）を宛先指定・リンクとして解釈させない（& < > のエスケープ＋verbatim。text には入れない）', function (?string $baseUrl) {
    $evil = '@here @channel <!here> <!channel> <@U123> <https://example.test|text> &lt;';
    $message = ReorderPointMessage::build([reorderChange(5, 'P-0005', $evil, '<!here>')], ReorderPointTrigger::stockAdjust(5), $baseUrl);

    $escaped = '@here @channel &lt;!here&gt; &lt;!channel&gt; &lt;@U123&gt; &lt;https://example.test|text&gt; &amp;lt;';
    $label = "P-0005 {$escaped}";
    expect($message['blocks'][1]['text'])->toBe([
        'type' => 'mrkdwn',
        'text' => ($baseUrl !== null ? "<{$baseUrl}/stock/5|{$label}>" : $label)."\n現在庫 3&lt;!here&gt;（発注点 5&lt;!here&gt;・変更前 8&lt;!here&gt;）",
        'verbatim' => true,
    ]);
    $mrkdwns = reorderMrkdwns($message);
    expect(array_column($mrkdwns, 'verbatim'))->each->toBeTrue();
    $all = implode("\n", array_column($mrkdwns, 'text'));
    foreach (['<!here>', '<!channel>', '<@U123>', '<https://example.test'] as $raw) {
        expect($all)->not->toContain($raw);
    }
    // < は組み立てた在庫へのリンクの 1 個だけ（リンクなしでは 0 個）
    expect(substr_count($all, '<'))->toBe($baseUrl !== null ? 1 : 0)
        ->and($message['text'])->toBe('発注点を下回った商品が 1 件あります（棚卸調整）')
        ->and($message['text'])->not->toContain('here');
})->with(['リンクあり' => ['https://stockflow.example.test'], 'リンクなし' => [null]]);

test('商品名の * _ ~ で書式を作らない（商品の行を太字で囲まない）', function () {
    $message = ReorderPointMessage::build([reorderChange(1, 'P-0001', '*強調* _斜体_ ~取消~')], ReorderPointTrigger::stockAdjust(1), null);

    expect($message['blocks'][1]['text']['text'])->toStartWith('P-0001 *強調* _斜体_ ~取消~')
        ->and($message['blocks'][1]['text']['text'])->not->toStartWith('*P-0001');
});

test('商品ごとの行は 20 件まで。超えた分は「ほか N 件」（発注点割れの一覧へのリンク）。blocks は Slack の上限 50 個に収まる', function () {
    $changes = array_map(fn (int $i) => reorderChange($i, sprintf('P-%04d', $i)), range(1, 25));

    $message = ReorderPointMessage::build($changes, ReorderPointTrigger::stockAdjust(1), 'https://stockflow.example.test');

    expect($message['blocks'])->toHaveCount(22)
        ->and($message['text'])->toBe('発注点を下回った商品が 25 件あります（棚卸調整）')
        ->and($message['blocks'][20]['text']['text'])->toStartWith('<https://stockflow.example.test/stock/20|P-0020 ')
        ->and($message['blocks'][21])->toBe(['type' => 'context', 'elements' => [[
            'type' => 'mrkdwn',
            'text' => 'ほか 5 件（<https://stockflow.example.test/stock?level=low|在庫一覧の発注点割れ>で確認してください）',
            'verbatim' => true,
        ]]]);
    $withoutLink = ReorderPointMessage::build($changes, ReorderPointTrigger::stockAdjust(1), null);
    expect($withoutLink['blocks'][21]['elements'][0]['text'])->toBe('ほか 5 件');
    // 入力の上限（コード 20・名前 100・単位 10 文字）をすべてエスケープで 5 倍にしても、section の上限 3,000 文字に収まる
    $longest = ReorderPointMessage::build([reorderChange(1, str_repeat('A', 20), str_repeat('<', 100), str_repeat('&', 10))], ReorderPointTrigger::stockAdjust(1), 'https://stockflow.example.test');
    expect(mb_strlen($longest['blocks'][1]['text']['text']))->toBeLessThan(3000);
});

test('通知する商品がなければ組み立てない（プログラムの誤り）', function () {
    ReorderPointMessage::build([], ReorderPointTrigger::stockAdjust(1), null);
})->throws(LogicException::class);

test('リンクの基準（APP_URL）: http／https だけ。末尾の / を除く。認証情報・< > |・空白を含むものは使わない', function (mixed $appUrl, ?string $expected) {
    expect(ReorderPointMessage::baseUrl($appUrl))->toBe($expected);
})->with([
    ['https://stockflow.example.test', 'https://stockflow.example.test'],
    ['http://localhost:8089/', 'http://localhost:8089'],
    ['  https://example.test/app//  ', 'https://example.test/app'],
    ['HTTPS://example.test', 'https://example.test'],
    [null, null],
    ['', null],
    ['javascript:alert(1)', null],
    ['ftp://example.test', null],
    ['https://user:pass@example.test', null],
    ['https://example.test/a|b', null],
    ['https://example.test/<x>', null],
    ['https://exa mple.test', null],
    ['example.test', null],
]);

test('Webhook URL: 空・空白だけは未設定。https 以外・認証情報入り・不正な URL は使わない', function (mixed $value, string|WebhookUrlProblem $expected) {
    expect(WebhookUrl::parse($value))->toBe($expected)
        ->and(WebhookUrl::isUnset($value))->toBe($expected === WebhookUrlProblem::Unset);
})->with([
    'https' => ['https://hooks.slack.com/services/T000/B000/XXXX', 'https://hooks.slack.com/services/T000/B000/XXXX'],
    '前後の空白は除く' => ["  https://hooks.slack.com/services/T000/B000/XXXX\n", 'https://hooks.slack.com/services/T000/B000/XXXX'],
    '大文字の HTTPS' => ['HTTPS://hooks.slack.com/x', 'HTTPS://hooks.slack.com/x'],
    'null' => [null, WebhookUrlProblem::Unset],
    '空' => ['', WebhookUrlProblem::Unset],
    '空白だけ' => ['   ', WebhookUrlProblem::Unset],
    'http' => ['http://hooks.slack.com/services/T000/B000/XXXX', WebhookUrlProblem::NotHttps],
    'ftp' => ['ftp://hooks.slack.com/x', WebhookUrlProblem::NotHttps],
    'ユーザー名入り' => ['https://user@hooks.slack.com/x', WebhookUrlProblem::Invalid],
    'パスワード入り' => ['https://user:secret@hooks.slack.com/x', WebhookUrlProblem::Invalid],
    'URL でない' => ['hooks.slack.com/services/x', WebhookUrlProblem::Invalid],
    '途中に空白' => ['https://hooks.slack.com/a b', WebhookUrlProblem::Invalid],
    'ホストなし' => ['https:///x', WebhookUrlProblem::Invalid],
    '文字列でない' => [123, WebhookUrlProblem::Unset],
]);

test('失敗の要約: きっかけ・商品コード（10 件まで）・原因の種類だけ', function () {
    $changes = array_map(fn (int $i) => reorderChange($i, sprintf('P-%04d', $i)), range(1, 12));

    expect(ReorderPointMessage::failureSummary($changes, ReorderPointTrigger::salesOrderShipped(3, 'SO-20261019-001'), NotifyFailureReason::HttpError, 500))
        ->toBe('発注点割れの通知を送信できませんでした（受注 SO-20261019-001 の出荷・商品 P-0001、P-0002、P-0003、P-0004、P-0005、P-0006、P-0007、P-0008、P-0009、P-0010 ほか 2 件）。原因: HTTP エラー（500）')
        ->and(ReorderPointMessage::failureSummary([reorderChange(1)], ReorderPointTrigger::stockAdjust(1), NotifyFailureReason::Timeout))
        ->toBe('発注点割れの通知を送信できませんでした（棚卸調整・商品 P-0001）。原因: タイムアウト（3 秒）');
});
