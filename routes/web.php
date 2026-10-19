<?php

use App\Auth\Permission;
use App\Http\Controllers\PartnerController;
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

    // 取引先マスタ（docs/spec.md §5-4・§6）。コントローラーでも Policy（PartnerPolicy）で検査する
    Route::controller(PartnerController::class)->prefix('partners')->name('partners.')->group(function () {
        Route::get('/', 'index')->can(Permission::View)->name('index');
        Route::get('create', 'create')->can(Permission::MasterEdit)->name('create');
        Route::post('/', 'store')->can(Permission::MasterEdit)->name('store');
        Route::get('address-lookup', 'addressLookup')->can(Permission::MasterEdit)->name('address-lookup');
        Route::get('{partner}', 'show')->can(Permission::View)->whereNumber('partner')->name('show');
        Route::get('{partner}/edit', 'edit')->can(Permission::MasterEdit)->whereNumber('partner')->name('edit');
        Route::put('{partner}', 'update')->can(Permission::MasterEdit)->whereNumber('partner')->name('update');
        Route::post('{partner}/deactivate', 'deactivate')->can(Permission::MasterDeactivate)->whereNumber('partner')->name('deactivate');
        Route::post('{partner}/reactivate', 'reactivate')->can(Permission::MasterDeactivate)->whereNumber('partner')->name('reactivate');
    });
});

require __DIR__.'/settings.php';
