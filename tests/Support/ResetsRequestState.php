<?php

namespace Tests\Support;

use Illuminate\Foundation\Http\Events\RequestHandled;
use Illuminate\Support\Facades\Event;

/**
 * ブラウザテスト（Pest のブラウザテスト）用。Pest はアプリを同じプロセスの HTTP サーバーで動かし、テストのアプリのインスタンスを
 * すべての要求で使い回す（PHP-FPM のように要求ごとに作り直さない）。そのままだと、要求の間で次の状態が残る:
 *
 * - 認証のガード（SessionGuard）が覚えたログイン中のユーザー: 別のブラウザ（Cookie のない要求）でもログイン済みに見える
 * - セッション（Store）の中身: 読み込みは既存の中身への上書きなので、別のセッションの値が混ざる
 *
 * そこで要求ごとの処理が終わった後（セッションは保存済み）に、ガードを作り直させ、セッションの中身を空にする
 * （Laravel Octane が要求ごとに行うのと同じ）。セッションのデータそのもの（array ドライバーの保存先）はそのまま残る
 */
trait ResetsRequestState
{
    protected function setUpResetsRequestState(): void
    {
        Event::listen(RequestHandled::class, function (): void {
            $app = app();
            if ($app->resolved('auth.driver')) {
                $app->forgetInstance('auth.driver');
            }
            if ($app->resolved('auth')) {
                $app->make('auth')->forgetGuards();
            }
            if ($app->resolved('session')) {
                $app->make('session')->driver()->flush();
            }
        });
    }
}
