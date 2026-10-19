<?php

use App\Enums\Role;
use App\Models\User;
use Tests\Browser\Support\Browser;

/**
 * admin のユーザー管理と操作履歴（ブラウザ）
 */
test('admin がユーザーを登録すると一覧に出て、そのユーザーでログインできる。操作履歴に登録とログインが新しい順に残る', function () {
    $admin = Browser::user(Role::Admin, 'テスト管理者');

    $page = Browser::login($admin);
    Browser::open($page, '/users');
    // 名前・メールアドレス・ロール・状態（登録日時は除く）
    Browser::assertRows($page, '[data-test="user-row"]', [
        ['テスト管理者（自分）', 'browser-admin@example.com', '管理者', '有効'],
    ], [0, 1, 2, 3]);

    $page->click('@user-create');
    $page->fill('#user-name', '新しい担当者')
        ->fill('#user-email', 'new-staff@example.com')
        ->select('#user-role', 'staff')
        ->fill('#user-password', 'browser-pass-1234')
        ->click('@user-submit');
    Browser::assertText($page, 'h1', '新しい担当者');
    $created = User::query()->where('email', 'new-staff@example.com')->sole();
    $page->assertPathIs("/users/{$created->id}");
    expect($created->role)->toBe(Role::Staff);

    Browser::open($page, '/users');
    Browser::assertRows($page, '[data-test="user-row"]', [
        ['テスト管理者（自分）', 'browser-admin@example.com', '管理者', '有効'],
        ['新しい担当者', 'new-staff@example.com', '担当者', '有効'],
    ], [0, 1, 2, 3]);

    // 登録したユーザーで（別のブラウザから）ログインできる
    $other = visit('/login');
    $other->fill('email', 'new-staff@example.com')
        ->fill('password', 'browser-pass-1234')
        ->click('@login-button');
    $other->assertPathIs('/dashboard');
    Browser::assertText($other, '[data-test="header-role"]', '担当者');

    // 操作履歴（新しい順）: 利用者・操作・対象・内容（日時は除く）
    Browser::open($page, '/audit-logs');
    Browser::assertRows($page, '[data-test="audit-row"]', [
        ['新しい担当者', 'ログイン', "ユーザー #{$created->id}", 'ログインしました'],
        ['テスト管理者', '登録', "ユーザー #{$created->id}", 'ユーザー「新しい担当者」（new-staff@example.com）を担当者として登録しました'],
        ['テスト管理者', 'ログイン', "ユーザー #{$admin->id}", 'ログインしました'],
    ], [1, 2, 3, 4]);

    // 操作で絞り込める（ログインだけ）
    Browser::open($page, '/audit-logs?action=login');
    Browser::assertRows($page, '[data-test="audit-row"]', [
        ['新しい担当者', 'ログイン'],
        ['テスト管理者', 'ログイン'],
    ], [1, 2]);
});
