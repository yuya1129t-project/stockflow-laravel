<?php

use App\Enums\Role;
use App\Http\Controllers\ProductActivationController;
use App\Http\Controllers\ProductController;
use App\Http\Controllers\ProductImportController;
use App\Models\AuditLog;
use App\Models\Product;
use App\Models\User;
use Illuminate\Support\Facades\Route;
use Inertia\Testing\AssertableInertia as Assert;

/** 商品の登録・編集・詳細・無効化／再有効化と権限・操作履歴（docs/spec.md §2・§3・§5-3） */

/** @return array<string, string> */
function productInput(array $overrides = []): array
{
    return [
        'code' => 'P-5001',
        'name' => 'ゲルインクボールペン 黒',
        'category' => '文具',
        'unit' => '本',
        'sales_price' => '150',
        'purchase_price' => '90',
        'reorder_point' => '20',
        ...$overrides,
    ];
}

test('登録: 商品と操作履歴を記録し、詳細画面へ移る', function () {
    $staff = User::factory()->staff()->create();

    $response = $this->actingAs($staff)->post(route('products.store'), productInput());

    $product = Product::query()->sole();
    $response->assertRedirect(route('products.show', $product))
        ->assertInertiaFlash('toast', ['type' => 'success', 'message' => '商品 P-5001 を登録しました']);
    expect($product->only(['code', 'name', 'category', 'unit', 'sales_price', 'purchase_price', 'reorder_point', 'is_active']))->toBe([
        'code' => 'P-5001',
        'name' => 'ゲルインクボールペン 黒',
        'category' => '文具',
        'unit' => '本',
        'sales_price' => 150,
        'purchase_price' => 90,
        'reorder_point' => 20,
        'is_active' => true,
    ]);
    $log = AuditLog::query()->sole();
    expect($log->only(['user_id', 'action', 'target_type', 'target_id', 'summary']))->toBe([
        'user_id' => $staff->id,
        'action' => 'create',
        'target_type' => 'product',
        'target_id' => (string) $product->id,
        'summary' => '商品 P-5001「ゲルインクボールペン 黒」を登録しました',
    ]);
});

test('登録: 商品コードは大文字に、数値は全角・桁区切りを正規化する。is_active は入力で変えられない', function () {
    $this->actingAs(User::factory()->staff()->create())
        ->post(route('products.store'), productInput([
            'code' => ' p-5002 ',
            'sales_price' => '１，２００',
            'purchase_price' => '1,000',
            'reorder_point' => '０',
            'is_active' => '0',
        ]))
        ->assertSessionHasNoErrors();

    expect(Product::query()->sole()->only(['code', 'sales_price', 'purchase_price', 'reorder_point', 'is_active']))->toBe([
        'code' => 'P-5002',
        'sales_price' => 1200,
        'purchase_price' => 1000,
        'reorder_point' => 0,
        'is_active' => true,
    ]);
});

test('登録: 商品コードの一意違反（大文字小文字を区別しない）', function () {
    Product::factory()->create(['code' => 'P-5001']);

    $this->actingAs(User::factory()->staff()->create())
        ->post(route('products.store'), productInput(['code' => 'p-5001']))
        ->assertSessionHasErrors(['code' => '商品コード「P-5001」はすでに登録されています']);

    expect(Product::query()->count())->toBe(1)
        ->and(AuditLog::query()->count())->toBe(0);
});

