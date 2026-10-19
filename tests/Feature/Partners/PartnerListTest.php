<?php

use App\Enums\PartnerKind;
use App\Enums\PartnerRole;
use App\Enums\Role;
use App\Models\Partner;
use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;

/** 取引先の一覧（検索・絞り込み・並べ替え・ページ送り・無効の表示。docs/spec.md §5-4） */
function partnerCodes(Assert $page): array
{
    return array_column($page->toArray()['props']['partners']['data'], 'code');
}

function listPartners(array $query = []): Assert
{
    $captured = null;
    test()->actingAs(User::factory()->create())
        ->get(route('partners.index', $query))
        ->assertOk()
        ->assertInertia(function (Assert $page) use (&$captured) {
            $page->component('partners/Index');
            $captured = $page;
        });

    return $captured;
}

test('既定は有効な取引先をコード順に 20 件ずつ', function () {
    foreach (range(25, 1) as $i) {
        Partner::factory()->create(['code' => sprintf('C-%04d', $i)]);
    }
    Partner::factory()->create(['code' => 'C-0000', 'is_active' => false]);

    $page = listPartners();

    expect(partnerCodes($page))->toBe(array_map(fn ($i) => sprintf('C-%04d', $i), range(1, 20)));
    $props = $page->toArray()['props'];
    expect($props['partners']['meta'])->toBe(['page' => 1, 'perPage' => 20, 'total' => 25, 'lastPage' => 2]);
    expect($props['filters'])->toBe(['q' => '', 'kind' => '', 'status' => 'active', 'sort' => 'code', 'order' => 'asc']);
    expect($props['partners']['data'][0])->toMatchArray([
        'code' => 'C-0001',
        'kind' => 'customer',
        'kindLabel' => '顧客',
        'isActive' => true,
    ]);
});

test('キーワードは取引先コード・取引先名の部分一致（大文字小文字を区別しない）', function () {
    Partner::factory()->create(['code' => 'C-0001', 'name' => 'アルファ商事']);
    Partner::factory()->create(['code' => 'C-0002', 'name' => 'ベータ物産']);
    Partner::factory()->create(['code' => 'S-0001', 'name' => 'Gamma Trading']);

    expect(partnerCodes(listPartners(['q' => 'c-000'])))->toBe(['C-0001', 'C-0002']);
    expect(partnerCodes(listPartners(['q' => '物産'])))->toBe(['C-0002']);
    expect(partnerCodes(listPartners(['q' => 'gamma'])))->toBe(['S-0001']);
});

test('キーワードの % _ \ は文字として扱う（LIKE のワイルドカードにしない）', function () {
    Partner::factory()->create(['code' => 'C-0001', 'name' => '100%商事']);
    Partner::factory()->create(['code' => 'C-0002', 'name' => '1000商事']);
    Partner::factory()->create(['code' => 'C_0003', 'name' => 'アンダー']);
    Partner::factory()->create(['code' => 'C-0004', 'name' => 'A\\B商会']);
    Partner::factory()->create(['code' => 'C-0005', 'name' => 'AB商会']);

    expect(partnerCodes(listPartners(['q' => '0%'])))->toBe(['C-0001']);
    expect(partnerCodes(listPartners(['q' => 'C_'])))->toBe(['C_0003']);
    expect(partnerCodes(listPartners(['q' => '\\B'])))->toBe(['C-0004']);
});

test('種別の絞り込み: 「顧客」は customer と both、「仕入先」は supplier と both、「顧客・仕入先」は both だけ', function () {
    Partner::factory()->create(['code' => 'C-0001', 'kind' => PartnerKind::Customer]);
    Partner::factory()->create(['code' => 'S-0001', 'kind' => PartnerKind::Supplier]);
    Partner::factory()->create(['code' => 'B-0001', 'kind' => PartnerKind::Both]);

    expect(partnerCodes(listPartners(['kind' => 'customer'])))->toBe(['B-0001', 'C-0001']);
    expect(partnerCodes(listPartners(['kind' => 'supplier'])))->toBe(['B-0001', 'S-0001']);
    expect(partnerCodes(listPartners(['kind' => 'both'])))->toBe(['B-0001']);
    expect(partnerCodes(listPartners()))->toBe(['B-0001', 'C-0001', 'S-0001']);
});

