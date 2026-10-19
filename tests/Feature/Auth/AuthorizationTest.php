<?php

use App\Auth\Permission;
use App\Enums\Role;
use App\Http\Middleware\HandleInertiaRequests;
use App\Models\User;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Route;
use Inertia\Testing\AssertableInertia as Assert;

/**
 * サーバー側の権限の検査（docs/spec.md §2）。権限外は 403、未ログインはログイン画面へ（JSON は 401）。
 * まだ業務の画面がないので、検査の仕組みはテスト用のルートで確かめる
 */
beforeEach(function () {
    Route::middleware(['web', 'auth'])->group(function () {
        // ルートで検査（middleware can:）
        foreach (Permission::cases() as $permission) {
            Route::get("/_test/route/{$permission->value}", fn () => 'ok')->can($permission);
        }

        // コントローラー（処理の中）で検査
        Route::post('/_test/controller/{permission}', function (string $permission) {
            Gate::authorize(Permission::from($permission));

            return 'ok';
        });
    });
});

/** @return list<array{Permission, Role}> */
function permissionRoleCases(): array
{
    $cases = [];
    foreach (Permission::cases() as $permission) {
        foreach (Role::cases() as $role) {
            $cases["{$role->value} × {$permission->value}"] = [$permission, $role];
        }
    }

    return $cases;
}

test('Gate の判定が権限表と一致する（27 通り）', function (Permission $permission, Role $role) {
    $user = User::factory()->role($role)->create();

    expect(Gate::forUser($user)->allows($permission))->toBe($permission->allows($role));
})->with(permissionRoleCases());

test('ルートの検査（can:）: 許可されたロールは 200、それ以外は 403', function (Permission $permission, Role $role) {
    $user = User::factory()->role($role)->create();

    $response = $this->actingAs($user)->get("/_test/route/{$permission->value}");

    $response->assertStatus($permission->allows($role) ? 200 : 403);
})->with(permissionRoleCases());

test('コントローラーの検査（Gate::authorize）: 許可されたロールは 200、それ以外は 403', function (Permission $permission, Role $role) {
    $user = User::factory()->role($role)->create();

    $response = $this->actingAs($user)->post("/_test/controller/{$permission->value}");

    $response->assertStatus($permission->allows($role) ? 200 : 403);
})->with(permissionRoleCases());

test('権限外の画面は、HTTP 403 で日本語のエラーページ（共通レイアウトの中）を表示する', function () {
    $viewer = User::factory()->create();

    $response = $this->actingAs($viewer)->get('/_test/route/user.manage');

    $response->assertForbidden();
    $response->assertInertia(fn (Assert $page) => $page
        ->component('Error')
        ->where('status', 403)
        ->where('auth.user.name', $viewer->name)
        ->where('auth.user.roleLabel', '閲覧者'),
    );
});

test('Inertia の画面遷移（XHR）でも 403 のエラーページになる', function () {
    $viewer = User::factory()->create();
    $this->actingAs($viewer)->get(route('dashboard'));

    $response = $this->actingAs($viewer)->get('/_test/route/audit.view', [
        'X-Inertia' => 'true',
        'X-Inertia-Version' => (string) app(HandleInertiaRequests::class)->version(request()),
    ]);

    $response->assertForbidden();
    expect($response->json('component'))->toBe('Error');
});

test('権限外の JSON の要求は 403 と日本語のメッセージ', function () {
    $staff = User::factory()->staff()->create();

    $this->actingAs($staff)->getJson('/_test/route/master.deactivate')
        ->assertForbidden()
        ->assertExactJson(['message' => 'この操作を行う権限がありません']);
});

test('未ログインはログイン画面へ（ログイン後に元の URL に戻る）', function () {
    $this->get('/_test/route/view')->assertRedirect(route('login'));
    expect(session('url.intended'))->toBe(url('/_test/route/view'));

    $this->get(route('dashboard'))->assertRedirect(route('login'));
});

test('未ログインの JSON の要求は 401 と日本語のメッセージ', function () {
    $this->getJson('/_test/route/view')
        ->assertUnauthorized()
        ->assertExactJson(['message' => 'ログインが必要です']);
});

test('ダッシュボードはすべてのロールが開ける', function (Role $role) {
    $user = User::factory()->role($role)->create();

    $this->actingAs($user)->get(route('dashboard'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->component('Dashboard'));
})->with(Role::cases());

test('画面にはロールと、そのロールに許可された操作だけを渡す（メニューの表示用）', function () {
    $staff = User::factory()->staff()->create(['name' => 'デモ担当者']);

    $this->actingAs($staff)->get(route('dashboard'))
        ->assertInertia(fn (Assert $page) => $page
            ->where('auth.user', [
                'id' => $staff->id,
                'name' => 'デモ担当者',
                'email' => $staff->email,
                'role' => 'staff',
                'roleLabel' => '担当者',
            ])
            ->where('auth.permissions', ['view', 'master.edit', 'order.edit', 'stock.adjust', 'csv.export', 'csv.import']),
        );
});