test('入力の検証（日本語のメッセージ）', function (array $input, string $field, string $message) {
    $this->actingAs(User::factory()->staff()->create())
        ->post(route('products.store'), productInput($input))
        ->assertSessionHasErrors([$field => $message]);

    expect(Product::query()->count())->toBe(0);
})->with([
    '必須' => [['name' => '  '], 'name', '商品名を入力してください'],
    '商品コードの形式' => [['code' => 'P 0001'], 'code', '商品コードは半角英数字・ハイフン（-）・アンダースコア（_）で入力してください（先頭は英数字）'],
    '商品コードの先頭' => [['code' => '-P1'], 'code', '商品コードは半角英数字・ハイフン（-）・アンダースコア（_）で入力してください（先頭は英数字）'],
    '文字数' => [['name' => str_repeat('あ', 101)], 'name', '商品名は100文字以内で入力してください'],
    '単位の文字数' => [['unit' => str_repeat('a', 11)], 'unit', '単位は10文字以内で入力してください'],
    '改行' => [['name' => "ペン\n黒"], 'name', '商品名に改行・タブなどの制御文字は使えません'],
    'タブ' => [['category' => "文\t具"], 'category', 'カテゴリに改行・タブなどの制御文字は使えません'],
    '表示方向の制御文字' => [['name' => "ペン\u{202E}黒"], 'name', '商品名に改行・タブなどの制御文字は使えません'],
    '行区切り' => [['unit' => "本\u{2028}".'x'], 'unit', '単位に改行・タブなどの制御文字は使えません'],
    '負の数' => [['sales_price' => '-1'], 'sales_price', '売価は0以上の整数で入力してください'],
    '小数' => [['purchase_price' => '1.5'], 'purchase_price', '仕入単価は0以上の整数で入力してください'],
    '3 桁区切りでないカンマ' => [['sales_price' => '1,2000'], 'sales_price', '売価は0以上の整数で入力してください'],
    '価格の上限' => [['sales_price' => '100000000'], 'sales_price', '売価は99,999,999以下で入力してください'],
    '桁が多すぎる値' => [['sales_price' => '99999999999999999999999'], 'sales_price', '売価は99,999,999以下で入力してください'],
    '発注点の上限' => [['reorder_point' => '10000000'], 'reorder_point', '発注点は9,999,999以下で入力してください'],
    '数値の必須' => [['reorder_point' => ''], 'reorder_point', '発注点を入力してください'],
    '文字列でない値' => [['name' => ['a']], 'name', '商品名は文字列で入力してください'],
]);

test('上限ちょうどの値は通る', function () {
    $this->actingAs(User::factory()->staff()->create())
        ->post(route('products.store'), productInput([
            'code' => str_repeat('A', 20),
            'name' => str_repeat('あ', 100),
            'sales_price' => '99999999',
            'reorder_point' => '9999999',
        ]))
        ->assertSessionHasNoErrors();
});

test('編集: 変更点を操作履歴に残す。商品コードも変更でき、一意違反はエラー', function () {
    $admin = User::factory()->admin()->create();
    $product = Product::factory()->create(productInput(['sales_price' => 150]));
    Product::factory()->create(['code' => 'P-9999']);

    $this->actingAs($admin)->put(route('products.update', $product), productInput(['code' => 'p-9999']))
        ->assertSessionHasErrors(['code' => '商品コード「P-9999」はすでに登録されています']);

    $this->actingAs($admin)->put(route('products.update', $product), productInput(['sales_price' => '180', 'name' => '新しい名前']))
        ->assertRedirect(route('products.show', $product))
        ->assertInertiaFlash('toast', ['type' => 'success', 'message' => '商品を保存しました']);

    expect($product->fresh()?->sales_price)->toBe(180)
        ->and(AuditLog::query()->sole()->only(['action', 'target_id', 'summary']))->toBe([
            'action' => 'update',
            'target_id' => (string) $product->id,
            'summary' => '商品 P-5001 を更新しました（商品名: ゲルインクボールペン 黒 → 新しい名前、売価: 150 → 180）',
        ]);
});

test('編集: 自分自身のコードは一意違反にしない。変更がなければ操作履歴を残さない', function () {
    $product = Product::factory()->create(productInput());

    $this->actingAs(User::factory()->staff()->create())
        ->put(route('products.update', $product), productInput())
        ->assertSessionHasNoErrors()
        ->assertInertiaFlash('toast', ['type' => 'info', 'message' => '変更はありませんでした']);

    expect(AuditLog::query()->count())->toBe(0);
});

test('詳細・編集画面は現在庫を含む商品を渡す', function () {
    $product = Product::factory()->create(['reorder_point' => 50]);

    $this->actingAs(User::factory()->create())->get(route('products.show', $product))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('products/Show')
            ->where('product.id', $product->id)
            ->where('product.stock', 0)
            ->where('product.is_low_stock', true));

    $this->actingAs(User::factory()->staff()->create())->get(route('products.edit', $product))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('products/Edit')
            ->where('product.code', $product->code)
            ->where('limits', ['code' => 20, 'name' => 100, 'category' => 50, 'unit' => 10]));
});

test('存在しない商品は 404', function () {
    $this->actingAs(User::factory()->admin()->create())->get('/products/999999')->assertNotFound();
});

