<?php

use App\Models\User;

test('未ログインはログイン画面へ', function () {
    $response = $this->get(route('dashboard'));
    $response->assertRedirect(route('login'));
});

test('ログインするとダッシュボード（仮表示）を開ける', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    $response = $this->get(route('dashboard'));
    $response->assertOk();
});
