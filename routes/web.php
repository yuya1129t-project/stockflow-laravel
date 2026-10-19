<?php

use App\Auth\Permission;
use Illuminate\Support\Facades\Route;

// トップはダッシュボードへ（未ログインならログイン画面へ）
Route::redirect('/', '/dashboard')->name('home');

// ログインが必要な画面。権限はルートごとに ->can(Permission::...) で検査する（docs/spec.md §2）
Route::middleware(['auth'])->group(function () {
    Route::inertia('dashboard', 'Dashboard')->can(Permission::View)->name('dashboard');
});

require __DIR__.'/settings.php';
