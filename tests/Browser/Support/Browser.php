<?php

namespace Tests\Browser\Support;

use App\Enums\PartnerKind;
use App\Enums\Role;
use App\Enums\StockMovementType;
use App\Models\Partner;
use App\Models\Product;
use App\Models\StockMovement;
use App\Models\User;

/**
 * ブラウザテスト（tests/Browser）の部品: テストデータの準備と、画面の値の読み取り。
 *
 * - データはテストごとに空の DB（テスト用 DB testing。CommitsTransactions で前後に全テーブルを空にする）に、そのテストが使う分だけ作る。
 *   ほかのテストの影響を受けないので、件数・金額は固定の値で比べる
 * - ログインは画面から行う（ログイン画面に入力して送信）。ログインの試行回数の制限（メールアドレス＋IP ごとに 1 分 5 回・IP ごとに
 *   1 分 20 回）は無効にしない。数える先はキャッシュ（テストは array。テストごとに作り直すので空から始まる）で、1 つのテストの
 *   ログインは多くても 3 回なので上限に当たらない
 * - 画面の値は、要素の表示されている文字（innerText の空白を詰めたもの）を、待ち合わせ付きの assertScript で比べる
 *   （表示が変わるまで Pest の上限の時間だけ繰り返し確かめる。固定時間の待ちは使わない）
 */
final class Browser
{
    /** テスト用ユーザーのパスワード（UserFactory の既定値） */
    public const string PASSWORD = 'password';

    /** ページの移動（読み込みの完了まで）の上限（ミリ秒） */
    public const int NAVIGATION_TIMEOUT_MS = 15_000;

    /** ロールごとのテスト用ユーザー（メールアドレスは "browser-<ロール>@example.com"。デモ用アカウントのアドレスとは別にする） */
    public static function user(Role $role, ?string $name = null): User
    {
        return User::factory()->role($role)->create([
            'name' => $name ?? "テスト{$role->label()}",
            'email' => "browser-{$role->value}@example.com",
        ]);
    }

    /** 画面からログインし、ダッシュボードが表示されたページを返す */
    public static function login(User $user): mixed
    {
        $page = visit('/login');
        $page->fill('email', $user->email)
            ->fill('password', self::PASSWORD)
            ->click('@login-button');
        $page->assertPathIs('/dashboard');
        self::assertText($page, '[data-test="header-role"]', $user->role->label());

        return $page;
    }

    /**
     * ページを path に移動する（読み込みの完了まで待つ）。
     * Pest の navigate は、表示を待つ確認と同じく「1 回 1 秒の試行を上限まで繰り返す」形で動くため、読み込みに 1 秒より
     * かかると同じ URL への移動を重ねて出し「Navigation … is interrupted by another navigation」で落ちる（負荷の高いときに
     * 実際に起きた）。移動の時間の上限をここで明示して、1 回の試行で読み込みを待ち切る
     */
    public static function open(mixed $page, string $path): void
    {
        $page->navigate($path, ['timeout' => self::NAVIGATION_TIMEOUT_MS]);
    }

    /** @param  array<string, mixed>  $attributes */
    public static function product(array $attributes = []): Product
    {
        return Product::factory()->create([
            'category' => 'テスト',
            'unit' => '個',
            'sales_price' => 1200,
            'purchase_price' => 800,
            'reorder_point' => 0,
            ...$attributes,
        ]);
    }

    /** 商品の在庫を qty にする（入庫を 1 件記録する。0 なら何もしない） */
    public static function stock(Product $product, int $qty): void
    {
        if ($qty > 0) {
            StockMovement::factory()->create(['product_id' => $product->id, 'type' => StockMovementType::In, 'quantity' => $qty, 'reason' => '初期在庫（テスト）']);
        }
    }

    public static function customer(): Partner
    {
        return Partner::factory()->customer()->create(['code' => 'C-0001', 'name' => 'サンプル商店']);
    }

    public static function supplier(): Partner
    {
        return Partner::factory()->create(['code' => 'S-0001', 'name' => 'サンプル卸', 'kind' => PartnerKind::Supplier]);
    }

    /**
     * 最初に見つかった要素の表示されている文字（空白を詰めたもの）が expected になるまで待つ。
     * 要素がない間は null として比べる
     */
    public static function assertText(mixed $page, string $selector, ?string $expected): void
    {
        $page->assertScript(self::textOf($selector), $expected);
    }

    /**
     * 要素（selector に合う全部。表の行など）それぞれの、セル（td・th）の表示されている文字の並びが expected になるまで待つ。
     * columns を渡したら、その位置（0 から）のセルだけを比べる（日時など、値の決まらない列を除く）
     *
     * @param  list<list<string>>  $expected
     * @param  list<int>|null  $columns
     */
    public static function assertRows(mixed $page, string $selector, array $expected, ?array $columns = null): void
    {
        $selectorJson = json_encode($selector, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
        $columnsJson = json_encode($columns, JSON_THROW_ON_ERROR);
        $page->assertScript(
            'function () { const columns = '.$columnsJson.'; return JSON.stringify([...document.querySelectorAll('.$selectorJson.')]'
            .'.map(function (row) { return [...row.querySelectorAll("td, th")]'
            .'.map(function (cell) { return cell.innerText.replace(/\s+/g, " ").trim(); })'
            .'.filter(function (text, i) { return columns === null || columns.includes(i); }); })); }',
            json_encode($expected, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
        );
    }

    /**
     * 最初に見つかった要素の表示されている文字（空白を詰めたもの）が、parts をすべて含むまで待つ
     *
     * @param  list<string>  $parts
     */
    public static function assertTextContains(mixed $page, string $selector, array $parts): void
    {
        $selectorJson = json_encode($selector, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
        $partsJson = json_encode($parts, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
        // 見つからない部分の一覧（すべて含めば []。要素がなければ null）
        $page->assertScript(
            'function () { const el = document.querySelector('.$selectorJson.'); if (el === null) { return null; } '
            .'const text = el.innerText.replace(/\s+/g, " ").trim(); '
            .'return JSON.stringify('.$partsJson.'.filter(function (part) { return ! text.includes(part); })); }',
            '[]',
        );
    }

    /** 文書の幅が画面の幅に収まっている（横スクロールが出ない） */
    public static function assertNoHorizontalScroll(mixed $page): void
    {
        $page->assertScript('function () { return document.documentElement.scrollWidth <= document.documentElement.clientWidth; }', true);
    }

    /** 表示されている文字を返す JavaScript の関数（空白を詰める。要素がなければ null） */
    private static function textOf(string $selector): string
    {
        $selectorJson = json_encode($selector, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);

        return 'function () { const el = document.querySelector('.$selectorJson.'); return el === null ? null : el.innerText.replace(/\s+/g, " ").trim(); }';
    }
}
