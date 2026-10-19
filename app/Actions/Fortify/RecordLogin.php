<?php

namespace App\Actions\Fortify;

use App\Models\AuditLog;
use Closure;
use Illuminate\Http\Request;

/**
 * ログインの手順（FortifyServiceProvider の authenticateThrough）の最後に、成功したログインを操作履歴に記録する
 * （docs/spec.md §3）。失敗したログイン・無効ユーザーの試行はこの手順まで届かないので記録しない
 */
class RecordLogin
{
    /**
     * @param  Closure(Request): mixed  $next
     */
    public function handle(Request $request, Closure $next): mixed
    {
        $user = $request->user();

        if ($user !== null) {
            AuditLog::create([
                'user_id' => $user->id,
                'action' => 'login',
                'target_type' => 'user',
                'target_id' => (string) $user->id,
                'summary' => 'ログインしました',
            ]);
        }

        return $next($request);
    }
}
