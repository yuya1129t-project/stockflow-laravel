<?php

namespace Database\Seeders\Demo;

use App\Support\Tax;
use Carbon\CarbonImmutable;

/**
 * デモ用シードデータの生成（DB に書き込まない純粋な処理）。docs/spec.md §9
 *
 * Next.js 版（stockflow-next の prisma/seed-data.ts）を移植したもの。乱数（mulberry32）・シード・手順が同じなので、
 * 同じ基準日時なら Next.js 版と同じ件数・同じ中身のデータになる。
 *
 * - すべて架空。取引先名には必ず「デモ」か「サンプル」を含め、メールは example.com / example.jp、
 *   電話番号・郵便番号は 000 始まり（実在しない番号帯）にしている
 * - 乱数は固定シードなので、同じ基準日時なら毎回同じデータになる。日付は基準日（JST）からの相対日数で
 *   決めるため、いつ実行しても「過去約 90 日」に同じ形で分布する
 * - 入出庫は時系列でシミュレーションし、どの時点でも在庫が負にならないようにする
 *   （出荷時に在庫が足りない受注は出荷せず confirmed のまま残す）
 *
 * @phpstan-type Line array{product_id: int, quantity: int, unit_price: int}
 * @phpstan-type SeedUser array{key: string, name: string, email: string, role: string}
 * @phpstan-type SeedPartner array{id: int, code: string, name: string, kind: string, postal_code: string, address: string, phone: string, email: string}
 * @phpstan-type SeedProduct array{id: int, code: string, name: string, category: string, unit: string, sales_price: int, purchase_price: int, reorder_point: int}
 * @phpstan-type SeedSalesOrder array{id: int, number: string, customer_id: int, order_date: string, due_date: string, status: string, note: string|null, subtotal: int, tax: int, total: int, created_at: CarbonImmutable, updated_at: CarbonImmutable, lines: list<Line>}
 * @phpstan-type SeedPurchaseOrder array{id: int, number: string, supplier_id: int, order_date: string, expected_date: string, status: string, note: string|null, subtotal: int, tax: int, total: int, created_at: CarbonImmutable, updated_at: CarbonImmutable, lines: list<Line>}
 * @phpstan-type SeedStockMovement array{product_id: int, type: string, quantity: int, reason: string, ref_type: string|null, ref_id: int|null, user: string, created_at: CarbonImmutable}
 * @phpstan-type SeedDocumentSequence array{kind: string, date: string, last_number: int}
 * @phpstan-type SeedData array{users: list<SeedUser>, partners: list<SeedPartner>, products: list<SeedProduct>, sales_orders: list<SeedSalesOrder>, purchase_orders: list<SeedPurchaseOrder>, stock_movements: list<SeedStockMovement>, document_sequences: list<SeedDocumentSequence>}
 */
final class DemoData
{
    /** デモアカウント共通のパスワード（デモ専用。画面と README に記載する前提の値） */
    public const string PASSWORD = 'demo-pass-1234';

    /** 日付を決めるタイムゾーン（docs/spec.md §1） */
    public const string TIMEZONE = 'Asia/Tokyo';

    /** シード期間（基準日から何日前までにデータを置くか） */
    public const int PERIOD_DAYS = 90;

    /** 乱数のシード（変えるとデータ全体が変わる。Next.js 版と同じ値） */
    private const int RANDOM_SEED = 20261011;

    /**
     * デモアカウント（admin／staff／viewer）。ログイン画面と README にも同じ値を表示する。
     * key は入出庫の記録者を指すための名前（ユーザーの ID は DB に入れたときに決まる）
     *
     * @var list<SeedUser>
     */
    public const array USERS = [
        ['key' => 'admin', 'name' => 'デモ管理者', 'email' => 'admin@example.com', 'role' => 'admin'],
        ['key' => 'staff', 'name' => 'デモ担当者', 'email' => 'staff@example.com', 'role' => 'staff'],
        ['key' => 'viewer', 'name' => 'デモ閲覧者', 'email' => 'viewer@example.com', 'role' => 'viewer'],
    ];

    private const array CUSTOMER_NAMES = [
        'デモ商店 あさひ店',
        'サンプル雑貨 みなと店',
        'デモ文具 ひがし店',
        'サンプルマート にし店',
        'デモ事務用品センター',
        'サンプル商会 きた店',
        'デモストア みなみ店',
        'サンプル文具館',
        'デモ生活雑貨 おか店',
        'サンプルオフィスサプライ',
        'デモ食品マート',
        'サンプル百貨 えき前店',
    ];