test('受注・発注の取引先の選択肢に使う絞り込み（Partner::withRole）は both を含む', function () {
    Partner::factory()->create(['code' => 'C-0001', 'kind' => PartnerKind::Customer]);
    Partner::factory()->create(['code' => 'S-0001', 'kind' => PartnerKind::Supplier]);
    Partner::factory()->create(['code' => 'B-0001', 'kind' => PartnerKind::Both]);

    expect(Partner::query()->withRole(PartnerRole::Customer)->orderBy('code')->pluck('code')->all())->toBe(['B-0001', 'C-0001']);
    expect(Partner::query()->withRole(PartnerRole::Supplier)->orderBy('code')->pluck('code')->all())->toBe(['B-0001', 'S-0001']);
});

test('取引先名・種別で並べ替えられる（昇順・降順。同じ値はコード順）', function () {
    Partner::factory()->create(['code' => 'C-0001', 'name' => 'ウ', 'kind' => PartnerKind::Customer]);
    Partner::factory()->create(['code' => 'C-0002', 'name' => 'ア', 'kind' => PartnerKind::Supplier]);
    Partner::factory()->create(['code' => 'C-0003', 'name' => 'イ', 'kind' => PartnerKind::Customer]);

    expect(partnerCodes(listPartners(['sort' => 'name', 'order' => 'asc'])))->toBe(['C-0002', 'C-0003', 'C-0001']);
    expect(partnerCodes(listPartners(['sort' => 'name', 'order' => 'desc'])))->toBe(['C-0001', 'C-0003', 'C-0002']);
    expect(partnerCodes(listPartners(['sort' => 'kind', 'order' => 'desc'])))->toBe(['C-0002', 'C-0001', 'C-0003']);
    expect(partnerCodes(listPartners(['sort' => 'code', 'order' => 'desc'])))->toBe(['C-0003', 'C-0002', 'C-0001']);
});

test('ページ送り: 2 ページ目は続き。範囲外のページは最終ページに丸める', function () {
    foreach (range(1, 23) as $i) {
        Partner::factory()->create(['code' => sprintf('C-%04d', $i)]);
    }

    expect(partnerCodes(listPartners(['page' => 2])))->toBe(['C-0021', 'C-0022', 'C-0023']);

    $page = listPartners(['page' => 99]);
    expect(partnerCodes($page))->toBe(['C-0021', 'C-0022', 'C-0023']);
    expect($page->toArray()['props']['partners']['meta']['page'])->toBe(2);
});

test('無効な取引先は「有効のみ」に出ず、「無効のみ」「無効も表示」に出る', function () {
    Partner::factory()->create(['code' => 'C-0001']);
    Partner::factory()->create(['code' => 'C-0002', 'is_active' => false]);

    expect(partnerCodes(listPartners()))->toBe(['C-0001']);
    expect(partnerCodes(listPartners(['status' => 'inactive'])))->toBe(['C-0002']);
    expect(partnerCodes(listPartners(['status' => 'all'])))->toBe(['C-0001', 'C-0002']);
});

test('不正な検索条件は既定値にする（エラーにしない。並べ替えの列はホワイトリスト）', function () {
    Partner::factory()->create(['code' => 'C-0001']);

    $page = listPartners(['kind' => 'x', 'status' => 'deleted', 'sort' => 'email; drop table partners', 'order' => 'up', 'q' => ['a']]);

    expect(partnerCodes($page))->toBe(['C-0001']);
    expect($page->toArray()['props']['filters'])->toBe(['q' => '', 'kind' => '', 'status' => 'active', 'sort' => 'code', 'order' => 'asc']);
});

test('一覧の画面に、ロールに応じたボタンの表示の切り替え（can）を渡す', function (string $role, array $can) {
    $user = User::factory()->role(Role::from($role))->create();

    $this->actingAs($user)->get(route('partners.index'))
        ->assertInertia(fn (Assert $page) => $page->where('can', $can));
})->with([
    'admin' => ['admin', ['create' => true, 'update' => true, 'setActive' => true]],
    'staff' => ['staff', ['create' => true, 'update' => true, 'setActive' => false]],
    'viewer' => ['viewer', ['create' => false, 'update' => false, 'setActive' => false]],
]);
