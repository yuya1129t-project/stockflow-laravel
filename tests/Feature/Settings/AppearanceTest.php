<?php

use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;

test('外観の設定はログインすれば開ける', function () {
    $this->actingAs(User::factory()->create())
        ->get(route('appearance.edit'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->component('settings/Appearance'));
});

test('設定のトップは外観の設定へ移る', function () {
    $this->actingAs(User::factory()->create())
        ->get('/settings')
        ->assertRedirect('/settings/appearance');
});

test('未ログインはログイン画面へ', function () {
    $this->get(route('appearance.edit'))->assertRedirect(route('login'));
});
