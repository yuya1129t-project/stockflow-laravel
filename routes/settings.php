<?php

use Illuminate\Support\Facades\Route;

// アカウントの設定は外観（ライト／ダーク）だけ。名前・メールアドレス・パスワードの変更、アカウント削除、
// 2 要素認証・パスキーは提供しない（公開デモでデモアカウントを壊せないようにするため。ユーザーの管理は admin が行う）
Route::middleware(['auth'])->group(function () {
    Route::redirect('settings', '/settings/appearance');

    Route::inertia('settings/appearance', 'settings/Appearance')->name('appearance.edit');
});
