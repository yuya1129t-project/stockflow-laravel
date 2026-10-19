# StockFlow（Laravel）

受発注・在庫管理の社内業務システム（デモ）です。小規模な卸・小売事業者を想定し、商品・取引先・受注・発注・在庫をブラウザで管理します。

[![CI](https://github.com/yuya1129t-project/stockflow-laravel/actions/workflows/ci.yml/badge.svg)](https://github.com/yuya1129t-project/stockflow-laravel/actions/workflows/ci.yml)

> 現在は初期構築・DB 設計・認証と、業務機能の最初の段階です（Laravel の土台・開発環境・CI、テーブル・架空のデモデータ、ログインと 3 ロールの権限、商品マスタ、取引先マスタと郵便番号からの住所検索、在庫（現在庫一覧・入出庫履歴・棚卸調整）、受注・発注、発注点割れの通知まで）。ほかの業務機能は下の「主な機能」のとおり **予定** です。

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
| 受注                   | 明細編集、税計算（1 件ごとに切り捨て）、ステータス遷移、出荷で在庫引当 | 済み |
| 発注                   | 明細編集、税計算（1 件ごとに切り捨て）、ステータス遷移、入荷で在庫加算 | 済み |
| 在庫                   | 現在庫一覧（発注点割れの強調）、入出庫履歴、棚卸調整                   | 済み |
| ダッシュボード         | 今月の受注額・未出荷数・発注点割れ・入荷待ち                           | 予定 |
| ユーザー管理・操作履歴 | ユーザー・ロール管理、API トークン、操作履歴の閲覧                     | 済み |
| 外部向け REST API      | `/api/v1`（Bearer 認証）、`docs/openapi.json` に対する契約テスト       | 予定 |
| 通知                   | 発注点割れの通知（Queue／Job）                                         | 済み |

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

業務仕様（[docs/spec.md](docs/spec.md) §3）のテーブルと、Laravel・スターターキットのテーブル（users・sessions・cache・jobs など）、外部向け REST API のトークン（Sanctum の `personal_access_tokens`。失効の日時 `revoked_at` を足しています）があります。

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
- **公開デモでデモアカウントを壊されないよう、次の機能は止めています**（ルートがなく 404 になります）。ユーザーの登録・ロール変更・無効化・パスワード再設定は admin のユーザー管理（[ユーザー管理・API トークン・操作履歴](#ユーザー管理api-トークン操作履歴)）で行います
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
- 入出庫履歴: 新しい順に 20 件ずつ。種別・数量（符号付き）・理由・参照元の伝票番号（受注・発注の詳細へのリンク）・記録したユーザーを出します。ユーザーと伝票番号はまとめて引きます（N+1 にしない）
- 棚卸調整（spec §4）: 数えた実数を入力すると、現在庫との差分を `adjust` として記録します。実数は 0 以上の整数（9,999,999 まで。全角数字・桁区切りも可）、理由は必須（200 文字まで・制御文字不可）。差分は**商品の行ロックを取った後の現在庫**から求めるので、画面を開いた後に出庫があっても、記録後の在庫は入力した実数になります。差分が 0 なら何も記録しません。入出庫と操作履歴（`audit_logs`、`action = stock_adjust`）は同じトランザクションで書きます

### 在庫の整合性（行ロック・トランザクション）

入出庫の記録は共通の部品 `App\Stock\StockTransaction` だけで行います（棚卸調整のほか、#7 発注の入荷・#8 受注の出荷・#11 発注点割れの通知から使います）。

```php
$result = StockTransaction::run(function (StockTransaction $stock) use ($orderId, $user, $entries) {
    $products = $stock->lockProducts($productIds); // 対象の全商品を一度に。有効などの検査はこの結果で
    // … 伝票の更新（同じトランザクション）: UPDATE … WHERE id = ? AND status = ? の件数で。モデルはこの中で取り直す
    return $stock->record($user->id, $entries);    // list<StockEntry>。不足なら InsufficientStockException で全体を取り消す
});
// run が戻った時点で確定済み。ここで $result->fellBelowReorderPoint（発注点以上 → 未満に変わった有効な商品）を通知する
```

- 現在庫は入出庫の合計で、在庫数の列は持ちません。「合計を確かめてから出庫を INSERT」をそのまま行うと、同時の出庫が両方とも確認を通って在庫が負になるため、記録の前に**対象商品の `products` の行を id の順に `SELECT … FOR UPDATE`（`lockForUpdate()`）でロック**してから合計を読みます。同じ商品を扱う処理はここで順番待ちになり、後の処理は先の記録を含めた在庫で判定します
- 複数の商品（受注の全明細など）を一度に記録できます。同じ商品の行は合算して判定し、1 商品でも足りなければ、不足する商品と数量を持つ `InsufficientStockException` を投げて全体を取り消します。数量の符号（in は正・out は負・adjust は 0 以外）と参照元（`ref_type`・`ref_id` の両方あり／両方なし）は `StockEntry` を作るときに検査します（DB の CHECK 制約と同じ規則）
- **分離レベルは READ COMMITTED** です。`run` は試行のたびに、BEGIN の直前に `SET TRANSACTION ISOLATION LEVEL READ COMMITTED` を出します（接続の既定も `config/database.php` の `isolation_level` で READ COMMITTED。MySQL の既定は REPEATABLE READ）。REPEATABLE READ では、ロックより前に同じトランザクションで普通の SELECT（伝票を読むなど）をしていると、ロックの後の合計がそのときのスナップショットのままになり、先に確定した出庫を見落として在庫が負になります（InnoDB で確認。直前に次のトランザクションだけを REPEATABLE READ にしても、`run` のトランザクションが READ COMMITTED になることをテストで確かめています）
- **`run` は最も外側のトランザクションとしてだけ呼べます**（ほかのトランザクションの中・`run` の中で呼ぶと例外）。伝票の更新など、同じトランザクションで書くものはすべてコールバックの中に書きます。`run` が戻った時点で確定しているので、通知はその後で送ります
- トランザクションの外からは記録できません。`StockTransaction` は `run` の中でしか作れず（コンストラクターは private）、コールバックの外へ持ち出して使う・同じトランザクションで別の商品のロックをあとから追加する、はいずれも例外です
- **MySQL には PostgreSQL の `FOR NO KEY UPDATE` がありません**。そのため `FOR UPDATE` は、明細（`sales_order_lines` など）の INSERT の外部キー検査が商品の行に取る共有ロックとも競合し、明細を入力の順番で INSERT する処理と交差するとデッドロックします（InnoDB で再現。負けるのは在庫の側）。対策は 2 つです
    1. `run` はデッドロック（1213）のとき、コールバックごと 3 回まで再試行します。ロック待ちのタイムアウト（1205）は再試行せず、棚卸調整では「混み合っているため、調整できませんでした…もう一度実行してください」を返します。ロック待ちの上限は、`run` の間だけセッション単位で 10 秒にします（MySQL の既定は 50 秒。終わったら元に戻す）
    2. 明細を書く処理（#7・#8 の伝票の作成・編集）は、明細を INSERT する前に、明細の全商品の行を id の順に `SELECT … FOR SHARE` でまとめてロックします。こうすればデッドロックしません（テストで確認）
- 同じトランザクションで、`lockProducts`／`record` より前に商品の行に共有ロック（`FOR SHARE`・明細の INSERT）を取らないでください。共有ロックを持った 2 本が排他ロックへの引き上げを互いに待ってデッドロックします。明細も書くなら「`lockProducts`（全商品）→ 伝票・明細の書き込み → `record`」の順にします（排他ロックを持っていれば外部キー検査は待ちません）
- 再試行ではコールバックが 2 回以上動きます（DB はロールバックされますが、PHP の変数・モデルの状態は戻りません）。そのため
    - コールバックの外で取得した Eloquent モデルを、コールバックの中で書き換えて `save` しないでください（2 回目は「変更なし」として UPDATE が出ず、伝票の更新だけが消えます）。伝票は `UPDATE … WHERE id = ? AND status = ?` の件数で更新するか、コールバックの中で `lockForUpdate()` で取り直してから更新します
    - DB の外の副作用（通知・外部 API・メール・キューへの投入）はコールバックの中で起こさず、`run` が戻った後で行います
- 商品の行をロックするほかの処理（商品の編集・CSV 取込）も、対象を一度に id の順でロックしています

同時実行のテスト（`tests/Feature/Stock/StockConcurrencyTest.php`）は、記録・棚卸調整を別プロセス（`tests/Support/stock-worker.php`）の別の MySQL 接続で動かし、**後の側がロック待ちに入ったことを `performance_schema.data_lock_waits` で確かめてから**先の側を確定させます（固定時間の待ちを証拠にしません。10 秒待っても入らなければ失敗）。確かめているのは、同じ商品の同時出庫で負にならない、4 本の順番待ち、逆の順番の複数商品でデッドロックしない、棚卸調整との競合、明細の INSERT との交差（再試行で成功／`FOR SHARE` を先に取ればデッドロックしない）、入出庫と操作履歴を書いた後のデッドロック（再試行の後に 1 回分だけ残る）です。ロックを外す・id の順をやめる・再試行をやめる・REPEATABLE READ に戻す・外側のトランザクションを許す・ロック待ちのタイムアウトも再試行する、のそれぞれで該当するテストが落ちることを確かめています。

- `run` は最も外側でしか動かないため、在庫を記録するテスト（`tests/Feature/Stock/StockTransactionTest.php`・`StockPagesTest.php`）はテストのトランザクション（RefreshDatabase）を張らずに実際に確定させ、テストの前後で全テーブルを空にします（`Tests\Support\CommitsTransactions`）
- 同時実行テストのワーカーは DB 名を `testing` に固定しているので、`--parallel` には対応していません（テスト全体も並列には対応していません）

- `performance_schema` を読むため、観測用の接続だけ MySQL の root で接続します（Sail の root のパスワードは `DB_PASSWORD` と同じ。CI は元から root）。別のユーザーにするときは `DB_LOCK_OBSERVER_USERNAME`・`DB_LOCK_OBSERVER_PASSWORD` を設定します

## 発注

画面は `/purchase-orders`（一覧）・`/purchase-orders/create`（作成）・`/purchase-orders/{id}`（詳細とステータス操作）・`/purchase-orders/{id}/edit`（編集）です。閲覧と CSV 出力は全ロール、作成・編集・ステータス変更は admin・staff だけです（ルートの `can:order.edit` と、コントローラー・Form Request の Policy `App\Policies\PurchaseOrderPolicy` で二重に検査）。

- 一覧: 発注番号の部分一致・仕入先・ステータス・発注日の期間で絞り込み、発注日・番号・仕入先・入荷予定日・合計で並べ替え、20 件ずつのページ送り。条件は URL のクエリに持ち、不正な値は既定値にします。既定は発注日の新しい順です。番号の並べ替えは「発注日 → 番号の桁数 → 番号」の順なので、1 日に 999 件を超えて `PO-…-1000` になっても `PO-…-999` の後に並びます。同点は id で並べます
- CSV 出力（`GET /purchase-orders/export`）: 商品の CSV 出力と同じ作りです（UTF-8 の BOM 付き・見出し行・一覧の絞り込みと並べ替えを反映した全件・数式になりうるセルの無害化・**10,000 件を超えると 413**）。列は発注番号・発注日・入荷予定日・仕入先コード・仕入先名・ステータス・明細数・小計・消費税・合計・備考です
- 作成・編集（下書きのときだけ）: 仕入先・入荷予定日（任意。発注日以降）・備考（任意。改行可・500 文字まで）・明細（商品・数量・単価）。明細は行の追加・削除ができ、小計・税・合計を画面でも計算して表示します（保存する金額はサーバーで計算）。単価の初期値は商品の仕入単価です
    - 仕入先は種別が「仕入先」か「顧客・仕入先」で有効な取引先だけ。明細の商品は有効な商品だけです。編集では、もとから明細にある商品は無効になっていても残せます（新しく加える商品だけ有効を求めます。spec §3 の「新規の」受注・発注の制限）
    - 仕入先は編集の保存でも有効なものが必要です（Next.js 版と同じ）。今の仕入先が無効になった下書きは、選択欄でその仕入先を選べない形で出し、「この仕入先は無効です。別の仕入先を選んでください」と案内します
    - 明細の検証（`App\Orders\OrderValidation`。受注と共通）: 数量は 1 以上 9,999,999 以下、単価は 0 以上 99,999,999 以下の整数。全角数字・桁区切りのカンマ、JSON の数値も受け付けます（小数・負・範囲外は検証エラー。ID は DB の INT の範囲内の 9 桁まで）。同じ商品の明細は 1 行だけ（重複は「同じ商品の明細が N 行目にあります」）、100 行まで。下書きは明細 0 行でも保存できます
    - 発注日は作成した日（Asia/Tokyo）で、入力では受け取りません（発注番号の日付と一致させるため）
- 詳細: 明細・金額・ステータスと、次に進める操作のボタン（確認ダイアログを出してから実行）。明細の商品から商品の詳細へ、在庫の入出庫履歴から発注の詳細へ移れます
- 操作履歴（`audit_logs`、`target_type = purchase_order`）: 作成（`create`）・更新（`update`。変更点つき。変更がない保存は記録しません）・ステータス変更（`status_change`）を、伝票・明細・入出庫と同じトランザクションで記録します（履歴の保存に失敗したら全体を取り消します）

### ステータス遷移（spec §4）

```
draft（下書き）→ ordered（発注済）→ received（入荷済）
draft／ordered → cancelled（取消）        received・cancelled は最終状態
```

- 明細の編集は draft のときだけ。ordered にするには明細が 1 行以上必要です
- received にするとき、全明細の数量を入庫（`in`、参照元 `purchase_order`）として、ステータスの変更と同じトランザクションで記録します（`App\Stock\StockTransaction::run`。入荷では仕入先・商品の有効は問いません）
- ステータス変更（発注済・入荷済・取消）では仕入先の有効を問いません（今の仕入先が無効になった下書きも、そのまま発注済にできます）
- 遷移の表と理由のメッセージは `App\Enums\PurchaseOrderStatus`（`transitions`・`transitionError`）の 1 か所です

### 受注と共通の部品（`app/Orders`）

| 部品                   | 役割                                                                                       |
| ---------------------- | ------------------------------------------------------------------------------------------ |
| `DocumentNumber`       | 採番（`SO-／PO-YYYYMMDD-NNN`）と今日の日付（Asia/Tokyo）                                   |
| `OrderAmounts`         | 金額の計算（小計・税・合計。合計の上限 999,999,999 円）                                    |
| `OrderValidation`      | 明細・備考・日付の検証の規則とエラー文（Form Request から使う）                            |
| `OrderLocks`           | 取引先・明細の商品を FOR SHARE でロックしてから種別・有効を検査する                        |
| `OrderVersion`         | 編集・ステータス変更の競合の検出（updated_at）                                             |
| `OrderTransaction`     | 作成・編集・ステータス変更のトランザクション（ロック待ちの上限・デッドロックの再試行）     |
| `OrderLinesEditor.vue` | 画面の明細の編集（行の追加・削除、小計・税・合計の表示。`resources/js/components/orders`） |

### 採番（同時に作成しても番号が重複しない）

- 連番は `document_sequences`（種類＋日付ごとに最後の番号）を `INSERT … ON DUPLICATE KEY UPDATE last_number = last_number + 1` の **1 文**で進めます。行がない日は INSERT、ある日は UPDATE になり、どちらもその行の排他ロックを取るので、同じ日の 2 本目は 1 本目の確定を待ってから次の番号を取ります
- 「行がなければ `SELECT … FOR UPDATE` → INSERT」にすると、READ COMMITTED ではない行をロックできず、2 本とも INSERT して重複キー（1062）になります（PR #21 のレビュー）
- 採番は伝票の INSERT と同じトランザクションで、取引先・商品の検査の後に行います（作成が失敗すれば番号も戻るので欠番を作りません。商品のロックを待つ間に採番の行をつかんだままにしません）。1 日に 999 件を超えたら桁を増やします（`PO-20261019-1000`）

### 金額（サーバーで計算）

- 小計 = Σ 数量 × 単価、税 = 小計 × 10% の 1 円未満切り捨て（伝票 1 件ごと。`App\Support\Tax`）、合計 = 小計 + 税。画面から送られた小計・合計は使いません
- 合計の上限は 999,999,999 円（DB の列は INT）。超えると「合計金額が上限（999,999,999 円）を超えています」です。画面の表示（`resources/js/lib/orders.ts`）も同じ式で計算します

### 二重入荷の防止・編集の競合の検出

- 入荷は `StockTransaction::run` の中で、`UPDATE purchase_orders SET status = 'received', updated_at = ? WHERE id = ? AND status = 'ordered' AND updated_at = ?` の**件数**を見てから、全明細の入庫を一度に記録します（`record` に全明細を渡すので、商品の行は id の順にまとめてロックされます）。同時に 2 回入荷しても、2 本目は 1 本目の確定を待ってから WHERE を評価し直して 0 件になるので、二重に入庫しません（0 件なら、遷移できない理由か競合を返します）
- 編集とステータス変更は、画面を開いた時点の `updated_at`（version）を送り、今の値と違えば行いません（「他の人がこの発注を更新しました。…画面を開き直して最新の内容を確認してください」。入力は残り、最新の内容を開くリンクを出します）。編集だけでなくステータス変更にも効くので、見ていない明細のまま発注済にはなりません。遷移できない状態なら、競合より先にその理由を返します
    - `purchase_orders.updated_at` はマイクロ秒まで持ちます（`TIMESTAMP(6)`）。秒の精度では同じ 1 秒の中の 2 回目の更新を見分けられないためです。更新のたびに「今の時刻」と「今の値 + 1 マイクロ秒」の大きい方を書くので、時計が止まっていても値は必ず変わります（`OrderVersion::next`。Eloquent の `save` は秒の精度で書くので、伝票の更新には使いません）
    - 受注（#8）も同じ部品を使い、`sales_orders.updated_at` も `TIMESTAMP(6)` です

### ロックの順番（デッドロックしない組み合わせ）

| 操作                         | ロックの順番                                                         |
| ---------------------------- | -------------------------------------------------------------------- |
| 作成                         | 仕入先 FOR SHARE → 明細の商品 FOR SHARE（id 順）→ 採番の行           |
| 編集                         | 仕入先 FOR SHARE → 発注 FOR UPDATE → 明細の商品 FOR SHARE（id 順）   |
| ステータス変更（入荷以外）   | 発注 FOR UPDATE（ロックした後に状態・明細の行数・updated_at を読む） |
| 入荷                         | 発注（条件付き UPDATE）→ 明細の商品 FOR UPDATE（id 順）              |
| 取引先の編集（種別の変更）   | 取引先 FOR UPDATE → その取引先の発注を FOR SHARE で数える            |
| 商品の無効化・棚卸調整・取込 | 商品 FOR UPDATE（id 順）                                             |

- 作成・編集では、取引先と明細の商品を **FOR SHARE でロックしてから**種別・有効を読み直します（`OrderLocks`）。ロックせずに読むと、種別の変更（仕入先 → 顧客）の確定前の値で検査を通り、仕入先ではない取引先に発注が付きます（PR #21 のレビューで再現）。商品の無効化との競合も同じです
- 編集は仕入先を発注より先にロックします。これは将来、発注の行を読む処理が加わったときの備えです。取引先の編集は「取引先 FOR UPDATE → その取引先の発注を FOR SHARE で数える」の順で、今は件数を supplier_id の索引だけで数えるので発注の本体の行を待たず、逆の順（発注 → 仕入先）でも待ちは一周しません（PR #22 のレビューで確認）。数える代わりに発注の行を読む形（番号の一覧など）に変えると、発注を先に持ったまま取引先を待つ編集と一周します（デッドロック 1213 を 2 セッションで確認）
- 明細の商品は INSERT の前に id の順にまとめて FOR SHARE でロックするので、明細を入力の順で INSERT しても（外部キー検査の共有ロック）、在庫の記録（id 順の FOR UPDATE）とデッドロックしません
- **在庫を記録するトランザクション（入荷・出荷）では `OrderLocks::lockProductsForLines`（FOR SHARE）を使いません**。共有ロックを持った 2 本が排他ロックへの引き上げを互いに待ってデッドロックするためです。`StockTransaction::run` の中で呼ぶと例外にしています（商品の有効などは `lockProducts` の結果で確かめます）
- 作成・編集・ステータス変更のロック待ちの上限は、在庫の記録と同じ 10 秒です（`OrderTransaction`。デッドロックは 3 回まで再試行し、ロック待ちのタイムアウトは再試行しません）。どちらも「混み合っているため、…できませんでした」と表示します

### テスト

- `tests/Feature/PurchaseOrders/PurchaseOrderTest.php`: 作成（採番・金額・明細・操作履歴）、入力検証、仕入先の種別・無効、無効な商品、編集（下書きのみ・競合・同じ時刻の 2 回の保存）、一覧・並べ替え・CSV、権限、ステータス変更、操作履歴の失敗で全体を取り消す
- `tests/Feature/PurchaseOrders/PurchaseOrderReceiveTest.php`: 入荷で在庫が増える・参照元・最終状態・取消の後は入荷できない・入荷の競合・操作履歴の失敗で入庫も取り消す（`run` は最も外側でしか動かないので、テストのトランザクションを張りません）
- `tests/Feature/PurchaseOrders/PurchaseOrderConcurrencyTest.php`: 別プロセス（`tests/Support/purchase-order-worker.php`）の別の MySQL 接続で実際に競合させ、後の側がロック待ちに入ったことを `performance_schema.data_lock_waits` で確かめてから先の側を確定させます。同時に 2 件作成して番号が重複しない（採番の行がない日・ある日）、同時に 2 回入荷して入庫は 1 回、取引先の種別変更と作成（どちらが先でも矛盾しない）、商品の無効化と作成、作成と在庫の記録が同じ 2 商品で重なってもデッドロックしない、入荷と取消、明細 0 行にする編集と発注済への変更、発注済への変更と編集、同時の 2 本の編集
- 次の変異を 1 つずつ入れ、該当するテストが落ちることを確かめています: 採番を「読んでから INSERT／UPDATE」にする、入荷を「読んでから更新」にする、取引先の FOR SHARE を外す、商品の FOR SHARE を外す、商品のロックを id の逆順にする、ステータス変更の発注のロックを外す、編集の発注のロックを外す、編集・ステータス変更・入荷の updated_at の比較を外す、updated_at を秒の精度で書く
- テストの採番の後始末: 確定させるテストは前後で全テーブルを空にし（`CommitsTransactions`）、ほかはテストのトランザクションを戻すので、採番の行は残りません（中断した実行の後も、次の実行の最初に空にします）

## 受注

画面は `/sales-orders`（一覧）・`/sales-orders/create`（作成）・`/sales-orders/{id}`（詳細とステータス操作）・`/sales-orders/{id}/edit`（編集）です。**発注と同じ作り・同じ共有部品**（`app/Orders`・`OrderLinesEditor.vue`）で、違いは次のとおりです。閲覧と CSV 出力は全ロール、作成・編集・ステータス変更は admin・staff だけです（ルートの `can:order.edit` と、コントローラー・Form Request の Policy `App\Policies\SalesOrderPolicy` で二重に検査）。

- 一覧・CSV 出力（`GET /sales-orders/export`）: 発注と同じ絞り込み・並べ替え・上限（10,000 件を超えると 413）。絞り込みは受注番号・顧客・ステータス・受注日の期間、並べ替えは受注日・番号・顧客・納期・合計。CSV の列は受注番号・受注日・納期・顧客コード・顧客名・ステータス・明細数・小計・消費税・合計・備考です
- 作成・編集（下書きのときだけ）: 顧客・納期（任意。受注日以降）・備考・明細。番号は `SO-YYYYMMDD-NNN`（受注日は作成した日。Asia/Tokyo）。**単価の初期値は商品の売価**です
    - 顧客は種別が「顧客」か「顧客・仕入先」で有効な取引先だけ。取引先の行を FOR SHARE でロックしてから種別・有効を読み直します（取引先の種別変更との競合でも、顧客ではない取引先に受注が付きません。同時実行テストで確認）。明細の商品・無効の扱いは発注と同じです
- 操作履歴（`audit_logs`、`target_type = sales_order`）: 作成・更新・ステータス変更（出荷を含む）を、伝票・明細・入出庫と同じトランザクションで記録します
- 実装: `App\SalesOrders`（`SalesOrderService`・`SalesOrderQuery`・`SalesOrderListParams`・`SalesOrderCsvExporter`）、`App\Enums\SalesOrderStatus`（遷移の表と理由のメッセージ）、画面は `resources/js/pages/sales-orders`

### ステータス遷移（spec §4）

```
draft（下書き）→ confirmed（確定）→ shipped（出荷済）
draft／confirmed → cancelled（取消）        shipped・cancelled は最終状態
```

- 明細の編集は draft のときだけ。confirmed にするには明細が 1 行以上必要です。すべての遷移で、詳細を開いた時点の `updated_at`（version）と今の値を比べ、違えば行いません（競合）。遷移できない状態なら、競合より先にその理由を返します

### 出荷の在庫引当（confirmed → shipped）

```php
StockTransaction::run(function (StockTransaction $stock) use ($id, $expected) {
    // 1. 受注の条件付き UPDATE（件数が 0 なら、遷移できない理由か競合を返す）
    $updated = DB::table('sales_orders')->where('id', $id)->where('status', 'confirmed')->where('updated_at', $expected)
        ->update(['status' => 'shipped', 'updated_at' => OrderVersion::next($expected)]);
    // 2. 全明細を一度に出庫（out・負の数量・参照元 sales_order）。商品は id の順に FOR UPDATE でまとめてロックされる
    $result = $stock->record($userId, $entries);   // 1 行でも不足なら InsufficientStockException → 1 も含めて全体を取り消す
    // 3. 操作履歴（同じトランザクション）
});
```

- **二重出荷の防止**: 条件付き UPDATE の**件数**で判定します。同時に 2 回出荷しても、2 本目は 1 本目の確定を待ってから WHERE を評価し直して 0 件になるので、二重に出庫しません（「この受注は「出荷済」のため、ステータスを変更できません」）
- **負の在庫の防止**: 別々の受注が同じ商品を同時に出荷しても、後の側は商品の行ロックを待ち、先の出庫を含めた在庫で判定します（足りなければ不足として中止）
- **デッドロックしない**: 全明細を 1 回の `record` に渡すので、商品は明細の順ではなく id の順にロックされます。明細の順番が逆の 2 受注を同時に出荷しても、待ちは一周しません（`StockTransaction` は、同じトランザクションであとから別の商品のロックを足すと例外にするので、明細ごとに `record` を呼ぶ形にはできません）。出荷では `OrderLocks::lockProductsForLines`（FOR SHARE）を使いません
- **在庫不足で全体を中止**: 1 行でも足りなければ `InsufficientStockException` でトランザクションごと取り消します。受注の状態・`updated_at`・入出庫・操作履歴のどれも残りません。画面は確認ダイアログのまま「在庫が足りない商品が N 品目あるため、出荷しませんでした（在庫・ステータスは変わっていません）」と、不足する商品（コード・名前）の**必要数・現在庫・不足数（単位つき）**の表を出します（商品コードの順。1 回だけの flash `shortages`）。明細も version も変わらないので、在庫を補充すれば同じ画面のまま出荷できます
- 出荷では顧客・商品の有効を問いません（spec §3 の制限は「新規の」受注で選べないこと。発注の入荷・Next.js 版と同じ）
- 戻り値: `SalesOrderService::changeStatus` は `['number' => string, 'from' => SalesOrderStatus, 'to' => SalesOrderStatus, 'stock' => StockRecordResult|null]` を返します（出荷以外は `stock` が null）。`stock->fellBelowReorderPoint`（`list<StockChange>`。「発注点以上 → 未満」に変わった有効な商品と前後の在庫）は、`run` の確定後にコントローラーまで返し、コントローラーが発注点割れの通知の Job を投入します（下の「発注点割れの通知」）

ロックの順番は発注と同じです（作成: 顧客 FOR SHARE → 明細の商品 FOR SHARE → 採番の行／編集: 顧客 FOR SHARE → 受注 FOR UPDATE → 明細の商品 FOR SHARE／確定・取消: 受注 FOR UPDATE／出荷: 受注（条件付き UPDATE）→ 明細の商品 FOR UPDATE（id 順））。

### テスト

- `tests/Feature/SalesOrders/SalesOrderTest.php`: 作成（採番・金額・明細・操作履歴・単価の初期値）、入力検証、顧客の種別・無効、無効な商品、編集（下書きのみ・競合・同じ時刻の 2 回の保存・実在しない version）、一覧・並べ替え・CSV、権限、ステータス変更（明細 0 行で確定できない・最終状態・競合）、操作履歴の失敗で全体を取り消す
- `tests/Feature/SalesOrders/SalesOrderShipTest.php`: 出荷で在庫が減る・参照元、在庫不足で全体を中止（1 行だけ不足でも他の行を記録しない・状態・updated_at・操作履歴が変わらない・不足の表・補充すれば出荷できる）、発注点割れに変わった商品を返す、最終状態・取消の後は出荷できない、競合・実在しない version、操作履歴の失敗で出庫も取り消す、無効な顧客・商品でも出荷できる、viewer は 403
- `tests/Feature/SalesOrders/SalesOrderConcurrencyTest.php`（別プロセスのワーカー `tests/Support/sales-order-worker.php`。後の側のロック待ちを `performance_schema.data_lock_waits` で確かめてから先を確定）: 同時作成で番号が重複しない、同じ受注の同時の 2 回の出荷で出庫は 1 回、別々の受注が同じ商品を同時に出荷して在庫が負にならない、明細の順番が逆の 2 受注の同時出荷でデッドロックしない、取引先の種別変更と作成（どちらが先でも）、商品の無効化と作成、作成と在庫の記録、出荷と取消、明細 0 行にする編集と確定、確定と編集、同時の 2 本の編集
- 次の変異を 1 つずつ入れ、該当するテストが落ちることを確かめています: 出荷を「読んでから更新」にする、出荷の条件から updated_at を外す、明細ごとに `record` を呼ぶ、出荷で商品を明細の順にロックする、在庫の記録の商品のロックを外す、確定・取消の受注のロックを外す、編集の受注のロックを外す、顧客の FOR SHARE を外す、編集・ステータス変更の updated_at の比較を外す

## ユーザー管理・API トークン・操作履歴

admin だけが使えます（docs/spec.md §2・§5-8・§5-9・§6-2）。権限はルート（`can:user.manage`・`can:audit.view`）と Policy（`UserPolicy`・`AuditLogPolicy`。Form Request・コントローラー）で二重に検査します。更新系は `App\Users\UserService` にまとめ、更新と操作履歴（`audit_logs`）は同じトランザクションで書きます。

### ユーザー管理（`/users`）

- 一覧（50 件ずつ）・登録（名前・メールアドレス・ロール・初期パスワード）・詳細・ロール変更・無効化／再有効化・パスワード再設定
- パスワードは Laravel の `Hash`（bcrypt）で保存し、規則は Laravel のパスワードの既定（`Password::defaults`。本番は 12 文字以上・英大文字・英小文字・数字・記号・漏洩していないこと、それ以外は 8 文字以上）に、72 バイトまで（bcrypt は 72 バイトを超える部分を無視するため）と制御文字の禁止を足しています。前後の空白もそのまま使います。メールアドレスは小文字にそろえ、ASCII の文字だけを受け付けます（ログインの照合と同じ）。重複は大文字・小文字の違いも同じ扱いです
- **自分自身の無効化・ロール変更はできません**（spec §3）。自分のパスワード再設定はできます
- **デモ用アカウント（シードの 3 人）は、無効化・ロール変更・パスワード再設定を拒否します**（公開デモでは admin のパスワードも公開するので、閲覧者がほかの閲覧者のデモを壊せないように）。判定はメールアドレスで、メールアドレスは画面から変えられません。API トークンの発行・失効はできます。**デモ専用の挙動**で、環境を問わず常に有効です（デモ以外で使うなら外してください）
- 無効化: そのユーザーのセッション（`sessions` テーブル）をすべて削除し、有効な API トークンをすべて失効させ、「ログインしたままにする」の Cookie（`remember_token`）を作り直します。再有効化してもトークンは戻りません
- 再有効化: セッションを削除し、`remember_token` を作り直します（無効化と同時に進んでいたログインが無効化の後に作ったセッションが、再有効化で使えるようにならないように）
- パスワード再設定: そのユーザーのセッションをすべて削除し、`remember_token` を作り直します。**自分自身の再設定でも全セッション（操作した画面を含む）を終了**し、ログイン画面へ移します。「API トークンもすべて失効させる」（既定でオン）を選ぶと、同じトランザクションでトークンも失効させます（件数は操作履歴に残します。パスワードは残しません）
- セッションの削除は `SESSION_DRIVER=database` の前提です（`.env.example` の既定。ほかのドライバーでは `sessions` テーブルに行がなく、削除できません）
- **ログインと再設定の競合**: Web のすべての要求に Laravel 標準の `AuthenticateSession` を掛けています（`bootstrap/app.php`。`EnsureUserIsActive` の後）。ログインした時点のパスワードのハッシュ（セッションの `password_hash_web`）を毎回いまの値と比べ、違えばログアウトさせます。古いパスワードで照合を通ったログインが、再設定の確定の後にセッションを保存しても、次の要求で締め出されます。同じ競合でできた「ログインしたままにする」の Cookie も、Cookie の中のパスワードのハッシュが古いので使えません（Laravel 13 の `SessionGuard` が Cookie からの再認証で照合し、`AuthenticateSession` も重ねて照合します）。パスワードの再設定については、`SESSION_DRIVER` が database でなくても既存のセッションが無効になります
- 公開デモでは、登録したユーザーの名前・メールアドレスがほかの閲覧者にも見えます（登録画面に注意書きがあります）

### 同時実行（有効な admin を 0 人にしない）

- すべての更新は、トランザクションの中で**操作する admin と対象の行を id の順に `FOR UPDATE` でロック**してから読み、**操作する admin がいまも有効な admin かを確かめ直します**（認可の後に降格・無効化されていたら 403）。id の順にそろえるのは、2 人の admin が互いを操作したときのデッドロックを避けるためです。同じユーザーへの操作（無効化とトークン発行など）はこの行ロックで順番待ちになります
- **有効な admin が 0 人にならないことは、上の行ロックと操作者の確かめ直し（と、自分自身は変更できないこと）だけで保たれます**（成功した操作の後には、行ロックで守られた有効な admin＝操作者が必ず残る）
- そのうえで**多重の防御**として、ロール変更・無効化／再有効化（admin の顔ぶれを変えうる操作）は、トランザクションを始める**前に** MySQL のユーザーロック（`GET_LOCK('sf_admins_<DB 名の md5 の先頭 16 文字>', 10)`。名前は 64 文字までなので DB 名の長さに依存しない形にしています。終わったら `RELEASE_LOCK`）で直列にし、変更後に有効な admin が 0 人になるなら拒否します。ロックを取ってからトランザクションを始めるので、前の操作の確定後の状態を読めます。行ロックだけの操作（パスワード再設定・トークン）はこのロックを待たないので、待ちが循環しません
- 2 人の admin が同時に互いを降格・無効化すると、後の側はユーザーロックを待ち、先の確定の後に「操作する admin がもう admin でない」ため拒否されます（片方だけ成功し、有効な admin は 1 人残ります）。`GET_LOCK` がなくても、後の側は行ロックを待って同じく拒否されます。最後の admin の数の確認も、操作者の確かめ直しが先に効くので通常は出ません（確かめ直しを外す変異で、この確認が 0 人になる変更を止めることを確かめています）

### API トークン（Sanctum）

- admin がユーザーごとに発行します（名前を付ける）。**平文は発行の応答（JSON）で 1 回だけ返し**、画面はダイアログに表示するだけでどこにも保存しません（画面遷移のフラッシュに載せると平文がセッションに保存されるため使いません）。DB には Sanctum の標準どおり SHA-256 のハッシュだけを保存します。形式は Sanctum の標準の `<id>|sf_<40 文字の英数字><CRC32>`（接頭辞 `sf_` で StockFlow のトークンだと分かります）
- 一覧（発行日時・最終使用・失効の状態）と失効（`revoked_at` を設定。行は消しません）。無効なユーザーには発行しません。**有効なトークンは 1 ユーザーあたり 10 件まで**です（ユーザーを登録できる admin はユーザーを増やせばそれ以上発行できるので、全体の上限ではありません。デモ用アカウントの枠は閲覧者全員で共有です）
- **REST API（#10）の認証**: ルートに `ApiTokenAuth::MIDDLEWARE`（`auth:sanctum`）を付けます。`App\Users\ApiTokenAuth` が Sanctum に次を差し込みます
    - `Authorization: Bearer <token>` だけで認証します（スキーム名の大文字・小文字は区別しません）。Cookie のセッションでは通りません（`config/sanctum.php` の `guard`・`stateful` が空。SPA 用の `/sanctum/csrf-cookie` も登録しません）。Web の画面はトークンでは開けません
    - トークンの形式でない値は DB を引かずに失敗にします（範囲外の id でも例外にしません）
    - 失効・持ち主の無効を毎回確かめます。持ち主（ロール・有効）は毎回 DB から読むので、ロール変更・無効化は次のリクエストから効きます。権限は Web と同じ Gate（`->can(Permission::...)`）で、トークンの持ち主の**いまの**ロールに従います
    - 失敗の理由（ヘッダーなし・形式違い・存在しない・失効済み・持ち主が無効）は区別せず、同じ 401 を返します（いまは `{"message": "ログインが必要です"}`。RFC 9457 の形にするのは #10）
    - 最終使用日時は、認証に成功したリクエストごとに更新します（Sanctum の標準）

### 操作履歴（`/audit-logs`）

- 新しい順の一覧（50 件ずつ。範囲外のページは最後のページへ）と、絞り込み（操作したユーザー・操作・対象の種類・期間（Asia/Tokyo の日付。両端を含む））。不正な値は無視します（エラーにしません）
- 操作・対象の種類は表示名にし、対象（商品・取引先・受注・発注・ユーザー）は詳細画面へのリンクにします
- ユーザー管理の操作の記録: 登録（`create`）・ロール変更（`role_change`。変更前後）・無効化（`deactivate`）・再有効化（`reactivate`）・パスワード再設定（`password_reset`）・トークンの発行（`token_create`）・失効（`token_revoke`）。対象の種類は `user`、トークンは `api_token`。終了したセッション・失効させたトークンの件数を summary に残します

### テスト

- `tests/Feature/Users/UserManagementTest.php`: 画面、範囲外の ID、権限（staff／viewer は全操作が 403 で何も変わらない・未ログイン）、拒否されたパスワード再設定で資格情報とセッションが変わらない（元のパスワードと Cookie が使え、指定したパスワードは使えない）、認可の後に無効化・降格された操作者の 6 操作の拒否、ログインと再設定の競合（旧パスワードで照合 → 再設定が確定 → ログインのセッションを保存 → 次の要求で拒否。同じ競合の「ログインしたままにする」の Cookie も拒否）、登録 → そのユーザーでログイン、メールの重複（一意制約の競合を含む）、入力検証、ロール変更・自分不可、最後の admin の判定、無効化で既存のセッション（2 本）・トークン・remember_token が使えなくなる、再有効化で無効中に作られたセッションを削除、パスワード再設定（ほかのユーザー・トークンを失効させない選択・自分自身で 2 つの Cookie がどちらも使えなくなる）、デモ用アカウントの保護。セッションは実際のログインで作った database セッションの Cookie で確かめます（`tests/Support/SessionRequests.php`）
- `tests/Feature/Users/ApiTokenTest.php`: 発行（平文は 1 回・ハッシュだけ保存・発行の直後と次の画面の後のどちらでもセッションに平文がない）・上限・無効なユーザー・名前の検証、失効（ほかのユーザーのトークン・範囲外の ID は「見つかりません」）、Bearer 認証の成功・失効後・失効させずに持ち主だけ無効にしたとき・ロール変更の反映・不正な形式 10 通り・Cookie のセッションだけでは通らない・Web はトークンで開けない
- `tests/Feature/Users/UserConcurrencyTest.php`（別プロセスのワーカー `tests/Support/user-worker.php`）: admin が 2 人だけのときに互いを同時に降格・無効化（3 通り）すると、後の側が**ユーザーロックを待つ**こと（`performance_schema.metadata_locks`）を確かめてから先を確定させ、後の側が拒否されて有効な admin が 1 人残る。無効化とトークン発行が同時（どちらが先でも）に、後の側が**対象の行ロックを待つ**ことを確かめ、無効なユーザーに有効なトークンが残らない
- `tests/Feature/AuditLogs/AuditLogIndexTest.php`: 一覧・表示名・リンク、権限、絞り込み（ユーザー・操作・対象の種類・期間の境界・組み合わせ・不正な値）、ページ送り
- 次の変異を 1 つずつ入れ、該当するテストが落ちることを確かめています: 行の `FOR UPDATE` を外す、`GET_LOCK` を外す（テストがユーザーロックの待ちを観測しているため。admin が 0 人になるからではありません）、両方を外す（相互降格が両方成功する）、`AuthenticateSession` を外す、発行のときに平文をフラッシュに入れる、操作者の確かめ直しを外す、トークン認証で持ち主の有効を見ない、失効を見ない、Sanctum の `guard` を `web` にする、無効化・再有効化・パスワード再設定でセッションを消さない、再有効化だけセッションを消さない、デモ保護を外す、トークンの上限を外す

### 残るリスク（対策しないもの）

- **ログインとパスワード再設定・無効化の競合**: ログインは「パスワードを照合する → セッションを保存する」の 2 段で、その間にユーザー単位のロックを取りません（Fortify・SessionGuard にその同期点がありません）。そのため、古いパスワードで照合を通ったログインが、再設定・無効化の後にセッション（行）を作ること自体は防げません。ただし、そのセッションは使えません: 再設定と重なったものは `AuthenticateSession` が次の要求で締め出し、無効化と重なったものは毎回の有効の確認（`EnsureUserIsActive`）で使えず、再有効化でもセッションを削除します。残るのは、ログインの応答そのもの（ダッシュボードへのリダイレクト）が返ることだけです
- 同じ競合で、パスワードのハッシュの設定（コストなど）を変えた直後に限り、ログイン側のハッシュの作り直し（`rehashPasswordIfRequired`）が古いパスワードで保存し直し、再設定を打ち消すことがありえます（設定の変更と数ミリ秒の競合が重なったときだけ。対策していません）
- パスワード再設定で API トークンを失効させないことを選んだ場合、漏れたトークンは使えるままです（連携を止めないための選択肢。画面の既定は失効させる）
- `SESSION_DRIVER` を database 以外にすると、無効化・再有効化でセッションを削除できません（`remember_token` の作り直しと、無効ユーザーの毎回の拒否だけが効きます。パスワード再設定は `AuthenticateSession` で既存のセッションが無効になります）

## 発注点割れの通知

入出庫の結果、有効な商品の現在庫が**発注点以上 → 発注点未満**に変わったとき、Slack の Incoming Webhook 形式（`{text, blocks}`）で通知します（docs/spec.md §6-3）。Next.js 版と同じ文面・同じ規則です。

- **対象の操作**: 棚卸調整と受注の出荷。発注の入荷（在庫が増えるだけ）は対象外です。無効の商品・もとから割れている商品・割れない商品は通知しません（判定は `StockPlan` の `fellBelowReorderPoint`）
- **1 回の操作につき 1 通**: 出荷で複数の商品が割れたら 1 通にまとめます。商品ごとの行は 20 件まで（超えた分は「ほか N 件」と在庫一覧の発注点割れ `/stock?level=low` へのリンク）。blocks は最大 22 個（Slack の上限は 50 個）
- **文面**: 見出し「発注点を下回った商品が N 件あります（棚卸調整／受注 SO-… の出荷）」と、商品ごとに 商品コード・商品名（在庫画面 `/stock/{id}` へのリンク。基準は `APP_URL`）・現在庫・発注点・変更前の在庫
- **利用者の入力の扱い**: 商品コード・商品名・単位は `&` `<` `>` をエスケープし、`verbatim: true` の mrkdwn（blocks）にだけ入れます（`@here`・`@channel` などのメンション化、URL のリンク化を止める）。トップレベルの `text`（通知のプレビュー。verbatim を指定できない）は件数ときっかけだけです
- **トランザクションの確定後に投入**: 棚卸調整（`StockController::adjust`）と出荷（`SalesOrderController::changeStatus`）で、`StockTransaction::run` が戻った後に `App\ReorderNotifications\ReorderPointNotifier` が Job（`App\Jobs\SendReorderPointNotification`）を投入します。Job は `ShouldQueueAfterCommit` なので、万一トランザクションの中で投入しても、確定するまでキューに入らず、取り消されたら捨てられます。在庫不足・操作履歴の失敗などで取り消された操作では投入しません
- **送信**: https の URL だけに、タイムアウト 3 秒（接続から受信の終わりまで）で POST します。リダイレクトはたどりません（3xx は HTTP エラー）。応答本文は使いません
- **失敗しても業務処理は取り消しません**: 送れなかったら操作履歴（`audit_logs`、`action = notify_failed`。対象は棚卸調整なら商品、出荷なら受注）に、きっかけ・商品コード・**原因の種類だけ**（URL の設定が不正／タイムアウト／HTTP エラー（ステータス）／通信エラー／キューに投入できなかった／処理の異常終了）を記録します。Webhook URL・応答本文・例外のメッセージは操作履歴にもログにも `failed_jobs` にも残しません（送信で出た例外は種類を問わず Job の中で捕まえ、ログにはクラス名だけを出します）。キュー（jobs テーブル）にも URL は入れません（実行のときに設定から読む）
- **再試行（同じ通知を何度も送らないため）**: 試行は最大 3 回（`tries = 3`）。再試行するのは、届いていないことが確かなとき（接続の前の失敗＝cURL 6 名前を解決できない・7 接続できない、HTTP 429 流量制限）だけで、10 秒・60 秒あけます。タイムアウト・5xx などは、相手が受け取って投稿した可能性があるので再試行しません。`notify_failed` は最後に 1 回だけ記録します。キューが `sync` のとき（積み直せない）は再試行せず、すぐに記録します。想定外の例外（本文の組み立てなど、プログラムの誤り）は `maxExceptions = 1`、ワーカーの打ち切り（30 秒）は `failOnTimeout` で、どちらも再試行しません。この 2 つと試行回数の上限超過は、Job の `failed()` が `notify_failed`（処理の異常終了）を記録します（`handle()` の中で記録したときは `handle()` が正常に戻るので、二重には記録しません）

### 設定

```bash
# .env（コミットしない）。https の Incoming Webhook の URL。空なら送らない（Job も投入しない。デモは未設定）
STOCKFLOW_SLACK_WEBHOOK_URL=https://hooks.slack.com/services/...
```

- `http://` の URL・認証情報入りの URL・URL として不正な値は使わず、通知のたびに `notify_failed`（Webhook URL の設定が不正）を記録します
- 在庫画面へのリンクは `APP_URL`（http／https）から作ります。不正ならリンクなしで送ります
- **本番では `APP_URL` を公開 URL にしてください**。未設定だと Laravel の既定値 `http://localhost` が使われ、Slack に localhost へのリンクが出ます

### キューのワーカー

キューは database ドライバー（`QUEUE_CONNECTION=database`、`jobs` テーブル。スターターキットのマイグレーション）です。**ワーカーを動かさないと送られません**（Job は `jobs` テーブルにたまります）。

```bash
./vendor/bin/sail artisan queue:work          # ワーカーを起動（止めるまで動き続ける）
./vendor/bin/sail artisan queue:work --once   # 1 件だけ処理して終わる（確認用）
./vendor/bin/sail artisan queue:failed        # 失敗した Job の一覧（failed_jobs）
```

- コードを変えたらワーカーを再起動します（`./vendor/bin/sail artisan queue:restart`。動いているワーカーは古いコードのまま）
- **AWS へのデプロイ（#14）では、Web とは別にワーカーを常駐させる必要があります**（ECS のサービスを 1 つ足す・Supervisor などで `php artisan queue:work` を起動し続ける）。ワーカーがないと通知は送られず、`jobs` テーブルにたまり続けます

### テスト

テストから外部へは送りません（`phpunit.xml` で `STOCKFLOW_SLACK_WEBHOOK_URL` を空に固定し、`Http::fake` で差し替えていない要求は `tests/TestCase.php` の `preventStrayRequests` で例外）。

- `tests/Unit/ReorderNotifications/ReorderPointMessageTest.php`: 文面、エスケープと verbatim（`@here`・`<!channel>`・`<@U123>`・`<https://…|…>`・`&lt;` を商品名と単位に入れ、リンクあり・なしで生のまま残らない）、`text` に入力が入らない、20 件の上限と「ほか N 件」、リンクの基準・Webhook URL の検証、失敗の要約
- `tests/Feature/ReorderNotifications/SendReorderPointNotificationTest.php`（`Http::fake`）: 成功（宛先・JSON・タイムアウト 3 秒・リダイレクトなしの設定）、HTTP エラー、リダイレクトをたどらない、タイムアウト、再試行する失敗としない失敗、最後まで失敗したら 1 回記録、sync では再試行せずすぐ記録、想定外の例外、`failed()` の記録、database のキューとワーカーで通して `failed_jobs` に残らない／二重に記録しない、URL の不正、秘密を残さない
- `tests/Feature/ReorderNotifications/ReorderPointDispatchTest.php`: 跨いだときだけ 1 回投入（`Queue::fake`）、跨がない・無効・差分 0・未設定・入荷・出荷以外では投入しない、出荷は 1 通にまとめる、取り消された操作では投入しない、送る時点でトランザクションの外にいて別の接続から確定後の在庫が見える、送信・投入に失敗しても業務処理は残り `notify_failed` を記録、確定するまで `jobs` に入らない
- 次の変異を 1 つずつ入れ、該当するテストが落ちることを確かめています: 出荷の `run` の中で投入する、リダイレクトの禁止を外す、`ShouldQueueAfterCommit` を `ShouldQueue` にする

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
