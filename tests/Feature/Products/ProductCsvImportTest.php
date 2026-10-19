<?php

use App\Enums\StockMovementType;
use App\Models\AuditLog;
use App\Models\Product;
use App\Models\StockMovement;
use App\Models\User;
use App\Products\ProductImportParser;
use Illuminate\Database\QueryException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Inertia\Support\SessionKey;

/** 商品の CSV 取込（docs/spec.md §7）。1 行でもエラーなら全体中止・行番号付きのエラー・1,000 行上限 */
const IMPORT_HEADER = "商品コード,商品名,カテゴリ,単位,売価,仕入単価,発注点\r\n";

function importCsv(string $content, string $fileName = 'products.csv', ?User $user = null): TestResponse
{
    $user ??= User::factory()->staff()->create();

    return test()->actingAs($user)->post(route('products.import.store'), [
        'file' => UploadedFile::fake()->createWithContent($fileName, $content),
    ]);
}

/** @return array<string, mixed> */
function importResult(TestResponse $response): array
{
    $response->assertRedirect(route('products.import'));
    $flash = $response->baseResponse->getSession()?->get(SessionKey::FLASH_DATA, []) ?? [];

    return $flash['importResult'];
}

/** @return list<int|null> */
function errorLines(TestResponse $response): array
{
    return array_column(importResult($response)['errors'], 'line');
}

test('code が既存なら更新、なければ新規。商品ごとの操作履歴と要約を残す', function () {
    $staff = User::factory()->staff()->create();
    $existing = Product::factory()->create(['code' => 'P-0001', 'name' => 'ペン', 'category' => '文具', 'unit' => '本', 'sales_price' => 150, 'purchase_price' => 90, 'reorder_point' => 20]);
    Product::factory()->create(['code' => 'P-0002', 'name' => 'ノート', 'category' => '文具', 'unit' => '冊', 'sales_price' => 200, 'purchase_price' => 100, 'reorder_point' => 5]);

    $response = importCsv(
        "\u{FEFF}".IMPORT_HEADER
        ."p-0001,ペン,文具,本,\"1,200\",90,20\r\n" // 小文字のコード・桁区切り → P-0001 の更新
        ."P-0002,ノート,文具,冊,200,100,5\r\n" // 変更なし
        ."P-0003,消しゴム,文具,個,８０,40,10\r\n", // 新規（全角数字）
        'products.csv',
        $staff,
    );

    expect(importResult($response))->toBe([
        'status' => 'success',
        'fileName' => 'products.csv',
        'summary' => ['created' => 1, 'updated' => 1, 'unchanged' => 1],
    ]);
    expect($existing->fresh()?->sales_price)->toBe(1200);
    $created = Product::query()->where('code', 'P-0003')->sole();
    expect($created->only(['name', 'sales_price', 'is_active']))->toBe(['name' => '消しゴム', 'sales_price' => 80, 'is_active' => true]);

    $logs = AuditLog::query()->orderBy('id')->get();
    expect($logs->map->only(['user_id', 'action', 'target_type', 'target_id', 'summary'])->all())->toBe([
        ['user_id' => $staff->id, 'action' => 'create', 'target_type' => 'product', 'target_id' => (string) $created->id, 'summary' => '商品 P-0003「消しゴム」を登録しました（CSV 取込）'],
        ['user_id' => $staff->id, 'action' => 'update', 'target_type' => 'product', 'target_id' => (string) $existing->id, 'summary' => '商品 P-0001 を更新しました（CSV 取込: 売価: 150 → 1200）'],
        ['user_id' => $staff->id, 'action' => 'import', 'target_type' => 'product', 'target_id' => null, 'summary' => '商品を CSV で取り込みました（products.csv: 新規 1 件・更新 1 件・変更なし 1 件）'],
    ])
        ->and($logs->every(fn (AuditLog $log) => $log->created_at !== null))->toBeTrue();
});

test('先頭に BOM が 2 つ付いたファイルも取り込める', function () {
    expect(importResult(importCsv("\u{FEFF}\u{FEFF}".IMPORT_HEADER."P-0001,ペン,文具,本,100,50,5\r\n"))['summary'])
        ->toBe(['created' => 1, 'updated' => 0, 'unchanged' => 0]);
});

