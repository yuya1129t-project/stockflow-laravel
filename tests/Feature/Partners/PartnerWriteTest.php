<?php

use App\Enums\PartnerKind;
use App\Http\Requests\PartnerRequest;
use App\Models\AuditLog;
use App\Models\Partner;
use App\Models\PurchaseOrder;
use App\Models\SalesOrder;
use App\Models\User;
use Illuminate\Support\Facades\Validator;
use Inertia\Testing\AssertableInertia as Assert;

/** 取引先の登録・編集・無効化（入力検証・一意・種別の変更・操作履歴・トランザクション。docs/spec.md §3・§8） */
function partnerInput(array $overrides = []): array
{
    return [
        'code' => 'C-0101',
        'name' => 'サンプル商事',
        'kind' => 'customer',
        'postal_code' => '0000001',
        'address' => 'デモ県サンプル市中央1-2-3',
        'phone' => '000-0000-0001',
        'email' => 'info@example.com',
        ...$overrides,
    ];
}

function partnerAuditLogs(): array
{
    return AuditLog::query()->where('target_type', 'partner')->orderBy('id')->get(['action', 'target_id', 'summary', 'user_id'])->toArray();
}

describe('登録', function () {
    test('staff は登録でき、郵便番号はハイフンなし・コードは大文字で保存し、操作履歴（create）が残る', function () {
        $staff = User::factory()->staff()->create();

        $response = $this->actingAs($staff)->post(route('partners.store'), partnerInput(['code' => 'c-0101', 'postal_code' => '000-0001']));

        $partner = Partner::query()->sole();
        $response->assertRedirect(route('partners.show', $partner));
        expect($partner->only(['code', 'name', 'kind', 'postal_code', 'address', 'phone', 'email', 'is_active']))->toBe([
            'code' => 'C-0101',
            'name' => 'サンプル商事',
            'kind' => PartnerKind::Customer,
            'postal_code' => '0000001',
            'address' => 'デモ県サンプル市中央1-2-3',
            'phone' => '000-0000-0001',
            'email' => 'info@example.com',
            'is_active' => true,
        ]);
        expect(partnerAuditLogs())->toBe([[
            'action' => 'create',
            'target_id' => (string) $partner->id,
            'summary' => '取引先 C-0101「サンプル商事」を登録しました',
            'user_id' => $staff->id,
        ]]);
    });

    test('郵便番号はハイフンなし・全角も受け付け、電話番号の全角は半角にする。任意の項目は空なら null', function () {
        $admin = User::factory()->admin()->create();

        $this->actingAs($admin)->post(route('partners.store'), partnerInput(['postal_code' => '０００１２３４', 'phone' => '０３－１２３４－５６７８']));
        $this->actingAs($admin)->post(route('partners.store'), partnerInput(['code' => 'C-0102', 'postal_code' => '', 'address' => '  ', 'phone' => '', 'email' => '']));

        expect(Partner::query()->where('code', 'C-0101')->sole()->only(['postal_code', 'phone']))->toBe(['postal_code' => '0001234', 'phone' => '03-1234-5678']);
        expect(Partner::query()->where('code', 'C-0102')->sole()->only(['postal_code', 'address', 'phone', 'email']))
            ->toBe(['postal_code' => null, 'address' => null, 'phone' => null, 'email' => null]);
    });

    test('取引先コードが重複するとエラーで、何も記録しない。小文字で書いても重複として扱う', function () {
        $staff = User::factory()->staff()->create();
        Partner::factory()->create(['code' => 'C-0101']);

        $this->actingAs($staff)->from(route('partners.create'))
            ->post(route('partners.store'), partnerInput(['code' => 'c-0101']))
            ->assertRedirect(route('partners.create'))
            ->assertSessionHasErrors(['code' => '取引先コード「C-0101」はすでに登録されています']);

        expect(Partner::query()->count())->toBe(1);
        expect(partnerAuditLogs())->toBe([]);
    });

    test('入力エラーは項目ごとの日本語のエラーで、登録しない', function (array $input, string $field, string $message) {
        $staff = User::factory()->staff()->create();

        $this->actingAs($staff)->post(route('partners.store'), partnerInput($input))
            ->assertSessionHasErrors([$field => $message]);

        expect(Partner::query()->count())->toBe(0);
        expect(partnerAuditLogs())->toBe([]);
    })->with([
        'コードが空' => [['code' => ''], 'code', '取引先コード を入力してください'],
        'コードの文字種' => [['code' => 'C 0001'], 'code', '取引先コードは半角英数字・ハイフン（-）・アンダースコア（_）で入力してください（先頭は英数字）'],
        'コードの先頭がハイフン' => [['code' => '-C0001'], 'code', '取引先コードは半角英数字・ハイフン（-）・アンダースコア（_）で入力してください（先頭は英数字）'],
        'コードが長すぎる' => [['code' => str_repeat('C', 21)], 'code', '取引先コード は 20 文字以内で入力してください'],
        '名前が空' => [['name' => ''], 'name', '取引先名 を入力してください'],
        '名前が長すぎる' => [['name' => str_repeat('あ', 101)], 'name', '取引先名 は 100 文字以内で入力してください'],
        '名前に改行' => [['name' => "サンプル\n商事"], 'name', '取引先名 に改行・タブなどの制御文字は使えません'],
        '名前にタブ' => [['name' => "サンプル\t商事"], 'name', '取引先名 に改行・タブなどの制御文字は使えません'],
        '名前に表示方向の制御' => [['name' => "サンプル\u{202E}商事"], 'name', '取引先名 に改行・タブなどの制御文字は使えません'],
        '住所に行区切り' => [['address' => "デモ県\u{2028}サンプル市"], 'address', '住所 に改行・タブなどの制御文字は使えません'],
        '住所が長すぎる' => [['address' => str_repeat('あ', 201)], 'address', '住所 は 200 文字以内で入力してください'],
        '種別が不正' => [['kind' => 'vendor'], 'kind', '種別を選択してください'],
        '種別が空' => [['kind' => ''], 'kind', '種別を選択してください'],
        '郵便番号が 6 桁' => [['postal_code' => '123-456'], 'postal_code', '郵便番号は7桁の数字で入力してください（例 123-4567）'],
        '郵便番号に記号' => [['postal_code' => '123_4567'], 'postal_code', '郵便番号は7桁の数字で入力してください（例 123-4567）'],
        '郵便番号に文字' => [['postal_code' => '123-456a'], 'postal_code', '郵便番号は7桁の数字で入力してください（例 123-4567）'],
        '電話番号の桁数' => [['phone' => '03-1234-567'], 'phone', '電話番号は 0 から始まる 10〜11 桁の数字で入力してください（ハイフンは任意。例 03-1234-5678）'],
        '電話番号が 0 始まりでない' => [['phone' => '3-1234-56789'], 'phone', '電話番号は 0 から始まる 10〜11 桁の数字で入力してください（ハイフンは任意。例 03-1234-5678）'],
        '電話番号に文字' => [['phone' => '03-1234-567a'], 'phone', '電話番号は 0 から始まる 10〜11 桁の数字で入力してください（ハイフンは任意。例 03-1234-5678）'],
        'メールの形式' => [['email' => 'info@'], 'email', 'メールアドレスの形式が正しくありません（例 info@example.com）'],
        'メールのドメインに「.」がない' => [['email' => 'info@example'], 'email', 'メールアドレスの形式が正しくありません（例 info@example.com）'],
        'メールに引用符と空白' => [['email' => '"a b"@example.com'], 'email', 'メールアドレスの形式が正しくありません（例 info@example.com）'],
        'メールにコメント' => [['email' => 'a(c)@example.com'], 'email', 'メールアドレスの形式が正しくありません（例 info@example.com）'],
        'メールに行区切り（途中）' => [['email' => "info\u{2028}@example.com"], 'email', 'メールアドレス に改行・タブなどの制御文字は使えません'],
        'メールのドメインに行区切り' => [['email' => "a@b.c\u{2028}om"], 'email', 'メールアドレス に改行・タブなどの制御文字は使えません'],
        '文字列でない値' => [['name' => ['a']], 'name', '取引先名 は文字列で入力してください'],
    ]);

    test('メールの末尾の行区切り（U+2028）は、前後の空白と同じく取り除いて保存する。規則そのものは制御文字として拒否する', function () {
        $staff = User::factory()->staff()->create();

        $this->actingAs($staff)->post(route('partners.store'), partnerInput(['email' => "a@b.com\u{2028}"]))->assertSessionHasNoErrors();

        expect(Partner::query()->sole()->email)->toBe('a@b.com');
        // ミドルウェア（TrimStrings）を通らない値でも、検証の規則で拒否する
        $rules = (new PartnerRequest)->rules();
        expect(Validator::make(['email' => "a@b.com\u{2028}"], ['email' => $rules['email']])->fails())->toBeTrue();
        expect(Validator::make(['email' => 'a@b.com'], ['email' => $rules['email']])->fails())->toBeFalse();
    });
});