    private const array SUPPLIER_NAMES = [
        'デモ紙製品卸',
        'サンプル文具卸',
        'デモ梱包資材',
        'サンプル日用品卸',
        'デモ飲料卸',
        'サンプル食品卸',
        'デモ事務機器卸',
        'サンプル衛生用品',
    ];

    /**
     * [名前, カテゴリ, 単位, 仕入単価, 仕入先（SUPPLIER_NAMES の添字）]
     *
     * @var list<array{string, string, string, int, int}>
     */
    private const array PRODUCT_SOURCE = [
        ['ボールペン 黒 0.5mm 10本入', '文具', '箱', 480, 1],
        ['ボールペン 赤 0.5mm 10本入', '文具', '箱', 480, 1],
        ['シャープペンシル 0.5mm', '文具', '本', 180, 1],
        ['シャープ替芯 HB 0.5mm', '文具', '個', 70, 1],
        ['消しゴム 小', '文具', '個', 50, 1],
        ['蛍光ペン 5色セット', '文具', 'セット', 350, 1],
        ['油性マーカー 黒 太字', '文具', '本', 90, 1],
        ['大学ノート B5 5冊パック', '文具', 'パック', 380, 0],
        ['付箋 75×75mm 黄', '文具', '個', 120, 0],
        ['修正テープ 5mm', '文具', '個', 160, 1],
        ['A4コピー用紙 500枚', '事務用品', '冊', 380, 0],
        ['A4コピー用紙 2500枚', '事務用品', '箱', 1850, 0],
        ['クリアファイル A4 10枚入', '事務用品', '袋', 150, 6],
        ['フラットファイル A4 10冊入', '事務用品', 'パック', 520, 6],
        ['ホチキス針 No.10 1000本', '事務用品', '箱', 45, 6],
        ['ホチキス 中型', '事務用品', '個', 650, 6],
        ['ダブルクリップ 中 10個入', '事務用品', '箱', 120, 6],
        ['ゼムクリップ 100個入', '事務用品', '箱', 60, 6],
        ['電卓 12桁', '事務用品', '個', 1200, 6],
        ['はさみ 事務用', '事務用品', '個', 280, 6],
        ['段ボール箱 60サイズ 10枚', '梱包資材', '束', 900, 2],
        ['段ボール箱 80サイズ 10枚', '梱包資材', '束', 1300, 2],
        ['段ボール箱 100サイズ 10枚', '梱包資材', '束', 1800, 2],
        ['OPPテープ 48mm×100m', '梱包資材', '巻', 140, 2],
        ['クラフトテープ 50mm×50m', '梱包資材', '巻', 120, 2],
        ['エアクッション 1m×50m', '梱包資材', '巻', 1600, 2],
        ['宅配用ポリ袋 A4 100枚', '梱包資材', 'パック', 650, 2],
        ['ストレッチフィルム 500mm×300m', '梱包資材', '巻', 1100, 2],
        ['荷札 100枚', '梱包資材', '箱', 220, 2],
        ['結束バンド 200mm 100本', '梱包資材', '袋', 180, 2],
        ['ハンドソープ 詰替 450ml', '日用品', '個', 210, 7],
        ['アルコール除菌スプレー 500ml', '日用品', '本', 380, 7],
        ['ゴミ袋 45L 50枚', '日用品', '袋', 290, 3],
        ['ペーパータオル 200枚 5個パック', '日用品', 'パック', 480, 3],
        ['トイレットペーパー 12ロール', '日用品', 'パック', 420, 3],
        ['食器用洗剤 600ml', '日用品', '本', 160, 3],
        ['ニトリル手袋 M 100枚', '日用品', '箱', 700, 7],
        ['不織布マスク 50枚入', '日用品', '箱', 450, 7],
        ['ウェットティッシュ 80枚', '日用品', '個', 140, 3],
        ['雑巾 5枚組', '日用品', '組', 300, 3],
        ['ミネラルウォーター 500ml 24本', '飲料・食品', 'ケース', 1100, 4],
        ['緑茶 500ml 24本', '飲料・食品', 'ケース', 1700, 4],
        ['インスタントコーヒー 80g', '飲料・食品', '個', 520, 4],
        ['スティックシュガー 100本', '飲料・食品', '袋', 260, 5],
        ['紙コップ 205ml 100個', '飲料・食品', '袋', 320, 5],
        ['個包装クッキー 30個入', '飲料・食品', '箱', 650, 5],
        ['精米 5kg', '飲料・食品', '袋', 2100, 5],
        ['小麦粉 1kg', '飲料・食品', '袋', 190, 5],
        ['上白糖 業務用', '飲料・食品', 'kg', 230, 5],
        ['緑茶ティーバッグ 50袋', '飲料・食品', '箱', 480, 4],
    ];

