<?php

use App\Enums\Role;
use App\Models\ApiToken;
use App\Models\User;
use App\Users\UserService;
use Illuminate\Support\Facades\DB;
use Tests\Support\CommitsTransactions;
use Tests\Support\StockWorker;

/**
 * ユーザー管理の同時実行（MySQL の独立した接続・別プロセスで実際に競合させる。docs/spec.md §3）。
 *
 * 操作は別プロセスのワーカー（tests/Support/user-worker.php）で動かす。先の側は確定の直前でロックを持ったまま止め、
 * 後の側がロック（admin の顔ぶれのユーザーロック GET_LOCK、またはユーザーの行ロック）を待っていることを
 * performance_schema で確かめてから先の側を確定させる（固定時間の待ちは証拠にしない。待ちに入らなければ上限の 10 秒でテストの失敗）。
 *
 * ワーカーはテストのトランザクションの外で書き込むので、このファイルはテストのトランザクションを張らない（CommitsTransactions）。
 * ワーカーは DB 名を testing に固定しているので、--parallel には対応しない
 */
uses(CommitsTransactions::class);

function userWorker(array $payload): StockWorker
{
    return StockWorker::start($payload, 'user-worker.php');
}

function adminSetLockName(): string
{
    return UserService::adminSetLockName(DB::connection()->getDatabaseName());
}

afterEach(function () {
    // 途中で失敗したときに、ロックを持ったままのワーカーが後始末を待たせないようにする
    StockWorker::stopAll();
});

describe('admin が 2 人だけのとき、互いを同時に変更しても有効な admin は 0 人にならない', function () {
    beforeEach(function () {
        $this->a = User::factory()->admin()->create(['name' => '管理者A']);
        $this->b = User::factory()->admin()->create(['name' => '管理者B']);
    });

    test('後の側は admin の顔ぶれのロックを待ち、先の確定の後に操作者を確かめ直して拒否される', function (array $first, array $second) {
        $a = userWorker([...$first, 'actorId' => $this->a->id, 'targetId' => $this->b->id, 'hold' => true]);
        $a->waitUntilHolding();
        $b = userWorker([...$second, 'actorId' => $this->b->id, 'targetId' => $this->a->id]);
        StockWorker::waitForUserLockWait($b->connectionId(), adminSetLockName());
        $a->release();
        $resultA = $a->result();
        $resultB = $b->result();

        expect($resultA['outcome'])->toBe('ok')
            ->and($resultB)->toBe(['outcome' => 'forbidden', 'message' => 'この操作を行う権限がありません'])
            ->and(User::query()->where('role', Role::Admin)->where('is_active', true)->pluck('id')->all())->toBe([$this->a->id])
            // 拒否された側の操作の履歴は残らない
            ->and(DB::table('audit_logs')->where('user_id', $this->b->id)->count())->toBe(0);
    })->with([
        '互いに降格' => [['op' => 'changeRole', 'role' => 'viewer'], ['op' => 'changeRole', 'role' => 'viewer']],
        '互いに無効化' => [['op' => 'setActive', 'active' => false], ['op' => 'setActive', 'active' => false]],
        '降格と無効化' => [['op' => 'changeRole', 'role' => 'staff'], ['op' => 'setActive', 'active' => false]],
    ]);
});

test('無効化とトークン発行が同時: 発行は対象の行のロックを待ち、無効化の確定の後に「無効なユーザーには発行できません」になる', function () {
    $admin = User::factory()->admin()->create();
    $other = User::factory()->admin()->create();
    $target = User::factory()->staff()->create();

    $deactivate = userWorker(['op' => 'setActive', 'actorId' => $admin->id, 'targetId' => $target->id, 'active' => false, 'hold' => true]);
    $deactivate->waitUntilHolding();
    $issue = userWorker(['op' => 'issueToken', 'actorId' => $other->id, 'targetId' => $target->id, 'name' => '同時']);
    StockWorker::waitForLockWait($issue->connectionId(), $deactivate->connectionId());
    $deactivate->release();
    $a = $deactivate->result();
    $b = $issue->result();

    expect($a['outcome'])->toBe('ok')
        ->and($b)->toBe(['outcome' => 'invalid', 'errors' => ['form' => UserService::INACTIVE_TOKEN_MESSAGE]])
        ->and($target->fresh()->is_active)->toBeFalse()
        // 無効なユーザーに有効なトークンが残らない（残ると、再有効化で生き返る）
        ->and(ApiToken::query()->where('tokenable_id', $target->id)->whereNull('revoked_at')->count())->toBe(0);
});

test('トークン発行と無効化が同時（発行が先）: 無効化は行のロックを待ち、発行されたトークンも失効させる', function () {
    $admin = User::factory()->admin()->create();
    $other = User::factory()->admin()->create();
    $target = User::factory()->staff()->create();

    $issue = userWorker(['op' => 'issueToken', 'actorId' => $other->id, 'targetId' => $target->id, 'name' => '同時', 'hold' => true]);
    $issue->waitUntilHolding();
    $deactivate = userWorker(['op' => 'setActive', 'actorId' => $admin->id, 'targetId' => $target->id, 'active' => false]);
    StockWorker::waitForLockWait($deactivate->connectionId(), $issue->connectionId());
    $issue->release();
    $a = $issue->result();
    $b = $deactivate->result();

    expect($a['outcome'])->toBe('ok')
        ->and($b['outcome'])->toBe('ok')
        ->and(ApiToken::query()->findOrFail($a['tokenId'])->revoked_at)->not->toBeNull();
});