describe('編集', function () {
    test('編集すると変更点が操作履歴（update）に残る。変更がなければ記録しない', function () {
        $staff = User::factory()->staff()->create();
        $partner = Partner::factory()->create(partnerInput(['postal_code' => '0000001']));

        $this->actingAs($staff)->put(route('partners.update', $partner), partnerInput(['name' => 'サンプル物産', 'postal_code' => '0000002', 'email' => '']))
            ->assertRedirect(route('partners.show', $partner));

        expect($partner->refresh()->only(['name', 'postal_code', 'email']))->toBe(['name' => 'サンプル物産', 'postal_code' => '0000002', 'email' => null]);
        expect(partnerAuditLogs())->toBe([[
            'action' => 'update',
            'target_id' => (string) $partner->id,
            'summary' => '取引先 C-0101 を更新しました（取引先名: サンプル商事 → サンプル物産、郵便番号: 000-0001 → 000-0002、メールアドレス: info@example.com → （なし））',
            'user_id' => $staff->id,
        ]]);

        // 同じ値でもう一度保存しても、履歴は増えない
        $this->actingAs($staff)->put(route('partners.update', $partner), partnerInput(['name' => 'サンプル物産', 'postal_code' => '000-0002', 'email' => '']))
            ->assertRedirect(route('partners.show', $partner));
        expect(partnerAuditLogs())->toHaveCount(1);
    });

    test('編集で他の取引先と同じコードにするとエラー。自分のコードのままなら一意のエラーにしない', function () {
        $staff = User::factory()->staff()->create();
        Partner::factory()->create(['code' => 'C-0001']);
        $partner = Partner::factory()->create(partnerInput());

        $this->actingAs($staff)->put(route('partners.update', $partner), partnerInput(['code' => 'c-0001']))
            ->assertSessionHasErrors(['code' => '取引先コード「C-0001」はすでに登録されています']);
        expect($partner->refresh()->code)->toBe('C-0101');

        $this->actingAs($staff)->put(route('partners.update', $partner), partnerInput(['name' => '別名']))
            ->assertSessionHasNoErrors();
        expect($partner->refresh()->name)->toBe('別名');
    });

    test('存在しない取引先の編集は 404', function () {
        $staff = User::factory()->staff()->create();

        $this->actingAs($staff)->put('/partners/999999', partnerInput())->assertNotFound();
        $this->actingAs($staff)->get('/partners/999999/edit')->assertNotFound();
    });

    test('詳細・編集の画面に取引先を渡す（郵便番号は 123-4567 の形。詳細は受注・発注の件数も）', function () {
        $staff = User::factory()->staff()->create();
        $partner = Partner::factory()->create(partnerInput(['postal_code' => '0000001', 'phone' => null]));
        SalesOrder::factory()->count(2)->create(['customer_id' => $partner->id]);

        $this->actingAs($staff)->get(route('partners.show', $partner))
            ->assertInertia(fn (Assert $page) => $page
                ->component('partners/Show')
                ->where('partner.postalCode', '000-0001')
                ->where('partner.phone', '')
                ->where('partner.salesOrdersCount', 2)
                ->where('partner.purchaseOrdersCount', 0));

        $this->actingAs($staff)->get(route('partners.edit', $partner))
            ->assertInertia(fn (Assert $page) => $page
                ->component('partners/Edit')
                ->where('partner.code', 'C-0101')
                ->where('partner.postalCode', '000-0001')
                ->has('kindOptions', 3));
    });
});