    /**
     * よく売れる商品（PRODUCT_SOURCE の添字）。期首在庫を少なめにし、入荷済みの発注に含めないことで、
     * 最終的に発注点を下回る商品（ダッシュボード・通知の確認用）を作る
     */
    private const array FAST_MOVING_INDEXES = [2, 10, 23, 32, 40, 44];

    /** 取消にする発注・受注（受注日順の添字） */
    private const array PO_CANCELLED = [7, 19, 30];

    private const array SO_CANCELLED = [5, 18, 33, 47, 61, 72];

    private SeededRandom $rng;

    private CarbonImmutable $now;

    private string $today;

    private function __construct(CarbonImmutable $now)
    {
        $this->rng = new SeededRandom(self::RANDOM_SEED);
        $this->now = $now;
        $this->today = $now->setTimezone(self::TIMEZONE)->toDateString();
    }

    /**
     * デモ用シードデータを作る。
     *
     * @param  CarbonImmutable  $now  基準日時（通常は実行時刻）。日付はこの日（JST）から過去約 90 日に分布する
     * @return SeedData
     */
    public static function build(CarbonImmutable $now): array
    {
        return (new self($now))->generate();
    }

    /** @return SeedData */
    private function generate(): array
    {
        $rng = $this->rng;
        $partners = $this->buildPartners();
        $customers = array_values(array_filter($partners, fn (array $p) => $p['kind'] === 'customer'));
        $suppliers = array_values(array_filter($partners, fn (array $p) => $p['kind'] === 'supplier'));

        // 商品
        /** @var array<int, int> $supplierIdOfProduct */
        $supplierIdOfProduct = [];
        $products = [];
        foreach (self::PRODUCT_SOURCE as $i => [$name, $category, $unit, $purchasePrice, $supplierIndex]) {
            $id = $i + 1;
            $supplierIdOfProduct[$id] = $suppliers[$supplierIndex]['id'];
            // 売価は仕入単価の 1.3〜1.6 倍を 10 円単位に丸める（四捨五入）
            $salesPrice = intdiv($purchasePrice * $rng->int(130, 160) + 500, 1000) * 10;
            $products[] = [
                'id' => $id,
                'code' => sprintf('P-%04d', $id),
                'name' => $name,
                'category' => $category,
                'unit' => $unit,
                'sales_price' => $salesPrice,
                'purchase_price' => $purchasePrice,
                'reorder_point' => $rng->int(2, 8) * 5,
            ];
        }
        $isFastMoving = fn (int $productId): bool => in_array($productId - 1, self::FAST_MOVING_INDEXES, true);

        // 発注（40 件）。直近 6 件は入荷待ち（ordered／draft）になるよう最近の日付にする
        /** @var list<array{order: SeedPurchaseOrder, received_at: CarbonImmutable|null}> $plannedPurchases */
        $plannedPurchases = [];
        foreach ($this->orderDaysAgo(40, [0, 1, 2, 3, 5, 6]) as $i => $daysAgo) {
            $supplierId = $rng->pick($suppliers)['id'];
            $ymd = $this->dayOf($daysAgo);
            $leadDays = $rng->int(3, 7);
            if (in_array($i, self::PO_CANCELLED, true)) {
                $status = 'cancelled';
            } elseif ($daysAgo - $leadDays >= 1) {
                $status = 'received';
            } elseif ($daysAgo <= 1 && $rng->chance(0.5)) {
                $status = 'draft';
            } else {
                $status = 'ordered';
            }

            // 仕入先の商品から 1〜4 品目。入荷済みの発注にはよく売れる商品を入れない（発注点割れを残すため）。
            // 入荷待ちの発注にはよく売れる商品を優先して入れる
            $candidates = array_values(array_filter($products, fn (array $p) => $supplierIdOfProduct[$p['id']] === $supplierId));
            $pool = $status === 'received'
                ? array_values(array_filter($candidates, fn (array $p) => ! $isFastMoving($p['id'])))
                : $candidates;
            $picked = $rng->sampleWeighted(
                $pool,
                fn (array $p): int => $status !== 'received' && $isFastMoving($p['id']) ? 6 : 1,
                $rng->int(1, min(4, count($pool))),
            );
            usort($picked, fn (array $a, array $b) => $a['id'] <=> $b['id']);
            $lines = array_map(fn (array $p) => [
                'product_id' => $p['id'],
                'quantity' => $rng->int(2, 6) * 10,
                'unit_price' => $p['purchase_price'],
            ], $picked);

            $createdAt = $this->minDate($this->jstDateTime($ymd, 9, $rng->int(0, 59)), $this->now);
            $receivedAt = $status === 'received'
                ? $this->jstDateTime($this->addDays($ymd, $leadDays), 14, $rng->int(0, 59))
                : null;
            $plannedPurchases[] = [
                'order' => [
                    'id' => 0,
                    'number' => '',
                    'supplier_id' => $supplierId,
                    'order_date' => $ymd,
                    'expected_date' => $this->addDays($ymd, $leadDays),
                    'status' => $status,
                    'note' => $status === 'cancelled' ? '仕入先の在庫切れのため取消（デモ）' : null,
                    ...$this->sumLines($lines),
                    'created_at' => $createdAt,
                    'updated_at' => $receivedAt ?? $createdAt,
                    'lines' => $lines,
                ],
                'received_at' => $receivedAt,
            ];
        }

        // 受注（80 件）。今日の受注を 3 件入れ、月初でもダッシュボードの「今月」に出るようにする
        /** @var list<array{order: SeedSalesOrder, ship_at: CarbonImmutable|null}> $plannedSales */
        $plannedSales = [];
        foreach ($this->orderDaysAgo(80, [0, 0, 0, 1, 2, 3]) as $i => $daysAgo) {
            $customerId = $rng->pick($customers)['id'];
            $ymd = $this->dayOf($daysAgo);
            $shipDays = $rng->int(1, 4);
            if (in_array($i, self::SO_CANCELLED, true)) {
                $status = 'cancelled';
            } elseif ($daysAgo - $shipDays >= 1) {
                $status = 'shipped';
            } elseif ($daysAgo === 0 && $rng->chance(0.4)) {
                $status = 'draft';
            } else {
                $status = 'confirmed';
            }

            $picked = $rng->sampleWeighted(
                $products,
                fn (array $p): int => $isFastMoving($p['id']) ? 3 : 1,
                $rng->int(1, 4),
            );
            usort($picked, fn (array $a, array $b) => $a['id'] <=> $b['id']);
            $lines = array_map(fn (array $p) => [
                'product_id' => $p['id'],
                'quantity' => $isFastMoving($p['id']) ? $rng->int(3, 8) : $rng->int(1, 8),
                'unit_price' => $p['sales_price'],
            ], $picked);

            $createdAt = $this->minDate($this->jstDateTime($ymd, 10, $rng->int(0, 59)), $this->now);
            $order = [
                'id' => 0,
                'number' => '',
                'customer_id' => $customerId,
                'order_date' => $ymd,
                'due_date' => $this->addDays($ymd, $rng->int(3, 10)),
                'status' => $status,
                'note' => $status === 'cancelled' ? 'お客様都合により取消（デモ）' : null,
                ...$this->sumLines($lines),
                'created_at' => $createdAt,
                'updated_at' => $createdAt,
                'lines' => $lines,
            ];
            $plannedSales[] = [
                'order' => $order,
                'ship_at' => $status === 'shipped'
                    ? $this->jstDateTime($this->addDays($ymd, $shipDays), 11, $rng->int(0, 59))
                    : null,
            ];
        }

        // 伝票番号（日付ごとに 001 から）と ID（受注日順）を振る
        /** @var array<string, int> $sequences */
        $sequences = [];
        $assignNumber = function (string $kind, string $orderDate) use (&$sequences): string {
            $key = "{$kind}:{$orderDate}";
            $sequences[$key] = ($sequences[$key] ?? 0) + 1;

            return sprintf('%s-%s-%03d', $kind, str_replace('-', '', $orderDate), $sequences[$key]);
        };
        foreach ($plannedPurchases as $i => $plan) {
            $plannedPurchases[$i]['order']['id'] = $i + 1;
            $plannedPurchases[$i]['order']['number'] = $assignNumber('PO', $plan['order']['order_date']);
        }
        foreach ($plannedSales as $i => $plan) {
            $plannedSales[$i]['order']['id'] = $i + 1;
            $plannedSales[$i]['order']['number'] = $assignNumber('SO', $plan['order']['order_date']);
        }

        // 入出庫を時系列でシミュレーションする（同時刻は追加した順。usort は安定ソート）
        /** @var list<array{at: CarbonImmutable, kind: string, index?: int, product_id?: int, delta?: int}> $events */
        $events = [['at' => $this->jstDateTime($this->dayOf(self::PERIOD_DAYS), 8, 0), 'kind' => 'initial']];
        foreach ($plannedPurchases as $i => $plan) {
            if ($plan['received_at'] !== null) {
                $events[] = ['at' => $plan['received_at'], 'kind' => 'receive', 'index' => $i];
            }
        }
        foreach ($plannedSales as $i => $plan) {
            if ($plan['ship_at'] !== null) {
                $events[] = ['at' => $plan['ship_at'], 'kind' => 'ship', 'index' => $i];
            }
        }
        // 棚卸差異（数件。よく売れる商品以外）
        $normalProducts = array_values(array_filter($products, fn (array $p) => ! $isFastMoving($p['id'])));
        for ($i = 0; $i < 8; $i++) {
            $delta = $rng->int(1, 3) * ($rng->chance(0.5) ? 1 : -1);
            $events[] = [
                'at' => $this->jstDateTime($this->dayOf($rng->int(5, self::PERIOD_DAYS - 10)), 17, $rng->int(30, 59)),
                'kind' => 'stocktake',
                'product_id' => $rng->pick($normalProducts)['id'],
                'delta' => $delta,
            ];
        }
        usort($events, fn (array $a, array $b) => $a['at']->getTimestamp() <=> $b['at']->getTimestamp());

        /** @var array<int, int> $stock */
        $stock = array_fill_keys(array_column($products, 'id'), 0);
        $movements = [];
        /** @var array<int, CarbonImmutable|null> $shipResults 受注の添字 => 出荷日時（在庫不足で出荷しなかったら null） */
        $shipResults = [];
        foreach ($events as $event) {
            switch ($event['kind']) {
                case 'initial':
                    foreach ($products as $p) {
                        $quantity = $isFastMoving($p['id'])
                            ? $p['reorder_point'] + $rng->int(25, 40)
                            : $p['reorder_point'] * 2 + $rng->int(40, 100);
                        $stock[$p['id']] = $quantity;
                        $movements[] = $this->movement($p['id'], 'adjust', $quantity, '期首在庫（棚卸）', null, null, 'admin', $event['at']);
                    }
                    break;
                case 'receive':
                    $order = $plannedPurchases[$event['index'] ?? 0]['order'];
                    foreach ($order['lines'] as $line) {
                        $stock[$line['product_id']] += $line['quantity'];
                        $movements[] = $this->movement($line['product_id'], 'in', $line['quantity'], '発注の入荷', 'purchase_order', $order['id'], 'staff', $event['at']);
                    }
                    break;
                case 'ship':
                    $index = $event['index'] ?? 0;
                    $order = $plannedSales[$index]['order'];
                    // 1 行でも在庫が足りなければ出荷しない（spec §4 と同じ）。確定のまま残す
                    $short = array_filter($order['lines'], fn (array $line) => $stock[$line['product_id']] < $line['quantity']);
                    if ($short !== []) {
                        $shipResults[$index] = null;
                        break;
                    }
                    foreach ($order['lines'] as $line) {
                        $stock[$line['product_id']] -= $line['quantity'];
                        $movements[] = $this->movement($line['product_id'], 'out', -$line['quantity'], '受注の出荷', 'sales_order', $order['id'], 'staff', $event['at']);
                    }
                    $shipResults[$index] = $event['at'];
                    break;
                case 'stocktake':
                    $productId = $event['product_id'] ?? 0;
                    $delta = $event['delta'] ?? 0;
                    $current = $stock[$productId];
                    $delta = $current + $delta < 0 ? -$delta : $delta;
                    $stock[$productId] = $current + $delta;
                    $movements[] = $this->movement($productId, 'adjust', $delta, '棚卸差異', null, null, 'admin', $event['at']);
                    break;
            }
        }

        // 出荷の結果を受注に反映する（出荷できなかった受注は確定のまま、出荷した受注は出荷日時に更新）
        $salesOrders = [];
        foreach ($plannedSales as $index => $plan) {
            $order = $plan['order'];
            if (array_key_exists($index, $shipResults)) {
                $shippedAt = $shipResults[$index];
                if ($shippedAt === null) {
                    $order['status'] = 'confirmed';
                    $order['note'] = '在庫不足のため出荷待ち（デモ）';
                } else {
                    $order['updated_at'] = $shippedAt;
                }
            }
            $salesOrders[] = $order;
        }

        $documentSequences = [];
        foreach ($sequences as $key => $lastNumber) {
            [$kind, $date] = explode(':', $key);
            $documentSequences[] = ['kind' => $kind, 'date' => $date, 'last_number' => $lastNumber];
        }

        return [
            'users' => self::USERS,
            'partners' => $partners,
            'products' => $products,
            'sales_orders' => $salesOrders,
            'purchase_orders' => array_column($plannedPurchases, 'order'),
            'stock_movements' => $movements,
            'document_sequences' => $documentSequences,
        ];
    }

