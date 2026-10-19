<?php

/**
 * ユーザー管理の同時実行テスト（tests/Feature/Users/UserConcurrencyTest.php）が別プロセスで起動する、ユーザー管理の操作の 1 本。
 * テストのプロセスとは別の MySQL の接続で、実際のサービス（App\Users\UserService）を動かす。
 * 起動は Tests\Support\StockWorker::start($payload, 'user-worker.php')。
 *
 * payload:
 * - op: changeRole（ロール変更）／setActive（無効化・再有効化）／issueToken（API トークンの発行）
 * - actorId: 操作する admin（認可の時点のユーザー。サービスはロックの後に確かめ直す）
 * - targetId: 対象のユーザー
 * - role: changeRole の変更先／active: setActive の変更先／name: issueToken のトークンの名前
 * - hold: 確定（commit）の直前で「holding」を出し、標準入力に 1 行来るまで確定しない（ロックを持ったまま待つ）
 *
 * 標準出力（1 行ずつ）: 最初に「connection <MySQL の接続 ID>」、hold なら「holding」、最後に結果の JSON
 * （outcome: ok／invalid（ValidationException。errors に項目ごとのメッセージ）／forbidden（AuthorizationException）／exception）
 */

use App\Enums\Role;
use App\Models\User;
use App\Users\UserService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\Console\Kernel;
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

/** @var array{op: string, actorId: int, targetId: int, role?: string, active?: bool, name?: string, hold?: bool} $payload */
$payload = json_decode((string) file_get_contents($argv[1]), true, flags: JSON_THROW_ON_ERROR);

$line = function (string $text): void {
    fwrite(STDOUT, $text."\n");
    fflush(STDOUT);
};

$connectionId = DB::selectOne('SELECT CONNECTION_ID() AS id');
$line('connection '.(is_object($connectionId) ? $connectionId->id : 0));

if ($payload['hold'] ?? false) {
    // 最も外側のトランザクションの確定の直前（ロックを持ったまま）で、テストからの合図を待つ
    Event::listen(TransactionCommitting::class, function () use ($line) {
        $line('holding');
        fgets(STDIN);
    });
}

try {
    // 認可の時点の操作者（ロックの前に読んだ値）。サービスはロックの後に DB から読み直して確かめる
    $actor = User::query()->findOrFail($payload['actorId']);
    $target = User::query()->findOrFail($payload['targetId']);
    $service = app(UserService::class);
    $result = ['outcome' => 'ok'];
    switch ($payload['op']) {
        case 'changeRole':
            $result['changed'] = $service->changeRole($actor, $target, Role::from($payload['role'] ?? ''));
            break;
        case 'setActive':
            $result['changed'] = $service->setActive($actor, $target, $payload['active'] ?? false)['changed'];
            break;
        case 'issueToken':
            $result['tokenId'] = $service->issueToken($actor, $target, $payload['name'] ?? 'テスト')->accessToken->getKey();
            break;
        default:
            throw new InvalidArgumentException("不明な op: {$payload['op']}");
    }
} catch (ValidationException $e) {
    $result = ['outcome' => 'invalid', 'errors' => array_map(fn (array $messages) => $messages[0], $e->errors())];
} catch (AuthorizationException $e) {
    $result = ['outcome' => 'forbidden', 'message' => $e->getMessage()];
} catch (Throwable $e) {
    $result = ['outcome' => 'exception', 'message' => $e::class.': '.$e->getMessage()];
}

$line(json_encode($result, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
