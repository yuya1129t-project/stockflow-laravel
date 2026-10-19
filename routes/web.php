<?php

use App\Auth\Permission;
use App\Http\Controllers\ProductActivationController;
use App\Http\Controllers\ProductController;
use App\Http\Controllers\ProductExportController;
use App\Http\Controllers\ProductImportController;
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

    // 商品マスタ（docs/spec.md §5-3・§7）。コントローラー・Form Request でも Policy（ProductPolicy）で検査する
    Route::prefix('products')->name('products.')->group(function () {
        Route::get('/', [ProductController::class, 'index'])->can(Permission::View)->name('index');
        Route::get('create', [ProductController::class, 'create'])->can(Permission::MasterEdit)->name('create');
        Route::post('/', [ProductController::class, 'store'])->can(Permission::MasterEdit)->name('store');
        Route::get('export', ProductExportController::class)->can(Permission::CsvExport)->name('export');
        Route::get('import', [ProductImportController::class, 'create'])->can(Permission::CsvImport)->name('import');
        Route::post('import', [ProductImportController::class, 'store'])->can(Permission::CsvImport)->name('import.store');
        Route::get('{product}', [ProductController::class, 'show'])->whereNumber('product')->can(Permission::View)->name('show');
        Route::get('{product}/edit', [ProductController::class, 'edit'])->whereNumber('product')->can(Permission::MasterEdit)->name('edit');
        Route::put('{product}', [ProductController::class, 'update'])->whereNumber('product')->can(Permission::MasterEdit)->name('update');
        Route::post('{product}/deactivate', [ProductActivationController::class, 'deactivate'])->whereNumber('product')->can(Permission::MasterDeactivate)->name('deactivate');
        Route::post('{product}/reactivate', [ProductActivationController::class, 'reactivate'])->whereNumber('product')->can(Permission::MasterDeactivate)->name('reactivate');
    });
});

require __DIR__.'/settings.php';