test('無効化・再有効化（admin）: 状態を変え、操作履歴を残す。同じ状態への変更は何もしない', function () {
    $admin = User::factory()->admin()->create();
    $product = Product::factory()->create(['code' => 'P-5001', 'name' => 'ペン']);

    $this->actingAs($admin)->post(route('products.deactivate', $product))
        ->assertRedirect(route('products.show', $product));
    expect($product->fresh()?->is_active)->toBeFalse();

    $this->actingAs($admin)->post(route('products.deactivate', $product));
    $this->actingAs($admin)->post(route('products.reactivate', $product));
    expect($product->fresh()?->is_active)->toBeTrue();

    expect(AuditLog::query()->orderBy('id')->get()->map->only(['action', 'summary'])->all())->toBe([
        ['action' => 'deactivate', 'summary' => '商品 P-5001「ペン」を無効にしました'],
        ['action' => 'reactivate', 'summary' => '商品 P-5001「ペン」を再度有効にしました'],
    ]);
});

test('権限: viewer は更新系がすべて 403（画面も操作も）', function (string $method, string $routeName, bool $withProduct) {
    $product = Product::factory()->create();
    $viewer = User::factory()->create();

    $url = route($routeName, $withProduct ? $product : []);
    $this->actingAs($viewer)->call($method, $url, productInput(['code' => 'P-7777']))->assertForbidden();

    expect(Product::query()->count())->toBe(1)
        ->and($product->fresh()?->only(['code', 'is_active']))->toBe(['code' => $product->code, 'is_active' => true])
        ->and(AuditLog::query()->count())->toBe(0);
})->with([
    '登録画面' => ['GET', 'products.create', false],
    '登録' => ['POST', 'products.store', false],
    '編集画面' => ['GET', 'products.edit', true],
    '編集' => ['PUT', 'products.update', true],
    '無効化' => ['POST', 'products.deactivate', true],
    '再有効化' => ['POST', 'products.reactivate', true],
    '取込画面' => ['GET', 'products.import', false],
    '取込' => ['POST', 'products.import.store', false],
]);

test('権限: staff は無効化・再有効化が 403', function (string $routeName) {
    $product = Product::factory()->create(['is_active' => $routeName === 'products.deactivate']);

    $this->actingAs(User::factory()->staff()->create())->post(route($routeName, $product))->assertForbidden();

    expect($product->fresh()?->is_active)->toBe($routeName === 'products.deactivate')
        ->and(AuditLog::query()->count())->toBe(0);
})->with(['products.deactivate', 'products.reactivate']);

test('権限: 閲覧（一覧・詳細）と CSV 出力は全ロール', function (Role $role) {
    $product = Product::factory()->create();
    $user = User::factory()->role($role)->create();

    $this->actingAs($user)->get(route('products.index'))->assertOk();
    $this->actingAs($user)->get(route('products.show', $product))->assertOk();
    $this->actingAs($user)->get(route('products.export'))->assertOk();
})->with(Role::cases());

test('権限: Policy でも検査する（ルートの can: を外しても拒否される）', function () {
    $product = Product::factory()->create();
    $viewer = User::factory()->create();
    $staff = User::factory()->staff()->create();

    // can: を付けないテスト用のルートで、コントローラー・Form Request の検査だけを確かめる
    Route::middleware(['web', 'auth'])->group(function () {
        Route::post('/_test/products', [ProductController::class, 'store']);
        Route::put('/_test/products/{product}', [ProductController::class, 'update']);
        Route::post('/_test/products/{product}/deactivate', [ProductActivationController::class, 'deactivate']);
        Route::post('/_test/products-import', [ProductImportController::class, 'store']);
    });
    $this->actingAs($viewer)->post('/_test/products', productInput(['code' => 'P-7777']))->assertForbidden();
    $this->actingAs($viewer)->put("/_test/products/{$product->id}", productInput())->assertForbidden();
    $this->actingAs($viewer)->post('/_test/products-import')->assertForbidden();
    $this->actingAs($staff)->post("/_test/products/{$product->id}/deactivate")->assertForbidden();
    expect(Product::query()->count())->toBe(1)
        ->and($product->fresh()?->is_active)->toBeTrue();

    expect($viewer->can('create', Product::class))->toBeFalse()
        ->and($viewer->can('update', $product))->toBeFalse()
        ->and($viewer->can('import', Product::class))->toBeFalse()
        ->and($viewer->can('export', Product::class))->toBeTrue()
        ->and($staff->can('update', $product))->toBeTrue()
        ->and($staff->can('changeActive', $product))->toBeFalse()
        ->and(User::factory()->admin()->inactive()->create()->can('changeActive', $product))->toBeFalse()
        ->and(User::factory()->admin()->create()->can('changeActive', $product))->toBeTrue();
});

test('未ログインはログイン画面へ', function () {
    $this->get(route('products.index'))->assertRedirect(route('login'));
    $this->post(route('products.store'), productInput())->assertRedirect(route('login'));
});