    /** @return list<SeedPartner> */
    private function buildPartners(): array
    {
        $partners = [];
        $add = function (string $code, string $name, string $kind, string $mailDomain) use (&$partners): void {
            $id = count($partners) + 1;
            $serial = sprintf('%04d', $id);
            $partners[] = [
                'id' => $id,
                'code' => $code,
                'name' => $name,
                'kind' => $kind,
                'postal_code' => "000{$serial}",
                'address' => sprintf('デモ県サンプル市中央%d丁目%d-%d', ($id % 9) + 1, $id, ($id % 5) + 1),
                'phone' => "000-0000-{$serial}",
                'email' => strtolower(str_replace('-', '', $code))."@{$mailDomain}",
            ];
        };
        foreach (self::CUSTOMER_NAMES as $i => $name) {
            $add(sprintf('C-%04d', $i + 1), $name, 'customer', 'example.jp');
        }
        foreach (self::SUPPLIER_NAMES as $i => $name) {
            $add(sprintf('S-%04d', $i + 1), $name, 'supplier', 'example.com');
        }

        return $partners;
    }

    /**
     * 発注・受注を作る日（基準日から何日前か）を、古い順に count 件
     *
     * @param  list<int>  $recent
     * @return list<int>
     */
    private function orderDaysAgo(int $count, array $recent): array
    {
        $days = $recent;
        while (count($days) < $count) {
            $days[] = $this->rng->int(1, self::PERIOD_DAYS - 2);
        }
        rsort($days);

        return $days;
    }

