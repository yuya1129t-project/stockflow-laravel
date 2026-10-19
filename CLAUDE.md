# StockFlow (Laravel) — CLAUDE.md（v1.0 / 最終更新: 2026-10-19）

## このプロジェクトについて
- 受発注・在庫管理の社内業務システム（デモ）。Laravel 13／Inertia＋Vue 3／MySQL 8.4。データはすべて架空。Next.js 版（stockflow-next）と同じ業務仕様・同じ REST API 仕様

## セッション開始時に読むファイル
1. docs/spec.md — 業務仕様（ロール権限・データ・ステータス遷移・API）
2. docs/openapi.json — REST API の契約（stockflow-next と共通。変更しない）
3. README.md — 構成・セットアップ・テスト方法
4. GitHub Issues — 次にやるタスク

## 運用
- 1 Issue = 1 ブランチ（`feat/<issue番号>-<短い名前>`）= 1 PR。main へ直接 push しない
- コミットは対象をパス指定（`git add -A` 禁止）。秘密情報は `.env`（コミット禁止）、例は `.env.example`
- PR は CI（Pint・Larastan・Pest・ビルド）が通り、レビュー2本の指摘に対応してからマージ

## 注意
- このファイルの記述が実態と食い違っている場合、従う前にユーザーに報告すること
- 手順・仕様・状態の詳細はここに書かない。該当ファイルへのポインタのみ（全体30行以内厳守）