test('有効／無効は CSV では変わらない（「状態」列は読み飛ばす）。入出庫も変えない', function () {
    $inactive = Product::factory()->inactive()->create(['code' => 'P-0001', 'name' => '旧名']);
    $active = Product::factory()->create(['code' => 'P-0002', 'name' => '旧名2']);
    StockMovement::factory()->create(['product_id' => $active->id, 'type' => StockMovementType::In, 'quantity' => 30]);

    $response = importCsv(
        "商品コード,商品名,カテゴリ,単位,売価,仕入単価,発注点,現在庫,状態\r\n"
        ."P-0001,新しい名前,文具,本,100,50,5,999,有効\r\n"
        ."P-0002,新しい名前2,文具,本,100,50,5,0,無効\r\n"
        ."P-0003,新規,文具,本,100,50,5,0,無効\r\n",
    );

    expect(importResult($response)['status'])->toBe('success')
        ->and($inactive->fresh()?->only(['name', 'is_active']))->toBe(['name' => '新しい名前', 'is_active' => false])
        ->and($active->fresh()?->only(['name', 'is_active']))->toBe(['name' => '新しい名前2', 'is_active' => true])
        ->and(Product::query()->where('code', 'P-0003')->sole()->is_active)->toBeTrue()
        ->and(StockMovement::query()->count())->toBe(1)
        ->and((int) StockMovement::query()->sum('quantity'))->toBe(30);
});

test('出力した CSV をそのまま取り込むと「変更なし」（無害化の \' も元に戻る）', function () {
    Product::factory()->create(['code' => 'P-0001', 'name' => "'-特価品", 'category' => '=分類', 'unit' => '個']);
    Product::factory()->create(['code' => 'P-0002', 'name' => 'カンマ,と"引用符"', 'category' => '-1', 'unit' => '@箱']);
    $user = User::factory()->staff()->create();
    $exported = (string) $this->actingAs($user)->get(route('products.export'))->getContent();

    expect(importResult(importCsv($exported, 'products.csv', $user))['summary'])->toBe(['created' => 0, 'updated' => 0, 'unchanged' => 2])
        ->and(Product::query()->where('code', 'P-0001')->sole()->name)->toBe("'-特価品");
});

test('1 行でもエラーがあれば全体を取り込まない（行番号付きのエラー）', function () {
    Product::factory()->create(['code' => 'P-0001', 'sales_price' => 100]);

    $response = importCsv(IMPORT_HEADER
        ."P-0001,ペン,文具,本,999,50,5\r\n"
        ."\r\n" // 空行は読み飛ばす（行番号は数える）
        ."P-0002,,文具,本,-1,50,5\r\n"
        ."P-0003,ノート\u{202E},文具,冊,100,50,5\r\n");

    $result = importResult($response);
    expect($result['status'])->toBe('error')
        ->and($result['message'])->toBe('エラーがあるため取り込みませんでした（1 件も登録・更新していません）。修正してからもう一度取り込んでください')
        ->and($result['errors'])->toBe([
            ['line' => 4, 'field' => '商品名', 'message' => '商品名を入力してください'],
            ['line' => 4, 'field' => '売価', 'message' => '売価は0以上の整数で入力してください'],
            ['line' => 5, 'field' => '商品名', 'message' => '商品名 に改行・タブなどの制御文字は使えません'],
        ])
        ->and($result['totalErrors'])->toBe(3);
    expect(Product::query()->sole()->sales_price)->toBe(100)
        ->and(AuditLog::query()->count())->toBe(0);
});

test('列数が見出しと違う行はエラー（囲んでいない 1,200 で列がずれたまま取り込まない）', function () {
    $response = importCsv(
        "商品コード,商品名,カテゴリ,単位,売価,仕入単価,発注点,現在庫,状態\r\n"
        ."ZR-1,ペン,文具,個,1,200,800,3,12,有効\r\n"
        ."ZR-2,ノート,文具,冊\r\n",
    );

    expect(importResult($response)['errors'])->toBe([
        ['line' => 2, 'field' => null, 'message' => '列の数が見出し行と違います（見出し 9 列・この行 10 列）。カンマを含む値は "" で囲んでください'],
        ['line' => 3, 'field' => null, 'message' => '列の数が見出し行と違います（見出し 9 列・この行 4 列）。カンマを含む値は "" で囲んでください'],
    ])
        ->and(Product::query()->count())->toBe(0);
});

