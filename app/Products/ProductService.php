<?php

namespace App\Products;

use App\Models\AuditLog;
use App\Models\Product;
use App\Models\User;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * 商品の更新系（登録・編集・無効化／再有効化・CSV 取込）。
 *
 * - 権限はルート（can:）と Policy（Form Request・コントローラー）で検査済みの前提。入力は検証済み（ProductValidation）
 * - 更新と操作履歴（audit_logs）は同じトランザクションで書く（docs/spec.md §3）
 *
 * @phpstan-type ProductAttributes array{code: string, name: string, category: string, unit: string, sales_price: int, purchase_price: int, reorder_point: int}
 *
 * @phpstan-import-type ImportRow from ProductImportParser
 */
final class ProductService
{
    /** 操作履歴の target_type */
    public const string TARGET_TYPE = 'product';

    /** 取込の書き込みを 1 文にまとめる行数（1 行あたり 10 個前後のパラメータ。MySQL の上限 65,535 個に十分収まる） */
    public const int IMPORT_CHUNK_SIZE = 500;

    /**
     * 登録
     *
     * @param  ProductAttributes  $attributes
     *
     * @throws ValidationException 商品コードが登録済み（検証の後に別の操作で登録された場合）
     */
    public function create(User $actor, array $attributes): Product
    {
        try {
            return DB::transaction(function () use ($actor, $attributes) {
                $product = Product::create($attributes);
                $this->log($actor, 'create', $product->id, "商品 {$product->code}「{$product->name}」を登録しました");

                return $product;
            });
        } catch (UniqueConstraintViolationException) {
            throw self::duplicateCode($attributes['code']);
        }
    }

    /**
     * 編集（商品コードも変更できる）。変更がなければ何もしない（操作履歴も残さない）
     *
     * @param  ProductAttributes  $attributes
     * @return bool 変更したか
     *
     * @throws ValidationException 商品コードが登録済み
     */
    public function update(User $actor, Product $product, array $attributes): bool
    {
        try {
            return DB::transaction(function () use ($actor, $product, $attributes) {
                $current = Product::query()->lockForUpdate()->findOrFail($product->id);
                $changes = self::describeChanges(self::pick($current), $attributes);
                if ($changes === []) {
                    return false;
                }
                $current->update($attributes);
                $this->log($actor, 'update', $current->id, "商品 {$current->code} を更新しました（".implode('、', $changes).'）');

                return true;
            });
        } catch (UniqueConstraintViolationException) {
            throw self::duplicateCode($attributes['code']);
        }
    }

    /**
     * 無効化（is_active = false）・再有効化。すでにその状態なら何もしない
     *
     * @return bool 変更したか
     */
    public function setActive(User $actor, Product $product, bool $active): bool
    {
        return DB::transaction(function () use ($actor, $product, $active) {
            $current = Product::query()->lockForUpdate()->findOrFail($product->id);
            if ($current->is_active === $active) {
                return false;
            }
            $current->is_active = $active;
            $current->save();
            $this->log(
                $actor,
                $active ? 'reactivate' : 'deactivate',
                $current->id,
                "商品 {$current->code}「{$current->name}」を".($active ? '再度有効に' : '無効に').'しました',
            );

            return true;
        });
    }

