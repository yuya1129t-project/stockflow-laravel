<?php

namespace App\Http\Middleware;

use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * ログイン中に無効化されたユーザーを、次のリクエストでログアウトさせる（docs/spec.md §3「無効ユーザーはログイン不可」）。
 * ログイン時の拒否は FortifyServiceProvider の authenticateUsing で行う
 */
class EnsureUserIsActive
{
    public const string MESSAGE = 'アカウントが無効になったため、ログアウトしました';

    /**
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (! $user instanceof User || $user->is_active) {
            return $next($request);
        }

        Auth::guard('web')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        if ($request->expectsJson()) {
            return response()->json(['message' => self::MESSAGE], 401);
        }

        return redirect()->route('login')->with('status', self::MESSAGE);
    }
}
