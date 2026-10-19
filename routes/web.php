<?php

use App\Auth\Permission;
use Illuminate\Support\Facades\Route;
use Laravel\Fortify\Http\Controllers\AuthenticatedSessionController;

// トップはダッシュボードへ（未ログインならログイン画面へ）
Route::redirect('/', '/dashboard')->name('home');

// ログイン・ログアウト（Fortify のコントローラー。Fortify のルートは登録させていない: FortifyServiceProvider::register）
Route::get('login', [AuthenticatedSessionController::class, 'create'])->middleware('guest:web')->name('login');
Route::post('login', [AuthenticatedSessionController::class, 'store'])->middleware(['guest:web', 'throttle:login'])->name('login.store');
Route::post('logout', [AuthenticatedSessionController::class, 'destroy'])->middleware('auth:web')->name('logout');

// ログインが必要な画面。権限はルートごとに ->can(Permission::...) で検査する（docs/spec.md §2）
Route::middleware(['auth'])->group(function () {
    Route::inertia('dashboard', 'Dashboard')->can(Permission::View)->name('dashboard');
});

require __DIR__.'/settings.php';
