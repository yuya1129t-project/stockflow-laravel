<?php

use Illuminate\Support\Facades\DB;

// 在庫の行ロック（lockForUpdate）や同時実行のテストは MySQL でないと意味がないため、
// テストの接続先が MySQL であることを固定する（CI・Sail とも）
test('tests run against mysql', function () {
    expect(DB::connection()->getDriverName())->toBe('mysql');
});
