<?php

/**
 * 発注の同時実行テスト（tests/Feature/PurchaseOrders/PurchaseOrderConcurrencyTest.php）が別プロセスで起動する、発注・取引先・商品の操作の 1 本。
 * テストのプロセスとは別の MySQL の接続で、実際のサービス（PurchaseOrderService・PartnerService・ProductService）を動かす。
 * 起動は Tests\Support\StockWorker::start($payload, 'purchase-order-worker.php')。
 *
 * payload:
 * - op: create（発注の作成）／update（発注の編集）／status（ステータス変更）／partnerUpdate（取引先の編集）／productDeactivate（商品の無効化）
 * - userId: 操作するユーザー
 * - input: create・update の入力（PurchaseOrderService の PurchaseOrderInput）
 * - id・version: update・status の発注と、画面を開いた時点の version
 * - to: status の変更先
 * - partnerId・partner: partnerUpdate の取引先と入力（PartnerService の PartnerInput）
 * - productId: productDeactivate の商品
 * - hold: 確定（commit）の直前で「holding」を出し、標準入力に 1 行来るまで確定しない（ロックを持ったまま待つ）
 *
 * 標準出力（1 行ずつ）: 最初に「connection <MySQL の接続 ID>」、hold なら「holding」、最後に結果の JSON
 * （outcome: ok／invalid（ValidationException。errors に項目ごとのメッセージ）／exception、attempts: トランザクションを始めた回数、
 *   number: 作成した発注の番号）
 */

use App\Enums\PurchaseOrderStatus;
use App\Models\Partner;
use App\Models\Product;
use App\Models\User;
use App\Products\ProductService;
use App\PurchaseOrders\PurchaseOrderService;
use App\Services\Partners\PartnerService;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Database\Events\TransactionBeginning;
use Illuminate\Database\Events\TransactionCommitting;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Validation\ValidationException;

require __DIR__.'/../../vendor/autoload.php';

$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

// テスト用 DB（testing）以外では動かさない（設定がキャッシュされていると環境変数が効かない）
if (! $app->environment('testing') || $app->configurationIsCached() || DB::connection()->getDatabaseName() !== 'testing') {
    fwrite(STDERR, "テスト用 DB ではないため中止しました\n");
    exit(2);
}

/** @var array{op: string, userId: int, input?: array<string, mixed>, id?: int, version?: string, to?: string, partnerId?: int, partner?: array<string, mixed>, productId?: int, hold?: bool} $payload */
$payload = json_decode((string) file_get_contents($argv[1]), true, flags: JSON_THROW_ON_ERROR);

$line = function (string $text): void {
    fwrite(STDOUT, $text."\n");
    fflush(STDOUT);
};

$connectionId = DB::selectOne('SELECT CONNECTION_ID() AS id');
$line('connection '.(is_object($connectionId) ? $connectionId->id : 0));

$attempts = 0;
Event::listen(TransactionBeginning::class, function () use (&$attempts) {
    $attempts++;
});
if ($payload['hold'] ?? false) {
    // 最も外側のトランザクションの確定の直前（ロックを持ったまま）で、テストからの合図を待つ
    Event::listen(TransactionCommitting::class, function () use ($line) {
        $line('holding');
        fgets(STDIN);
    });
}

try {
    $user = User::query()->findOrFail($payload['userId']);
    $result = ['outcome' => 'ok'];
    switch ($payload['op']) {
        case 'create':
            $order = app(PurchaseOrderService::class)->create($user, $payload['input'] ?? []);
            $result['number'] = $order->number;
            $result['id'] = $order->id;
            break;
        case 'update':
            $result['changed'] = app(PurchaseOrderService::class)->update($user, $payload['id'] ?? 0, $payload['input'] ?? [], $payload['version'] ?? null);
            break;
        case 'status':
            $changed = app(PurchaseOrderService::class)->changeStatus($user, $payload['id'] ?? 0, PurchaseOrderStatus::from($payload['to'] ?? ''), $payload['version'] ?? null);
            $result['to'] = $changed['to']->value;
            break;
        case 'partnerUpdate':
            $result['changed'] = app(PartnerService::class)->update($user, Partner::query()->findOrFail($payload['partnerId'] ?? 0), $payload['partner'] ?? []);
            break;
        case 'productDeactivate':
            $result['changed'] = app(ProductService::class)->setActive($user, Product::query()->findOrFail($payload['productId'] ?? 0), false);
            break;
        default:
            throw new InvalidArgumentException("不明な op: {$payload['op']}");
    }
} catch (ValidationException $e) {
    $result = ['outcome' => 'invalid', 'errors' => array_map(fn (array $messages) => $messages[0], $e->errors())];
} catch (Throwable $e) {
    $result = ['outcome' => 'exception', 'message' => $e::class.': '.$e->getMessage()];
}

$line(json_encode([...$result, 'attempts' => $attempts], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
