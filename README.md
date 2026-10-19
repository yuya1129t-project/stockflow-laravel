# StockFlow（Laravel）

受発注・在庫管理の社内業務システム（デモ）です。小規模な卸・小売事業者を想定し、商品・取引先・受注・発注・在庫をブラウザで管理します。

[![CI](https://github.com/yuya1129t-project/stockflow-laravel/actions/workflows/ci.yml/badge.svg)](https://github.com/yuya1129t-project/stockflow-laravel/actions/workflows/ci.yml)

> 現在は初期構築・DB 設計・認証と、業務機能の最初の段階です（Laravel の土台・開発環境・CI、テーブル・架空のデモデータ、ログインと 3 ロールの権限、商品マスタ、取引先マスタと郵便番号からの住所検索、在庫（現在庫一覧・入出庫履歴・棚卸調整）まで）。ほかの業務機能は下の「主な機能」のとおり **予定** です。

## このリポジトリについて

- 自社開発（個人の公開成果物）です
- 要件・方針の決定と公開の判断は本人が行い、設計・実装・コードレビュー（Claude と Codex の独立したレビュー）・指摘への対応は Claude Code が行っています。本人は成果物を確認しています
- **データはすべて架空です**（実在の会社・人物・取引とは関係ありません）
- [Next.js 版（stockflow-next）](https://github.com/yuya1129t-project/stockflow-next) と **同じ業務仕様**（[docs/spec.md](docs/spec.md)）・**同じ REST API 仕様**（[docs/openapi.json](docs/openapi.json)）で実装します

## 主な機能

| 機能                   | 内容                                                                   | 状態 |
| ---------------------- | ---------------------------------------------------------------------- | ---- |
| 認証・ロール権限       | admin／staff／viewer。権限はサーバー側で検査                           | 済み |
| 商品マスタ             | 一覧・検索・登録・編集・無効化、CSV 出力・取込                         | 済み |
| 取引先マスタ           | 一覧・検索・登録・編集・無効化、郵便番号から住所を自動入力（外部 API） | 済み |
| 受注                   | 明細編集、税計算（1 件ごとに切り捨て）、ステータス遷移、出荷で在庫引当 | 予定 |
| 発注                   | 明細編集、税計算（1 件ごとに切り捨て）、ステータス遷移、入荷で在庫加算 | 予定 |
| 在庫                   | 現在庫一覧（発注点割れの強調）、入出庫履歴、棚卸調整                   | 済み |
| ダッシュボード         | 今月の受注額・未出荷数・発注点割れ・入荷待ち                           | 予定 |
| ユーザー管理・操作履歴 | ユーザー・ロール管理、API トークン、操作履歴の閲覧                     | 予定 |
| 外部向け REST API      | `/api/v1`（Bearer 認証）、`docs/openapi.json` に対する契約テスト       | 予定 |
| 通知                   | 発注点割れの通知（Queue／Job）                                         | 予定 |

## 技術スタック

| 区分           | 採用技術                                                                          |
| -------------- | --------------------------------------------------------------------------------- |
| フレームワーク | Laravel 13（PHP 8.4）                                                             |
| 画面           | Laravel 公式 Vue スターターキット（Inertia 3＋Vue 3＋TypeScript＋Tailwind CSS 4） |
| 認証           | Laravel Fortify（スターターキット同梱）                                           |
| データベース   | MySQL 8.4                                                                         |
| テスト         | Pest                                                                              |
| 静的解析・整形 | Larastan（PHPStan level 7）／Pint／Vite+（フロントの lint・整形）／vue-tsc        |
| CI             | GitHub Actions（MySQL サービスに対してテスト）                                    |
| ローカル環境   | Laravel Sail（Docker Compose）                                                    |
| 実行環境       | Node.js 24／npm（Sail のコンテナ内）                                              |

## ローカル開発

必要なもの: Docker（Docker Compose が使えること）だけです。PHP・Composer・Node.js は手元に入れる必要はありません（Sail のコンテナに PHP 8.4 と Node.js 24 が入っており、その中で動かします）。

```bash
# 1. PHP の依存を入れる（Composer はコンテナで実行）
docker run --rm \
  -u "$(id -u):$(id -g)" \
  -v "$(pwd)":/var/www/html \
  -w /var/www/html \
  laravelsail/php84-composer:latest \
  composer install --ignore-platform-reqs

# 2. 環境変数のファイルを作る（.env はコミットしない）
cp .env.example .env

# 3. コンテナを起動（アプリ: http://localhost:8089 、MySQL: 127.0.0.1:33069）
./vendor/bin/sail up -d

# 4. アプリの鍵の生成、マイグレーションとデモデータの投入
./vendor/bin/sail artisan key:generate
./vendor/bin/sail artisan migrate --seed

# 5. 画面のビルド（Sail のコンテナの Node.js 24 で実行）
./vendor/bin/sail npm ci
./vendor/bin/sail npm run build
```

- ブラウザで http://localhost:8089 を開きます
- 他のプロジェクトとぶつからないよう、ホスト側のポートを `.env` の `APP_PORT`（アプリ 8089）・`FORWARD_DB_PORT`（MySQL 33069）・`VITE_PORT`（Vite の開発サーバー 5179）で変えています。アプリ・MySQL・Vite はいずれも `127.0.0.1` にだけ公開します（同じネットワークの別の端末からは届きません）
- 画面を編集しながら確認するときは `./vendor/bin/sail npm run dev` で Vite の開発サーバーを起動します（http://localhost:5179 。画面は引き続き http://localhost:8089 で開きます）
- npm の操作は Sail のコンテナの中で行います。画面のビルド時に Wayfinder（ルートの型を生成する Vite のプラグイン）が `php artisan` を呼ぶため、PHP のあるコンテナで実行する必要があります
- 止めるときは `./vendor/bin/sail down`（データベースのデータは Docker のボリュームに残ります）

## データベース

業務仕様（[docs/spec.md](docs/spec.md) §3）のテーブルと、Laravel・スターターキットのテーブル（users・sessions・cache・jobs など）があります。

```mermaid
erDiagram
    users ||--o{ stock_movements : "記録者"
    users ||--o{ audit_logs : "操作者"
    partners ||--o{ sales_orders : "顧客"
    partners ||--o{ purchase_orders : "仕入先"
    sales_orders ||--|{ sales_order_lines : "明細"
    purchase_orders ||--|{ purchase_order_lines : "明細"
    products ||--o{ sales_order_lines : ""
    products ||--o{ purchase_order_lines : ""
    products ||--o{ stock_movements : ""
    sales_orders ||..o{ stock_movements : "出荷（ref）"
    purchase_orders ||..o{ stock_movements : "入荷（ref）"

    users {
        bigint id PK
        string email UK
        string name
        string role "admin / staff / viewer"
        bool is_active
    }
    products {
        bigint id PK
        string code UK "P-0001"
        string name
        string category
        string unit
        int sales_price "0 以上"
        int purchase_price "0 以上"
        int reorder_point "発注点。0 以上"
        bool is_active
    }
    partners {
        bigint id PK
        string code UK "C-0001 / S-0001"
        string name
        string kind "customer / supplier / both"
        char postal_code "7 桁"
        string address
        string phone
        string email
        bool is_active
    }
    sales_orders {
        bigint id PK
        string number UK "SO-YYYYMMDD-NNN"
        bigint customer_id FK
        date order_date
        date due_date
        string status "draft / confirmed / shipped / cancelled"
        int subtotal
        int tax
        int total "小計 + 税"
    }
    sales_order_lines {
        bigint id PK
        bigint sales_order_id FK "（受注, 商品）で一意"
        bigint product_id FK
        int quantity "1 以上"
        int unit_price "受注時点の単価"
    }
    purchase_orders {
        bigint id PK
        string number UK "PO-YYYYMMDD-NNN"
        bigint supplier_id FK
        date order_date
        date expected_date
        string status "draft / ordered / received / cancelled"
        int subtotal
        int tax
        int total "小計 + 税"
    }
    purchase_order_lines {
        bigint id PK
        bigint purchase_order_id FK "（発注, 商品）で一意"
        bigint product_id FK
        int quantity "1 以上"
        int unit_price "発注時点の単価"
    }
    stock_movements {
        bigint id PK
        bigint product_id FK
        string type "in / out / adjust"
        int quantity "in は正・out は負・adjust は 0 以外"
        string reason
        string ref_type "sales_order / purchase_order / null"
        bigint ref_id "ref_type と両方あるか両方 null"
        bigint user_id FK
        timestamp created_at
    }
    audit_logs {
        bigint id PK
        bigint user_id FK
        string action
        string target_type
        string target_id
        text summary
        timestamp created_at
    }
    document_sequences {
        string kind PK "SO / PO"
        date date PK
        int last_number "0 以上"
    }
```

- 金額・数量は整数（円・個数）、日付は `date`。現在庫は入出庫（`stock_movements.quantity`）の合計で、入出庫と操作履歴は更新・削除しません
- 値の範囲（価格 ≥ 0、数量 > 0、合計 = 小計 + 税、入出庫の符号、参照元の有無の一致、郵便番号 7 桁）とステータス等の値は **MySQL の CHECK 制約**でも守ります。ステータス等は文字列の列＋CHECK で表し、PHP 側は backed enum（`app/Enums`）に変換します
- 外部キー: 伝票 → 明細は `cascade`（伝票を消すと明細も消える）、マスタ（商品・取引先）と履歴のユーザーへの参照は `restrict`（参照があれば消せない）

### デモデータ

```bash
./vendor/bin/sail artisan migrate --seed   # テーブルを作ってデモデータを入れる
./vendor/bin/sail artisan db:seed          # デモデータだけ入れ直す（このアプリのテーブルを空にしてから投入）
```

- 商品 50・取引先 20（顧客 12・仕入先 8）・受注 80・発注 40・入出庫の履歴。すべて架空です（[Next.js 版](https://github.com/yuya1129t-project/stockflow-next) と同じ手順・同じ乱数で作るので、同じ日に作れば同じデータになります）
- 日付は投入した日から過去約 90 日に分布します。どの時点でも在庫が負にならないよう入出庫を時系列で作り、一部の商品は発注点を下回る状態にしています
- デモ用アカウント（パスワードはすべて `demo-pass-1234`。デモ専用の値です）。ログイン画面にも表示され、クリックすると入力欄に入ります（画面の表示はシードの値 `Database\Seeders\Demo\DemoData` をそのまま使うので、二重に持っていません）

| ロール | メールアドレス     |
| ------ | ------------------ |
| admin  | admin@example.com  |
| staff  | staff@example.com  |
| viewer | viewer@example.com |

### データを消す操作のガード

- シード（全データを消して入れ直す）と、`migrate:fresh`・`db:wipe` など DB を消すコマンドは、次のどれかに当たると実行しません。`--database` で別の接続を選んだときも、実行の直前にその接続を確かめます
    - ローカルの開発環境（`APP_ENV=local`）とテスト以外の環境（production・staging など）で、環境変数 `STOCKFLOW_ALLOW_DESTRUCTIVE_SEED=1` がない（DB が同じマシン上にあっても必要です。デモ環境の定期リセットでだけ設定する想定）
    - DB のホストがローカル（`localhost`・`127.0.0.1`・`::1`・Sail の `mysql`・ソケット）以外で、同じ環境変数がない
    - テスト用 DB（`testing`）の取り違え: テスト以外の環境から `testing` を消そうとしている（環境変数でも外せません）
- 本番（`APP_ENV=production`）では、`migrate:fresh` などのコマンドは環境変数があっても常に禁止です（Laravel の `DB::prohibitDestructiveCommands`）。シードだけが環境変数で許可できます
- テストは、環境が `testing` で、実際に使う DB が `testing` のときだけ動きます。設定がキャッシュされている（`config:cache` 済み）と phpunit.xml の環境変数が効かないので、テストは DB を作り直す前に中止します（`sail artisan config:clear` してから実行してください）
- シードで消すのはこのアプリのテーブルだけです（マイグレーション履歴・キャッシュ・キューのテーブルは残します）
- 日時は Asia/Tokyo の壁時計で保存します。DB の既定値（`CURRENT_TIMESTAMP`）とそろえるため、MySQL の接続のタイムゾーンを `+09:00`（`DB_TIMEZONE`）にしています

## 認証と権限

### ログイン

- Laravel Fortify のログイン・ログアウトだけを使います（`config/fortify.php` の `features` は空）。Fortify のルートは登録させず（`Fortify::ignoreRoutes()`）、ログイン・ログアウトの 3 つだけを `routes/web.php` に定義しています（機能の設定に関係なく登録されるパスワードの再確認 `/user/confirm-password` なども、ありません）。ログイン後は元の画面に戻ります（未ログインで開いた URL）
- **公開デモでデモアカウントを壊されないよう、次の機能は止めています**（ルートがなく 404 になります）。ユーザーの登録・ロール変更・無効化は admin のユーザー管理（今後の Issue）で行います
    - 公開のユーザー登録（`/register`）
    - パスワードのリセット、メールアドレスの確認（必須化もしない）
    - 名前・メールアドレスの変更、パスワードの変更、アカウントの削除（スターターキットの「設定」の画面）
    - 2 要素認証、パスキー
    - 「設定」に残しているのは外観（ライト／ダーク）だけです
- ログインの試行回数の制限: 同じメールアドレス＋IP から 1 分に 5 回、同じ IP から 1 分に 20 回まで（成功・失敗を問わず数えます）。超えると「ログインの試行回数が多すぎます。N 秒後に…」と表示します（JSON の要求には 429 と `Retry-After` ヘッダー）
    - ASCII 以外の文字を含むメールアドレスは照合せず、通常の失敗にします（DB の照合順序はゼロ幅スペースなどを無視するため、そのまま照合すると、変形したアドレスで「メールアドレス＋IP」の制限を回避できてしまう）
    - **本番での注意**: IP は `$request->ip()` で取ります。ロードバランサーの後ろに置くときは、`trustProxies` で信頼するプロキシを設定しないと全員が 1 つの IP として数えられます（1 人の試行で全員がログインできなくなる）。#14（AWS でのデモ公開）で設定し、`at: '*'` にするならアプリに直接届く経路を塞ぎます
- 無効ユーザー（`is_active = false`）: パスワードが正しくてもログインできません（パスワードが違うときは通常の失敗と同じメッセージにして、無効かどうかを明かしません）。ログイン中に無効化された場合は、次のリクエストでログアウトしてログイン画面へ移ります（`App\Http\Middleware\EnsureUserIsActive`）
- 成功したログインを操作履歴（`audit_logs`、`action = login`）に記録します（`App\Listeners\RecordLogin`。Laravel の `Login` イベント）。「ログインしたままにする」の Cookie での再認証も記録します。失敗したログインと、無効ユーザーの拒否は記録しません
- ログインに成功したとき、ハッシュの設定（コスト）が変わっていればパスワードのハッシュを作り直します（Laravel の標準のログインと同じ）

### 権限（1 か所の定義＋サーバー側の検査）

- ロールごとの権限は `App\Auth\Permission`（enum）の 1 か所だけに定義しています。業務仕様（[docs/spec.md](docs/spec.md) §2）の表と同じで、テストが spec の表を読み込んで突き合わせます（`tests/Unit/Auth/PermissionTest.php`）

| 権限（Gate のアビリティ） | 内容                                   | admin | staff | viewer |
| ------------------------- | -------------------------------------- | ----- | ----- | ------ |
| `view`                    | ダッシュボード・各一覧・詳細の閲覧     | ○     | ○     | ○      |
| `master.edit`             | 商品・取引先の登録・編集               | ○     | ○     | ×      |
| `master.deactivate`       | 商品・取引先の無効化（論理削除）       | ○     | ×     | ×      |
| `order.edit`              | 受注・発注の作成・編集・ステータス変更 | ○     | ○     | ×      |
| `stock.adjust`            | 在庫の棚卸調整                         | ○     | ○     | ×      |
| `csv.export`              | CSV 出力                               | ○     | ○     | ○      |
| `csv.import`              | CSV 取込（商品）                       | ○     | ○     | ×      |
| `user.manage`             | ユーザー管理                           | ○     | ×     | ×      |
| `audit.view`              | 操作履歴の閲覧                         | ○     | ×     | ×      |

- 各権限は Gate のアビリティとして登録しています（`AppServiceProvider`。無効ユーザーには何も許可しません）。**権限は必ずサーバー側で検査します**（画面でボタンやメニューを隠すのは補助です）
    - ルート: `Route::get(...)->can(Permission::MasterEdit)`（middleware `can:master.edit`）
    - コントローラー: `Gate::authorize(Permission::MasterEdit)`
    - Policy は「モデル単位の判定」（例: 自分自身は無効化できない）が必要な操作に使い、その中でもこの表を使います
- 権限外は HTTP 403 で、共通レイアウトの中に日本語のエラーページ（`resources/js/pages/Error.vue`）を出します。未ログインはログイン画面へ移ります。JSON の要求（`/api/*` を含む）は、未ログインが 401、権限外が 403 で、日本語のメッセージを返します
- 画面には、ログイン中のユーザーの名前・ロールと、許可された権限の一覧だけを渡します（`HandleInertiaRequests`）。サイドバーは業務仕様 §5 の画面の構成で、まだない画面は「準備中」と表示し、admin 専用の画面（ユーザー管理・操作履歴）は admin にだけ出します

## 取引先マスタと外部 API 連携

画面は「取引先」（`/partners`）。一覧・詳細はすべてのロール、登録・編集と住所検索は admin・staff、無効化・再有効化は admin だけです（ルートの `->can(...)` と `App\Policies\PartnerPolicy` で二重に検査）。

- 一覧: 取引先コード・取引先名の部分一致（大文字・小文字を区別しない。`%` `_` `\` は文字として検索）、種別の絞り込み、コード・名前・種別での並べ替え、20 件ずつのページ送り、「有効のみ（既定）／無効も表示／無効のみ」の切り替え。条件は URL のクエリに持ち、不正な値は既定値にします
    - 種別の絞り込みの「顧客」は customer と both、「仕入先」は supplier と both、「顧客・仕入先」は both だけです（spec §3 の顧客・仕入先の定義）。受注・発注で取引先を選ぶときは `Partner::query()->withRole(PartnerRole::Customer)` などで絞ります
- 入力検証（`App\Http\Requests\PartnerRequest`）: 取引先コードは半角英数字・`-`・`_`（先頭は英数字、20 文字まで）で、大文字に揃えて一意。郵便番号はハイフン有無・全角を受け付けてハイフンなしの 7 桁で保存。電話番号は 0 から始まる 10〜11 桁（全角は半角に）。メールアドレスは RFC の厳密な検査（`email:rfc,strict`。DNS は引かない）。名前・住所・メールアドレスの改行・タブ・表示方向の制御などの制御文字は拒否します（`App\Rules\SingleLine`。1 行の項目の共通の規則）。エラーは項目ごとに日本語で表示します
- 受注・発注で使われている取引先は、その役割を外す種別の変更（例 受注のある顧客を「仕入先」に）をできません。取引先の行をロックしてから伝票の件数を数えます（`App\Services\Partners\PartnerService`）
- 登録・編集・無効化・再有効化は操作履歴（`audit_logs`、`target_type = partner`）に記録します。更新と履歴は同じトランザクションで、履歴の保存に失敗したら更新も取り消します。変更のない保存は記録しません

### 郵便番号 → 住所（zipcloud）

- 外部 API（[zipcloud](https://zipcloud.ibsnet.co.jp/doc/api)）は**サーバー側**から呼びます（`App\Services\Partners\AddressLookup`。画面は `GET /partners/address-lookup?postal_code=` を呼ぶ）。タイムアウト 3 秒（接続から受信の終わりまで）、本文は 64KB まで（受信しながら数え、超えたら打ち切る。Content-Length のない応答・chunked も同じ）、リダイレクトはたどらず、応答は型・件数・長さ・制御文字・問い合わせた郵便番号との一致を検証してから使います
- 失敗・該当なしのときは「住所を手入力してください」と表示し、そのまま手入力して保存できます。失敗の原因はサーバーのログに残します。記録するのは決まった形の短い値（HTTP ステータス・cURL のエラー番号・妥当な範囲の整数の status）だけで、外部の応答の文字列・例外のメッセージ・郵便番号は出しません
- 結果は郵便番号ごとに Laravel の Cache に 1 日保存します（成功と該当なしだけ。タイムアウトなどの一時的な失敗は保存しない）。回数はユーザーごとに 1 分 30 回までで（判定と加算はキャッシュの不可分な加算で 1 手順。同時の要求でも上限を超えない）、超えると 429 と「しばらくしてから…住所を手入力してください」を返します（どちらも既定のキャッシュのストアを使います）
- 画面では、検索中に郵便番号や住所の入力が変わったら、届いた結果を捨てます（古い結果で入力を上書きしない）
- 受信の打ち切りとタイムアウトは、テスト用のローカルの HTTP サーバー（`tests/Support/raw-http-server.php`。127.0.0.1 だけ）で、実際の通信で確かめます。回数制限と種別変更の同時実行は、別プロセス・別の MySQL の接続で競合させて確かめます（`tests/Feature/Partners/ConcurrencyTest.php`）
- テストでは `Http::fake` で zipcloud を差し替えます。`tests/TestCase.php` で `Http::preventStrayRequests()` にしているので、差し替えていない外部への要求はテストの失敗になり、CI から実 API を呼びません

## 商品マスタ

画面は `/products`（一覧）・`/products/create`（登録）・`/products/{id}`（詳細）・`/products/{id}/edit`（編集）・`/products/import`（CSV 取込）です。権限はルートの `can:` と、コントローラー・Form Request の Policy（`App\Policies\ProductPolicy`）で二重に検査します。

- 一覧: 商品コード・商品名・カテゴリの部分一致、カテゴリ、状態（有効のみ／無効も表示／無効のみ）で絞り込み、許可した列（コード・名前・カテゴリ・売価・発注点・現在庫）で並べ替え、20 件ずつページ送り。条件は URL のクエリに持ちます（共有・再読み込み・「戻る」で同じ一覧になります）
- 現在庫は入出庫の合計で、商品ごとに集計した結果を 1 本の SQL で結合します（N+1 にしない。テストで問い合わせの回数を確かめています）。有効な商品で 現在庫 < 発注点 のものを「発注点割れ」として強調します
- 登録・編集の検証は Form Request（`App\Http\Requests\ProductRequest`）で、規則とエラー文は CSV 取込と共通（`App\Products\ProductValidation`）です
    - 商品コードは大文字に揃えてから一意を確かめます（`p-0001` と `P-0001` は同じ商品）。半角英数字・`-`・`_`（先頭は英数字）、20 文字まで
    - 1 行で入力する項目（コード・名前・カテゴリ・単位）は、改行・タブなどの制御文字と、表示方向の制御文字（U+202E など）を拒否します
    - 価格・発注点は 0 以上の整数。全角数字・3 桁区切りのカンマ（`1,200`）は受け付けます
- 無効化・再有効化は admin のみです（論理削除。`is_active`）
- 操作履歴（`audit_logs`）に登録・更新（変更点つき）・無効化・再有効化を、更新と同じトランザクションで記録します。変更がない保存は記録しません

### CSV（docs/spec.md §7）

|            | 出力（`GET /products/export`）                                           | 取込（`/products/import`）                                                                  |
| ---------- | ------------------------------------------------------------------------ | ------------------------------------------------------------------------------------------- |
| 権限       | 全ロール                                                                 | admin・staff                                                                                |
| 文字コード | UTF-8（BOM 付き。Excel で文字化けしない）                                | UTF-8（BOM の有無は問わない。Shift_JIS などはエラー）                                       |
| 列         | 商品コード・商品名・カテゴリ・単位・売価・仕入単価・発注点・現在庫・状態 | 商品コード〜発注点の 7 列が必須（順番は問わない）。ほかの列（現在庫・状態など）は読み飛ばす |
| 件数       | 一覧の絞り込み・並べ替えを反映した全件。**10,000 件を超えると 413**      | データ行 **1,000 行**・ファイル **512KB** まで（画面でも送信前にサイズを検査）              |

- 出力したファイルを編集して、そのまま取り込めます。code が登録済みなら更新、なければ新規です。**有効／無効は CSV では変わりません**（無効化は admin だけの操作のため。新規は有効）
- 取込は **1 行でもエラーがあれば全体を取り込まず**、行番号・項目・内容の一覧を表示します（最大 100 件。件数は全体を表示）。検査するのは、見出しの不足・重複、列数が見出しと違う行（囲んでいない `1,200` で列がずれた行）、引用符の誤り（閉じ忘れの `"` で後ろの行を飲み込まない。RFC 4180 に沿って厳格に解析）、各項目の検証、ファイルの中の商品コードの重複です
- 書き込みは 1 トランザクションで、更新は 500 行ずつ 1 文にまとめます。操作履歴は商品ごとの登録・更新（「CSV 取込」と変更点つき）と、件数の要約 1 件です
- CSV インジェクション対策: 出力では `=` `+` `-` `@` タブ・CR で始まる文字列の先頭に `'` を付けます。取込では付けた `'` を 1 つ外すので、出力 → 取込で元の値に戻ります（`'-特価品` のように元から `'` で始まる値も変わりません）
- CSV の解析・生成は `App\Support\Csv`（`CsvParser`・`CsvWriter`・`CsvFormula`）の小さなクラスにまとめ、単体テストしています（ライブラリは使っていません）

## 在庫

画面は `/stock`（現在庫一覧）・`/stock/{id}`（商品ごとの在庫・入出庫履歴・棚卸調整）です。閲覧は全ロール、棚卸調整は admin・staff だけです（ルートの `can:stock.adjust` と、Form Request の Policy `ProductPolicy::adjustStock` で二重に検査）。商品の詳細画面から在庫の画面へ移れます。

- 現在庫一覧: 商品一覧と同じ検索・カテゴリ・状態・並べ替え（現在庫を含む）・20 件ずつのページ送りに、「発注点割れのみ」の絞り込み（`level=low`）を加えたものです。集計は商品一覧と同じ 1 本の SQL（`App\Products\ProductQuery`）で、発注点割れ（有効な商品で 現在庫 < 発注点）を強調します
- 入出庫履歴: 新しい順に 20 件ずつ。種別・数量（符号付き）・理由・参照元の伝票番号（受注・発注の画面ができるまではテキスト）・記録したユーザーを出します。ユーザーと伝票番号はまとめて引きます（N+1 にしない）
- 棚卸調整（spec §4）: 数えた実数を入力すると、現在庫との差分を `adjust` として記録します。実数は 0 以上の整数（9,999,999 まで。全角数字・桁区切りも可）、理由は必須（200 文字まで・制御文字不可）。差分は**商品の行ロックを取った後の現在庫**から求めるので、画面を開いた後に出庫があっても、記録後の在庫は入力した実数になります。差分が 0 なら何も記録しません。入出庫と操作履歴（`audit_logs`、`action = stock_adjust`）は同じトランザクションで書きます

### 在庫の整合性（行ロック・トランザクション）

入出庫の記録は共通の部品 `App\Stock\StockTransaction` だけで行います（棚卸調整のほか、#7 発注の入荷・#8 受注の出荷・#11 発注点割れの通知から使います）。

```php
$result = StockTransaction::run(function (StockTransaction $stock) use ($order, $user, $entries) {
    $products = $stock->lockProducts($productIds); // 対象の全商品を一度に。有効などの検査はこの結果で
    // … 伝票の更新（同じトランザクション）
    return $stock->record($user->id, $entries);    // list<StockEntry>。不足なら InsufficientStockException で全体を取り消す
});
// 確定の後で $result->fellBelowReorderPoint（発注点以上 → 未満に変わった有効な商品）を通知する
```

- 現在庫は入出庫の合計で、在庫数の列は持ちません。「合計を確かめてから出庫を INSERT」をそのまま行うと、同時の出庫が両方とも確認を通って在庫が負になるため、記録の前に**対象商品の `products` の行を id の順に `SELECT … FOR UPDATE`（`lockForUpdate()`）でロック**してから合計を読みます。同じ商品を扱う処理はここで順番待ちになり、後の処理は先の記録を含めた在庫で判定します
- 複数の商品（受注の全明細など）を一度に記録できます。同じ商品の行は合算して判定し、1 商品でも足りなければ、不足する商品と数量を持つ `InsufficientStockException` を投げて全体を取り消します。数量の符号（in は正・out は負・adjust は 0 以外）と参照元（`ref_type`・`ref_id` の両方あり／両方なし）は `StockEntry` を作るときに検査します（DB の CHECK 制約と同じ規則）
- **分離レベルは READ COMMITTED** です（`config/database.php` の `isolation_level`。MySQL の既定は REPEATABLE READ）。REPEATABLE READ では、ロックより前に同じトランザクションで普通の SELECT（伝票を読むなど）をしていると、ロックの後の合計がそのときのスナップショットのままになり、先に確定した出庫を見落として在庫が負になります（InnoDB で確認。分離レベルを戻すと同時実行のテストが落ちます）。`run` は READ COMMITTED でなければ例外にします
- トランザクションの外からは記録できません。`StockTransaction` は `run` の中でしか作れず（コンストラクターは private）、コールバックの外へ持ち出して使う・`run` を入れ子にする・同じトランザクションで別の商品のロックをあとから追加する、はいずれも例外です
- **MySQL には PostgreSQL の `FOR NO KEY UPDATE` がありません**。そのため `FOR UPDATE` は、明細（`sales_order_lines` など）の INSERT の外部キー検査が商品の行に取る共有ロックとも競合し、明細を入力の順番で INSERT する処理と交差するとデッドロックします（InnoDB で再現。負けるのは在庫の側）。対策は 2 つです
    1. `run` はデッドロック・ロック待ちのタイムアウトのとき、コールバックごと 3 回まで再試行します（最も外側のトランザクションのとき。コールバックの中では通知・外部 API などの DB 以外の副作用を起こさない）
    2. 明細を書く処理（#7・#8 の伝票の作成・編集）は、明細を INSERT する前に、明細の全商品の行を id の順に `SELECT … FOR SHARE` でまとめてロックします。こうすればデッドロックしません（テストで確認）
- 同じトランザクションで、`lockProducts`／`record` より前に商品の行に共有ロック（`FOR SHARE`・明細の INSERT）を取らないでください。共有ロックを持った 2 本が排他ロックへの引き上げを互いに待ってデッドロックします。明細も書くなら「`lockProducts`（全商品）→ 伝票・明細の書き込み → `record`」の順にします（排他ロックを持っていれば外部キー検査は待ちません）
- 商品の行をロックするほかの処理（商品の編集・CSV 取込）も、対象を一度に id の順でロックしています

同時実行のテスト（`tests/Feature/Stock/StockConcurrencyTest.php`）は、記録・棚卸調整を別プロセス（`tests/Support/stock-worker.php`）の別の MySQL 接続で動かし、**後の側がロック待ちに入ったことを `performance_schema.data_lock_waits` で確かめてから**先の側を確定させます（固定時間の待ちを証拠にしません。10 秒待っても入らなければ失敗）。確かめているのは、同じ商品の同時出庫で負にならない、4 本の順番待ち、逆の順番の複数商品でデッドロックしない、棚卸調整との競合、明細の INSERT との交差（再試行で成功／`FOR SHARE` を先に取ればデッドロックしない）です。ロックを外す・id の順をやめる・再試行をやめる・REPEATABLE READ に戻す、のそれぞれで該当するテストが落ちることを確かめています。

- `performance_schema` を読むため、観測用の接続だけ MySQL の root で接続します（Sail の root のパスワードは `DB_PASSWORD` と同じ。CI は元から root）。別のユーザーにするときは `DB_LOCK_OBSERVER_USERNAME`・`DB_LOCK_OBSERVER_PASSWORD` を設定します

## テスト・静的解析

```bash
./vendor/bin/sail test                 # Pest（テスト用データベース testing に対して実行）
./vendor/bin/sail composer lint        # Pint（整形の確認のみ。直すときは composer lint:fix）
./vendor/bin/sail composer analyse     # Larastan（PHPStan）
./vendor/bin/sail npm run check        # フロントの lint・整形の確認（Vite+）
./vendor/bin/sail npm run types:check  # フロントの型チェック（vue-tsc）
./vendor/bin/sail npm run build        # 本番用ビルド
```

- テストの並列実行（`--parallel`）には対応していません（テスト用 DB の名前を `testing` に固定して判定しているため）
- テスト用のデータベース `testing` は、Sail の MySQL コンテナの初回起動時（ボリュームを作るとき）に自動で作られます。作られていない場合は `./vendor/bin/sail down -v` でボリュームを消してから起動し直します（開発用のデータも消えます）
- CI（GitHub Actions）では、MySQL 8.4 のサービスを立てて、上と同じチェックとビルドを push（main）と pull request ごとに実行します

## 開発の進め方

- タスクは GitHub Issues で管理し、1 Issue = 1 ブランチ（`feat/<issue番号>-<短い名前>`）= 1 PR で進めます
- PR は CI（Pint・Larastan・Pest・ビルド）が通り、Claude と Codex の独立したレビューの指摘に対応してからマージします
- 業務仕様は [docs/spec.md](docs/spec.md)、REST API の契約は [docs/openapi.json](docs/openapi.json)（Next.js 版と共通。変更しない）を正とします