    /**
     * @param  list<Line>  $lines
     * @return array{subtotal: int, tax: int, total: int}
     */
    private function sumLines(array $lines): array
    {
        $subtotal = array_sum(array_map(fn (array $line) => $line['quantity'] * $line['unit_price'], $lines));
        $tax = Tax::of($subtotal);

        return ['subtotal' => $subtotal, 'tax' => $tax, 'total' => $subtotal + $tax];
    }

    /** @return SeedStockMovement */
    private function movement(int $productId, string $type, int $quantity, string $reason, ?string $refType, ?int $refId, string $user, CarbonImmutable $at): array
    {
        return [
            'product_id' => $productId,
            'type' => $type,
            'quantity' => $quantity,
            'reason' => $reason,
            'ref_type' => $refType,
            'ref_id' => $refId,
            'user' => $user,
            'created_at' => $at,
        ];
    }

    private function dayOf(int $daysAgo): string
    {
        return $this->addDays($this->today, -$daysAgo);
    }

    private function addDays(string $ymd, int $days): string
    {
        return CarbonImmutable::createFromFormat('!Y-m-d', $ymd, 'UTC')?->addDays($days)->toDateString() ?? $ymd;
    }

    /** JST の暦日＋時刻 */
    private function jstDateTime(string $ymd, int $hour, int $minute): CarbonImmutable
    {
        return CarbonImmutable::parse(sprintf('%s %02d:%02d:00', $ymd, $hour, $minute), self::TIMEZONE);
    }

    private function minDate(CarbonImmutable $a, CarbonImmutable $b): CarbonImmutable
    {
        return $a->lessThanOrEqualTo($b) ? $a : $b;
    }
}
