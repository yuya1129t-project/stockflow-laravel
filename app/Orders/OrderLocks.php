<?php

namespace App\Orders;

use App\Enums\PartnerRole;
use App\Models\Partner;
use App\Models\Product;
use App\Stock\StockTransaction;
use LogicException;

/**
 * 受注・発注の作成・編集で、取引先と明細の商品の行を FOR SHARE でロックしてから検査する（受注・発注で共通）。
 *
 * - 取引先: 種別（docs/spec.md §3: 顧客は customer か both、仕入先は supplier か both）と有効を、ロックした後に読み直して
 *   検査する。取引先の編集（App\Services\Partners\PartnerService::update）は行を FOR UPDATE でロックしてから受注・発注の
 *   件数を数えるので、「伝票の作成」と「その役割を外す種別の変更」が同時に走っても、片方は他方の確定を待ってから判定する
 *   （ロックせずに読むと、種別の変更の確定前の値で検査を通り、仕入先ではない取引先に発注が付く。PR #21 のレビューで再現）
 * - 商品: 存在と有効を検査する（無効な商品は新規の受注・発注で選べない。spec §3）。商品の無効化（UPDATE）と競合する。
 *   ロックは id の順にまとめて 1 回で取る（在庫の記録 StockTransaction::lockProducts と同じ順番。デッドロックを避ける）。
 *   明細の INSERT の外部キー検査も商品の行に共有ロックを取るが、先に全商品を id の順に持っておけば、入力の順番で
 *   INSERT しても新たな待ちは生じない
 *
 * 注意（受注の出荷・発注の入荷など、在庫を記録するトランザクション）:
 * lockProductsForLines（FOR SHARE）は、在庫の記録（StockTransaction::run の中の lockProducts／record。FOR UPDATE）と
 * 同じトランザクションで併用しない。FOR SHARE を持った 2 本が、同じ商品で FOR UPDATE への引き上げを互いに待って
 * デッドロックする。StockTransaction::run の中で呼ぶと例外にしている。在庫を記録するトランザクションで商品の有効などを
 * 確かめたいときは、StockTransaction::lockProducts(全商品) の結果（StockProduct の isActive・code・name）を使う
 */
final class OrderLocks
{
    /**
     * 取引先の行を FOR SHARE でロックし、役割（顧客・仕入先）と有効を検査する。
     * 問題があれば画面に出すメッセージ（string）を返す
     */
    public static function lockPartnerForRole(int $partnerId, PartnerRole $role): Partner|string
    {
        $label = $role->label();
        $partner = Partner::query()->whereKey($partnerId)->sharedLock()->first(['id', 'code', 'name', 'kind', 'is_active']);
        if ($partner === null) {
            return "{$label}が見つかりません";
        }
        if (! $partner->kind->hasRole($role)) {
            return "{$partner->code}「{$partner->name}」は{$label}ではありません（種別が「{$label}」か「顧客・仕入先」の取引先を選んでください）";
        }
        if (! $partner->is_active) {
            return "{$partner->code}「{$partner->name}」は無効です。別の{$label}を選んでください";
        }

        return $partner;
    }

    /**
     * 明細の商品の行を id の順に FOR SHARE でロックして読む（存在しない商品は含まれない）。
     * 伝票の作成・編集（在庫を記録しないトランザクション）専用。StockTransaction::run の中では例外（クラスの説明を参照）
     *
     * @param  list<int>  $productIds
     * @return array<int, Product> 商品 ID → 商品（id・code・name・unit・is_active）
     */
    public static function lockProductsForLines(array $productIds): array
    {
        if (StockTransaction::inProgress()) {
            throw new LogicException('lockProductsForLines（FOR SHARE）は在庫を記録するトランザクションでは使えません（ロックの引き上げでデッドロックするため）。StockTransaction::lockProducts の結果で検査してください');
        }
        $ids = array_values(array_unique($productIds));
        sort($ids);
        if ($ids === []) {
            return [];
        }

        return Product::query()
            ->whereKey($ids)
            ->orderBy('id')
            ->sharedLock()
            ->get(['id', 'code', 'name', 'unit', 'is_active'])
            ->keyBy('id')
            ->all();
    }

    /**
     * 明細の商品を検査する（存在しない・無効な商品は行ごとのエラー）。
     * allowInactive は無効でも認める商品（編集で、もとから明細にあった商品。spec §3 の「新規の」受注・発注の制限）
     *
     * @param  list<array{product_id: int, quantity: int, unit_price: int}>  $lines
     * @param  array<int, Product>  $products
     * @param  list<int>  $allowInactive
     * @return array<string, string> 項目（lines.N.product_id）→ メッセージ。問題がなければ空
     */
    public static function checkLineProducts(array $lines, array $products, array $allowInactive = []): array
    {
        $errors = [];
        foreach ($lines as $index => $line) {
            $product = $products[$line['product_id']] ?? null;
            if ($product === null) {
                $errors["lines.{$index}.product_id"] = '商品が見つかりません';
            } elseif (! $product->is_active && ! in_array($product->id, $allowInactive, true)) {
                $errors["lines.{$index}.product_id"] = "{$product->code}「{$product->name}」は無効になっているため選べません";
            }
        }

        return $errors;
    }
}
