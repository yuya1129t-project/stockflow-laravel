<?php

namespace Tests\Support;

use Illuminate\Testing\TestResponse;

/**
 * 実際のログイン（POST /login）で作った database セッションの Cookie で要求を送るテスト用（ファイルで uses(SessionRequests::class)）。
 *
 * テストでは同じアプリのまま要求を続けるので、要求のたびにガード（読み込み済みのユーザー）とセッションの store
 * （前の要求の中身がメモリに残る）、それらを持ち続けるシングルトン（auth.driver: sessions.user_id に書くガード、
 * redirect: フラッシュを書くセッション）を捨て、Cookie だけでログイン状態が決まるようにする。
 * JSON の要求（getJson など）にも Cookie を付ける（withCredentials）
 * セッションは sessions テーブルに保存する（SESSION_DRIVER=database。本番と同じ）
 */
trait SessionRequests
{
    protected function setUpSessionRequests(): void
    {
        config(['session.driver' => 'database']);
    }

    /** ログインして、そのセッションの ID（Cookie の値）を返す */
    protected function loginForSession(string $email, string $password): string
    {
        $response = $this->usingSession(null)->post(route('login.store'), ['email' => $email, 'password' => $password]);
        $response->assertRedirect(route('dashboard'));
        $cookie = $response->getCookie(config('session.cookie'));
        assert($cookie !== null);
        $id = $cookie->getValue();
        assert(is_string($id) && $id !== '');

        return $id;
    }

    /** 次の要求を、このセッションの Cookie だけで送る（null なら Cookie なし） */
    protected function usingSession(?string $sessionId): static
    {
        $this->app['auth']->forgetGuards();
        $this->app['session']->forgetDrivers();
        foreach (['session.store', 'auth.driver', 'redirect'] as $instance) {
            $this->app->forgetInstance($instance);
        }
        $this->defaultCookies = $sessionId === null ? [] : [config('session.cookie') => $sessionId];
        $this->withCredentials = $sessionId !== null;

        return $this;
    }

    /** このセッションの Cookie でダッシュボードを開く（ログイン中なら 200、ログアウトしていればログイン画面へ） */
    protected function dashboardWithSession(string $sessionId): TestResponse
    {
        return $this->usingSession($sessionId)->get(route('dashboard'));
    }
}
