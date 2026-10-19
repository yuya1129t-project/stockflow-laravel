<?php

use App\Auth\Permission;
use App\Http\Controllers\Api\V1\OpenApiController;
use App\Http\Controllers\Api\V1\ProductController;
use App\Http\Controllers\Api\V1\SalesOrderController;
use App\Users\ApiTokenAuth;
use Illuminate\Support\Facades\Route;

/*
 * 外部向け REST API（/api/v1。docs/spec.md §6-2・docs/openapi.json。Next.js 版と共通の契約）。
 * 接頭辞 api/v1 と api ミドルウェアは bootstrap/app.php で付ける。
 *
 * - 認証は Bearer トークンだけ（ApiTokenAuth::MIDDLEWARE。Cookie のセッションでは通らない）。権限はトークンの持ち主の
 *   いまのロールで、Web と同じ Gate（->can(Permission::...)）で検査する。参照は閲覧、受注の登録は受注の作成
 * - エラーは RFC 9457（application/problem+json）。未定義のパスの 404・定義したパスの未定義のメソッドの 405（Allow 付き）も
 *   同じ形（bootstrap/app.php の例外の描画。App\Api\V1\ApiResponse::fromException）
 */
Route::middleware(ApiTokenAuth::MIDDLEWARE)->group(function () {
    Route::get('products', [ProductController::class, 'index'])->can(Permission::View)->name('api.v1.products.index');
    Route::get('products/{code}/stock', [ProductController::class, 'stock'])->can(Permission::View)->name('api.v1.products.stock');
    Route::get('sales-orders', [SalesOrderController::class, 'index'])->can(Permission::View)->name('api.v1.sales-orders.index');
    Route::post('sales-orders', [SalesOrderController::class, 'store'])->can(Permission::OrderEdit)->name('api.v1.sales-orders.store');
    Route::get('sales-orders/{number}', [SalesOrderController::class, 'show'])->can(Permission::View)->name('api.v1.sales-orders.show');
});

// OpenAPI 3.1 の定義（認証不要）
Route::get('openapi.json', OpenApiController::class)->name('api.v1.openapi');