test('閉じ忘れの " で後続の行を飲み込まない（引用符の厳格な解析）', function () {
    $response = importCsv(IMPORT_HEADER
        ."ZR-2,\"ペン,文具,個,100,50,5\r\n"
        ."ZR-3,ノート,文具,冊,200,100,5\r\n"
        ."ZR-4,\"消しゴム\",文具,個,80,40,5\r\n");

    $errors = importResult($response)['errors'];
    expect($errors)->toHaveCount(1)
        ->and($errors[0]['line'])->toBe(4)
        ->and($errors[0]['message'])->toContain('2 行目の " が閉じられていない')
        ->and(Product::query()->count())->toBe(0);
});

test('囲んでいない項目の途中の " はエラー', function () {
    expect(errorLines(importCsv(IMPORT_HEADER."P-1,ペン\"黒,文具,本,1,1,1\r\n")))->toBe([2]);
});

test('ファイル内の商品コードの重複はエラー（大文字小文字・前後の空白の違いも同じコード）', function () {
    $response = importCsv(IMPORT_HEADER
        ."P-0001,ペン,文具,本,1,1,1\r\n"
        ." p-0001 ,ペン2,文具,本,1,1,1\r\n");

    expect(importResult($response)['errors'])->toBe([
        ['line' => 3, 'field' => '商品コード', 'message' => '商品コード「P-0001」が 2 行目と重複しています'],
    ]);
});

test('見出しの不足・重複はエラー（列の順番・知らない列は問わない）', function () {
    $missing = importResult(importCsv("商品名,商品コード,単位,売価,仕入単価,発注点,メモ\r\nペン,P-1,本,1,1,1,x\r\n"));
    expect($missing['errors'])->toBe([
        ['line' => 1, 'field' => 'カテゴリ', 'message' => '見出し行に「カテゴリ」の列がありません'],
    ]);

    $duplicated = importResult(importCsv("商品コード,商品名,カテゴリ,単位,売価,仕入単価,発注点,商品名\r\n"));
    expect($duplicated['errors'])->toBe([
        ['line' => 1, 'field' => '商品名', 'message' => '見出し「商品名」が重複しています'],
    ]);

    // 列の順番を入れ替えても取り込める
    expect(importResult(importCsv("発注点,単位,商品名,商品コード,カテゴリ,仕入単価,売価,メモ\r\n5,本,ペン,P-1,文具,50,100,x\r\n"))['status'])->toBe('success')
        ->and(Product::query()->sole()->only(['code', 'sales_price', 'reorder_point']))->toBe(['code' => 'P-1', 'sales_price' => 100, 'reorder_point' => 5]);
});

test('ファイル全体のエラー: 空・見出しだけ・UTF-8 以外', function (string $content, string $message) {
    expect(importResult(importCsv($content))['errors'])->toBe([
        ['line' => null, 'field' => null, 'message' => $message],
    ]);
})->with([
    '空のファイル' => ['', 'ファイルが空です'],
    '空行だけ' => ["\r\n,,,\r\n", 'ファイルが空です'],
    '見出しだけ' => [IMPORT_HEADER, '取り込むデータ行がありません（見出し行だけです）'],
    'Shift_JIS' => [mb_convert_encoding(IMPORT_HEADER."P-1,ペン,文具,本,1,1,1\r\n", 'SJIS-win', 'UTF-8'), '文字コードが UTF-8 ではありません。UTF-8 で保存し直してください（Excel では「CSV UTF-8（コンマ区切り）」）'],
]);

test('データ行は 1,000 行まで（1,001 行はエラー）', function () {
    $rows = fn (int $count) => IMPORT_HEADER.implode('', array_map(fn (int $i) => sprintf("T-%04d,商品%d,文具,個,100,50,5\r\n", $i, $i), range(1, $count)));

    expect(importResult(importCsv($rows(1001)))['errors'][0]['message'])
        ->toBe('データ行が上限（1,000 行）を超えています（1,001 行）。ファイルを分けて取り込んでください')
        ->and(Product::query()->count())->toBe(0);

    expect(importResult(importCsv($rows(1000)))['summary'])->toBe(['created' => 1000, 'updated' => 0, 'unchanged' => 0])
        ->and(Product::query()->count())->toBe(1000)
        ->and(AuditLog::query()->where('action', 'create')->count())->toBe(1000);
});