describe('種別の変更（使用中の受注・発注と矛盾させない）', function () {
    test('受注のある顧客を仕入先に変えると、種別のエラーで拒否する（何も書き換えない）', function () {
        $staff = User::factory()->staff()->create();
        $partner = Partner::factory()->create(partnerInput());
        SalesOrder::factory()->count(3)->create(['customer_id' => $partner->id]);

        $this->actingAs($staff)->put(route('partners.update', $partner), partnerInput(['kind' => 'supplier', 'name' => '別名']))
            ->assertSessionHasErrors(['kind' => '受注（3 件）で使われているため、顧客の役割を外せません（「顧客」か「顧客・仕入先」にしてください）']);

        expect($partner->refresh()->only(['kind', 'name']))->toBe(['kind' => PartnerKind::Customer, 'name' => 'サンプル商事']);
        expect(partnerAuditLogs())->toBe([]);
    });

    test('発注のある仕入先を顧客に変えると拒否する。顧客・仕入先（both）から外すときも同じ', function () {
        $staff = User::factory()->staff()->create();
        $supplier = Partner::factory()->create(partnerInput(['code' => 'S-0101', 'kind' => 'supplier']));
        PurchaseOrder::factory()->create(['supplier_id' => $supplier->id]);
        $both = Partner::factory()->create(partnerInput(['code' => 'B-0101', 'kind' => 'both']));
        SalesOrder::factory()->create(['customer_id' => $both->id]);
        PurchaseOrder::factory()->create(['supplier_id' => $both->id]);

        $this->actingAs($staff)->put(route('partners.update', $supplier), partnerInput(['code' => 'S-0101', 'kind' => 'customer']))
            ->assertSessionHasErrors(['kind' => '発注（1 件）で使われているため、仕入先の役割を外せません（「仕入先」か「顧客・仕入先」にしてください）']);
        $this->actingAs($staff)->put(route('partners.update', $both), partnerInput(['code' => 'B-0101', 'kind' => 'customer']))
            ->assertSessionHasErrors('kind');
        $this->actingAs($staff)->put(route('partners.update', $both), partnerInput(['code' => 'B-0101', 'kind' => 'supplier']))
            ->assertSessionHasErrors('kind');

        expect($supplier->refresh()->kind)->toBe(PartnerKind::Supplier);
        expect($both->refresh()->kind)->toBe(PartnerKind::Both);
        expect(partnerAuditLogs())->toBe([]);
    });

    test('役割を足す変更（顧客 → 顧客・仕入先）と、受注・発注のない取引先の変更はできる', function () {
        $staff = User::factory()->staff()->create();
        $customer = Partner::factory()->create(partnerInput());
        SalesOrder::factory()->create(['customer_id' => $customer->id]);
        $unused = Partner::factory()->create(partnerInput(['code' => 'C-0102']));

        $this->actingAs($staff)->put(route('partners.update', $customer), partnerInput(['kind' => 'both']))->assertSessionHasNoErrors();
        $this->actingAs($staff)->put(route('partners.update', $unused), partnerInput(['code' => 'C-0102', 'kind' => 'supplier']))->assertSessionHasNoErrors();

        expect($customer->refresh()->kind)->toBe(PartnerKind::Both);
        expect($unused->refresh()->kind)->toBe(PartnerKind::Supplier);
        expect(array_column(partnerAuditLogs(), 'summary'))->toBe([
            '取引先 C-0101 を更新しました（種別: 顧客 → 顧客・仕入先）',
            '取引先 C-0102 を更新しました（種別: 顧客 → 仕入先）',
        ]);
    });
});

