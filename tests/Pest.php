<?php

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\CommitsTransactions;
use Tests\Support\ResetsRequestState;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| Test Case
|--------------------------------------------------------------------------
|
| The closure you provide to your test functions is always bound to a specific PHPUnit test
| case class. By default, that class is "PHPUnit\Framework\TestCase". Of course, you may
| need to change it using the "pest()" function to bind different classes or traits.
|
*/

pest()->extend(TestCase::class)
    ->use(RefreshDatabase::class)
    ->in('Feature');

// ブラウザテスト（tests/Browser）。画面からの在庫の記録（出荷・入荷）は最も外側のトランザクションとしてだけ動くので、
// テストのトランザクションを張らずに確定させる（CommitsTransactions。テストの前後で全テーブルを空にする）。
// アプリは同じプロセスで要求ごとに使い回されるので、要求の間で認証・セッションの状態を残さない（ResetsRequestState）
pest()->extend(TestCase::class)
    ->use(CommitsTransactions::class, ResetsRequestState::class)
    ->in('Browser');

/*
|--------------------------------------------------------------------------
| Expectations
|--------------------------------------------------------------------------
|
| When you're writing tests, you often need to check that values meet certain conditions. The
| "expect()" function gives you access to a set of "expectations" methods that you can use
| to assert different things. Of course, you may extend the Expectation API at any time.
|
*/

expect()->extend('toBeOne', function () {
    return $this->toBe(1);
});

/*
|--------------------------------------------------------------------------
| Functions
|--------------------------------------------------------------------------
|
| While Pest is very powerful out-of-the-box, you may have some testing code specific to your
| project that you don't want to repeat in every file. Here you can also expose helpers as
| global functions to help you to reduce the number of lines of code in your test files.
|
*/

function something()
{
    // ..
}
