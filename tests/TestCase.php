<?php

namespace Tests;

use App\Support\Database\DestructiveOperationGuard;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Laravel\Fortify\Features;
use RuntimeException;

abstract class TestCase extends BaseTestCase
{
    /**
     * DB を作り直すトレイト（RefreshDatabase など）が動く前に、接続先がテスト用 DB（testing）であることを確かめる。
     * 開発用 DB を指したままテストを走らせてデータを消す事故を防ぐ
     * （トレイトのフック beforeRefreshingDatabase はテストのクラス側のトレイトに上書きされるため、ここで判定する）
     *
     * @return array<class-string, class-string>
     */
    protected function setUpTraits()
    {
        $guard = DestructiveOperationGuard::checkDefaultConnection();
        if (! $guard->ok) {
            throw new RuntimeException("テスト用 DB ではないため中止しました: {$guard->reason}");
        }

        return parent::setUpTraits();
    }

    protected function skipUnlessFortifyHas(string $feature, ?string $message = null): void
    {
        if (! Features::enabled($feature)) {
            $this->markTestSkipped($message ?? "Fortify feature [{$feature}] is not enabled.");
        }
    }
}