describe('無効化・再有効化', function () {
    test('admin は無効化・再有効化でき、操作履歴（deactivate／reactivate）が残る。同じ状態への操作は記録しない', function () {
        $admin = User::factory()->admin()->create();
        $partner = Partner::factory()->create(partnerInput());

        $this->actingAs($admin)->post(route('partners.deactivate', $partner))->assertRedirect(route('partners.show', $partner));
        expect($partner->refresh()->is_active)->toBeFalse();
        $this->actingAs($admin)->post(route('partners.deactivate', $partner))->assertRedirect(route('partners.show', $partner));
        $this->actingAs($admin)->post(route('partners.reactivate', $partner))->assertRedirect(route('partners.show', $partner));
        expect($partner->refresh()->is_active)->toBeTrue();

        expect(partnerAuditLogs())->toBe([
            ['action' => 'deactivate', 'target_id' => (string) $partner->id, 'summary' => '取引先 C-0101「サンプル商事」を無効にしました', 'user_id' => $admin->id],
            ['action' => 'reactivate', 'target_id' => (string) $partner->id, 'summary' => '取引先 C-0101「サンプル商事」を再度有効にしました', 'user_id' => $admin->id],
        ]);
    });
});

describe('トランザクション（操作履歴の保存に失敗したら取引先の変更も巻き戻す）', function () {
    beforeEach(function () {
        // 操作履歴の保存だけを失敗させる
        AuditLog::creating(function () {
            throw new RuntimeException('操作履歴の保存に失敗（テスト）');
        });
        $this->withoutExceptionHandling();
    });

    afterEach(function () {
        AuditLog::flushEventListeners();
    });

    test('登録: 取引先は作られない', function () {
        $staff = User::factory()->staff()->create();

        expect(fn () => $this->actingAs($staff)->post(route('partners.store'), partnerInput()))
            ->toThrow(RuntimeException::class, '操作履歴の保存に失敗（テスト）');

        expect(Partner::query()->count())->toBe(0);
    });

    test('編集: 取引先は変更前の値のまま', function () {
        $staff = User::factory()->staff()->create();
        $partner = Partner::factory()->create(partnerInput());

        expect(fn () => $this->actingAs($staff)->put(route('partners.update', $partner), partnerInput(['name' => '別名', 'kind' => 'both'])))
            ->toThrow(RuntimeException::class);

        expect($partner->refresh()->only(['name', 'kind']))->toBe(['name' => 'サンプル商事', 'kind' => PartnerKind::Customer]);
    });

    test('無効化: 取引先は有効のまま', function () {
        $admin = User::factory()->admin()->create();
        $partner = Partner::factory()->create(partnerInput());

        expect(fn () => $this->actingAs($admin)->post(route('partners.deactivate', $partner)))
            ->toThrow(RuntimeException::class);

        expect($partner->refresh()->is_active)->toBeTrue();
        expect(AuditLog::query()->count())->toBe(0);
    });
});
