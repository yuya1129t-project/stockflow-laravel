<?php

use App\Enums\Role;
use App\Models\AuditLog;
use App\Models\Partner;
use App\Models\User;
use App\Policies\PartnerPolicy;
use Illuminate\Support\Facades\Gate;
use Inertia\Testing\AssertableInertia as Assert;

/**
 * 取引先の権限（docs/spec.md §2）。サーバー側で検査し、権限外は 403。
 * ルートの can: と、コントローラー（Form Request）の Policy の両方で検査する
 */
function validPartnerInput(): array
{
    return ['code' => 'C-0201', 'name' => '権限テスト商事', 'kind' => 'customer'];
}

test('一覧・詳細はすべてのロールが開ける', function (Role $role) {
    $user = User::factory()->role($role)->create();
    $partner = Partner::factory()->create();

    $this->actingAs($user)->get(route('partners.index'))->assertOk();
    $this->actingAs($user)->get(route('partners.show', $partner))->assertOk();
})->with(Role::cases());

test('viewer は登録・編集・無効化・住所検索のすべてを 403 で拒否され、DB は変わらない', function () {
    $viewer = User::factory()->create();
    $partner = Partner::factory()->create(['name' => '元の名前']);

    $this->actingAs($viewer)->get(route('partners.create'))->assertForbidden();
    $this->actingAs($viewer)->post(route('partners.store'), validPartnerInput())->assertForbidden();
    $this->actingAs($viewer)->get(route('partners.edit', $partner))->assertForbidden();
    $this->actingAs($viewer)->put(route('partners.update', $partner), validPartnerInput())->assertForbidden();
    $this->actingAs($viewer)->post(route('partners.deactivate', $partner))->assertForbidden();
    $this->actingAs($viewer)->post(route('partners.reactivate', $partner))->assertForbidden();
    $this->actingAs($viewer)->getJson(route('partners.address-lookup', ['postal_code' => '0310001']))
        ->assertForbidden()
        ->assertExactJson(['message' => 'この操作を行う権限がありません']);

    expect(Partner::query()->count())->toBe(1);
    expect($partner->refresh()->only(['name', 'is_active']))->toBe(['name' => '元の名前', 'is_active' => true]);
    expect(AuditLog::query()->count())->toBe(0);
});

test('viewer の権限外の画面は、日本語のエラーページ（403）', function () {
    $viewer = User::factory()->create();

    $this->actingAs($viewer)->get(route('partners.create'))
        ->assertForbidden()
        ->assertInertia(fn (Assert $page) => $page->component('Error')->where('status', 403));
});

test('viewer の不正な入力も、検証より先に権限で拒否する（入力エラーを返さない）', function () {
    $viewer = User::factory()->create();

    $this->actingAs($viewer)->post(route('partners.store'), ['code' => ''])->assertForbidden()->assertSessionHasNoErrors();
});

test('staff は無効化・再有効化を 403 で拒否される（admin のみ）', function () {
    $staff = User::factory()->staff()->create();
    $active = Partner::factory()->create();
    $inactive = Partner::factory()->create(['is_active' => false]);

    $this->actingAs($staff)->post(route('partners.deactivate', $active))->assertForbidden();
    $this->actingAs($staff)->post(route('partners.reactivate', $inactive))->assertForbidden();

    expect($active->refresh()->is_active)->toBeTrue();
    expect($inactive->refresh()->is_active)->toBeFalse();
    expect(AuditLog::query()->count())->toBe(0);
});

test('未ログインはログイン画面へ（JSON の住所検索は 401）', function () {
    $partner = Partner::factory()->create();

    $this->get(route('partners.index'))->assertRedirect(route('login'));
    $this->post(route('partners.store'), validPartnerInput())->assertRedirect(route('login'));
    $this->put(route('partners.update', $partner), validPartnerInput())->assertRedirect(route('login'));
    $this->getJson(route('partners.address-lookup', ['postal_code' => '0310001']))->assertUnauthorized();
});

test('Policy の判定は権限表と一致する（ルートの can: とは別に、コントローラーで二重に検査する）', function (Role $role) {
    $user = User::factory()->role($role)->create();
    $partner = Partner::factory()->create();
    $gate = Gate::forUser($user);

    expect(Gate::getPolicyFor(Partner::class))->toBeInstanceOf(PartnerPolicy::class);
    expect($gate->allows('viewAny', Partner::class))->toBeTrue();
    expect($gate->allows('view', $partner))->toBeTrue();
    expect($gate->allows('create', Partner::class))->toBe($role !== Role::Viewer);
    expect($gate->allows('update', $partner))->toBe($role !== Role::Viewer);
    expect($gate->allows('lookupAddress', Partner::class))->toBe($role !== Role::Viewer);
    expect($gate->allows('setActive', $partner))->toBe($role === Role::Admin);
})->with(Role::cases());

test('無効ユーザーには Policy でも何も許可しない', function () {
    $admin = User::factory()->admin()->inactive()->create();

    expect(Gate::forUser($admin)->allows('setActive', Partner::factory()->create()))->toBeFalse();
    expect(Gate::forUser($admin)->allows('create', Partner::class))->toBeFalse();
});