    /**
     * CSV 取込の書き込み（検証済みの行。ProductImportParser::parse の結果）。
     * code が既存なら更新、なければ新規（有効／無効は変えない）。1 トランザクションで行い、
     * 操作履歴は商品ごとの登録・更新と、件数の要約 1 件を残す
     *
     * @param  list<ImportRow>  $rows
     * @return array{created: int, updated: int, unchanged: int}
     *
     * @throws ValidationException 取込の途中で同じ商品コードが別の操作で登録された
     */
    public function import(User $actor, array $rows, string $fileName): array
    {
        try {
            return DB::transaction(function () use ($actor, $rows, $fileName) {
                $codes = array_map(fn (array $row) => $row['data']['code'], $rows);
                // 更新し得る既存の商品を、id の順にまとめてロックする（CSV の並び順に 1 行ずつロックするとデッドロックしうる）
                $existing = Product::query()->whereIn('code', $codes)->orderBy('id')->lockForUpdate()->get()
                    ->keyBy(fn (Product $p) => strtoupper($p->code));

                $toCreate = [];
                $toUpdate = [];
                $unchanged = 0;
                foreach ($rows as ['data' => $data]) {
                    $current = $existing->get($data['code']);
                    if ($current === null) {
                        $toCreate[] = $data;

                        continue;
                    }
                    $changes = self::describeChanges(self::pick($current), $data);
                    if ($changes === []) {
                        $unchanged++;

                        continue;
                    }
                    $toUpdate[] = ['id' => $current->id, 'data' => $data, 'changes' => $changes];
                }

                $now = now();
                // 更新は数百行ずつ 1 文にまとめる（1 行ずつだと DB との往復が行数分かかる）。id を指定した upsert なので
                // 既存の行の UPDATE になる（INSERT … ON DUPLICATE KEY UPDATE）。is_active・created_at は更新しない
                foreach (array_chunk($toUpdate, self::IMPORT_CHUNK_SIZE) as $chunk) {
                    Product::query()->upsert(
                        array_map(fn (array $u) => ['id' => $u['id'], ...$u['data'], 'created_at' => $now, 'updated_at' => $now], $chunk),
                        ['id'],
                        ['code', 'name', 'category', 'unit', 'sales_price', 'purchase_price', 'reorder_point', 'updated_at'],
                    );
                }
                // 新規は is_active を指定しない（DB の既定値 true）
                foreach (array_chunk($toCreate, self::IMPORT_CHUNK_SIZE) as $chunk) {
                    Product::query()->insert(array_map(fn (array $data) => [...$data, 'created_at' => $now, 'updated_at' => $now], $chunk));
                }
                $created = $toCreate === []
                    ? collect()
                    : Product::query()->whereIn('code', array_column($toCreate, 'code'))->orderBy('id')->get(['id', 'code', 'name']);

                $summary = ['created' => count($toCreate), 'updated' => count($toUpdate), 'unchanged' => $unchanged];

                // 商品ごとの履歴（画面での登録・編集と同じ形。CSV 取込によるものと分かるようにする）と、件数の要約 1 件
                $logs = [];
                foreach ($created as $p) {
                    $logs[] = self::logRow($actor, 'create', (string) $p->id, "商品 {$p->code}「{$p->name}」を登録しました（CSV 取込）", $now);
                }
                foreach ($toUpdate as $u) {
                    $logs[] = self::logRow($actor, 'update', (string) $u['id'], "商品 {$u['data']['code']} を更新しました（CSV 取込: ".implode('、', $u['changes']).'）', $now);
                }
                $logs[] = self::logRow(
                    $actor,
                    'import',
                    null,
                    "商品を CSV で取り込みました（{$fileName}: 新規 {$summary['created']} 件・更新 {$summary['updated']} 件・変更なし {$summary['unchanged']} 件）",
                    $now,
                );
                foreach (array_chunk($logs, self::IMPORT_CHUNK_SIZE) as $chunk) {
                    AuditLog::query()->insert($chunk);
                }

                return $summary;
            });
        } catch (UniqueConstraintViolationException) {
            throw ValidationException::withMessages([
                'file' => '取込中に同じ商品コードが別の操作で登録されました。もう一度取り込んでください',
            ]);
        }
    }

    /**
     * 変更点の説明（操作履歴の summary 用）。変更がなければ空配列
     *
     * @param  ProductAttributes  $before
     * @param  ProductAttributes  $after
     * @return list<string>
     */
    public static function describeChanges(array $before, array $after): array
    {
        $changes = [];
        foreach (ProductValidation::LABELS as $field => $label) {
            if ($before[$field] !== $after[$field]) {
                $changes[] = "{$label}: {$before[$field]} → {$after[$field]}";
            }
        }

        return $changes;
    }

    /** @return ProductAttributes */
    private static function pick(Product $product): array
    {
        return [
            'code' => $product->code,
            'name' => $product->name,
            'category' => $product->category,
            'unit' => $product->unit,
            'sales_price' => $product->sales_price,
            'purchase_price' => $product->purchase_price,
            'reorder_point' => $product->reorder_point,
        ];
    }

    private function log(User $actor, string $action, int $productId, string $summary): void
    {
        AuditLog::create([
            'user_id' => $actor->id,
            'action' => $action,
            'target_type' => self::TARGET_TYPE,
            'target_id' => (string) $productId,
            'summary' => $summary,
        ]);
    }

    /** @return array{user_id: int, action: string, target_type: string, target_id: string|null, summary: string, created_at: mixed} */
    private static function logRow(User $actor, string $action, ?string $targetId, string $summary, mixed $now): array
    {
        return [
            'user_id' => $actor->id,
            'action' => $action,
            'target_type' => self::TARGET_TYPE,
            'target_id' => $targetId,
            'summary' => $summary,
            'created_at' => $now,
        ];
    }

    private static function duplicateCode(string $code): ValidationException
    {
        return ValidationException::withMessages(['code' => "商品コード「{$code}」はすでに登録されています"]);
    }
}