test('一括更新は 500 行をまたいでも全件を更新し、商品ごとの履歴を残す', function () {
    $now = now();
    DB::table('products')->insert(array_map(fn (int $i) => [
        'code' => sprintf('T-%04d', $i), 'name' => "商品{$i}", 'category' => '文具', 'unit' => '個',
        'sales_price' => 100, 'purchase_price' => 50, 'reorder_point' => 5, 'is_active' => $i % 2 === 0, 'created_at' => $now, 'updated_at' => $now,
    ], range(1, 501)));

    $csv = IMPORT_HEADER.implode('', array_map(fn (int $i) => sprintf("T-%04d,商品%d,文具,個,%d,50,5\r\n", $i, $i, 100 + $i), range(1, 501)));
    expect(importResult(importCsv($csv))['summary'])->toBe(['created' => 0, 'updated' => 501, 'unchanged' => 0]);

    expect(Product::query()->where('code', 'T-0501')->sole()->sales_price)->toBe(601)
        ->and(Product::query()->whereColumn('sales_price', '!=', DB::raw('100 + CAST(SUBSTRING(code, 3) AS UNSIGNED)'))->count())->toBe(0)
        ->and(Product::query()->where('is_active', true)->count())->toBe(250)
        ->and(AuditLog::query()->where('action', 'update')->count())->toBe(501);
});

test('エラーは先頭の 100 件だけ返し、件数は全体を返す', function () {
    $csv = IMPORT_HEADER.str_repeat("P-1,,文具,個,1,1,1\r\n", 150);

    $result = importResult(importCsv($csv));
    expect($result['errors'])->toHaveCount(100)
        ->and($result['totalErrors'])->toBe(150);
});

test('ファイルの上限（512KB）を超えるとサーバー側でも拒否する', function () {
    $response = importCsv(IMPORT_HEADER.str_repeat('x', ProductImportParser::MAX_BYTES));

    $response->assertSessionHasErrors(['file' => 'ファイルが大きすぎます（上限 512KB）。ファイルを分けて取り込んでください']);
    expect(Product::query()->count())->toBe(0);
});

test('ファイルの選択なしはエラー', function () {
    $this->actingAs(User::factory()->staff()->create())
        ->post(route('products.import.store'))
        ->assertSessionHasErrors(['file' => 'CSV ファイルを選択してください']);
});

test('操作履歴・画面に出すファイル名から制御文字を除く', function () {
    $result = importResult(importCsv(IMPORT_HEADER."P-1,ペン,文具,本,1,1,1\r\n", "a\u{202E}vsc.csv"));

    expect($result['fileName'])->toBe('avsc.csv')
        ->and(AuditLog::query()->where('action', 'import')->sole()->summary)->toContain('（avsc.csv:');
});

test('取込画面に上限を渡す', function () {
    $this->actingAs(User::factory()->staff()->create())
        ->get(route('products.import'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('products/Import')
            ->where('limits', ['maxRows' => 1000, 'maxBytes' => 524288]));
});

test('書き込みの途中で失敗したら、商品の登録・更新もすべて取り消す（1 トランザクション）', function () {
    $existing = Product::factory()->create(['code' => 'P-0001', 'sales_price' => 100]);
    // 操作履歴の書き込み（商品の書き込みの後）で失敗させる
    DB::listen(function ($query) {
        if (str_starts_with($query->sql, 'insert into `audit_logs`')) {
            throw new RuntimeException('テスト用の失敗');
        }
    });

    $this->withoutExceptionHandling();

    expect(fn () => importCsv(IMPORT_HEADER."P-0001,ペン,文具,本,999,50,5\r\nP-0002,ノート,文具,冊,100,50,5\r\n"))
        ->toThrow(RuntimeException::class, 'テスト用の失敗');

    expect($existing->fresh()?->sales_price)->toBe(100)
        ->and(Product::query()->count())->toBe(1);
});

test('デッドロックを再試行しきれなかったときは、500 にせず日本語の「もう一度取り込んでください」にする', function () {
    // テストはトランザクションの中で動く（入れ子のトランザクションでは Laravel は再試行せず、デッドロックをそのまま投げる）
    DB::listen(function ($query) {
        if (str_starts_with($query->sql, 'insert into `products`')) {
            throw new QueryException('mysql', $query->sql, [], new PDOException('SQLSTATE[40001]: Serialization failure: 1213 Deadlock found when trying to get lock; try restarting transaction'));
        }
    });

    importCsv(IMPORT_HEADER."P-0001,ペン,文具,本,100,50,5\r\n")
        ->assertRedirect()
        ->assertSessionHasErrors(['file' => 'ほかの操作と同時に取り込もうとしたため、取り込めませんでした（1 件も登録・更新していません）。もう一度取り込んでください']);
});
