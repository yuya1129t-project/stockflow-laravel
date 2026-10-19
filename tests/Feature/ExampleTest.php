<?php

test('トップはダッシュボードへ移る', function () {
    $response = $this->get(route('home'));

    $response->assertRedirect('/dashboard');
});
