<?php

namespace App\Listeners;

use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Auth\Events\Login;

/**
 * 成功したログインを操作履歴に記録する（docs/spec.md §3）。Laravel のイベントの自動検出で Login に登録される。
 *
 * - ログイン画面からのログイン（Fortify が guard の login() を呼ぶ）と、「ログインしたままにする」の
 *   Cookie による再認証（SessionGuard::user()）の両方で Login イベントが 1 回ずつ出る
 * - 失敗したログイン・無効ユーザーの拒否（authenticateUsing）では Login イベントが出ないので記録しない
 * - Cookie で再認証した無効ユーザーは、直後に EnsureUserIsActive がログアウトさせるので記録しない
 */
class RecordLogin
{
    public function handle(Login $event): void
    {
        $user = $event->user;

        if (! $user instanceof User || ! $user->is_active) {
            return;
        }

        AuditLog::create([
            'user_id' => $user->id,
            'action' => 'login',
            'target_type' => 'user',
            'target_id' => (string) $user->id,
            'summary' => 'ログインしました',
        ]);
    }
}
