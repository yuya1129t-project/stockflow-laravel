<?php

use Illuminate\Contracts\Process\ProcessResult;
use Illuminate\Support\Facades\Process;

/**
 * テストが開発用 DB を消さないこと（tests/TestCase.php の setUpTraits）の回帰テスト。
 * 別プロセスで Pest を起動し、RefreshDatabase が DB を作り直す前に中止されることを確かめる。
 * DB 名は存在しない名前（stockflow_guard_probe）にしてあるので、万一ガードが効かなくても実在のデータは消えない
 */
const GUARD_PROBE_DATABASE = 'stockflow_guard_probe';
const GUARD_PROBE_TEST = 'tests/Feature/ExampleTest.php';

/** 別プロセスの出力（環境によって Pest は JSON で出力し、日本語がエスケープされるので戻す） */
function guardProbeOutput(ProcessResult $result): string
{
    $output = $result->output().$result->errorOutput();
    $decoded = json_decode(trim($output), true);

    return is_array($decoded) ? json_encode($decoded, JSON_UNESCAPED_UNICODE) ?: $output : $output;
}

test('APP_ENV=local と開発用 DB が環境変数で与えられたら、テストは DB を作り直す前に中止する', function () {
    $result = Process::path(base_path())
        ->env(['APP_ENV' => 'local', 'DB_DATABASE' => GUARD_PROBE_DATABASE])
        ->timeout(120)
        ->run(['php', 'vendor/bin/pest', GUARD_PROBE_TEST]);

    $output = guardProbeOutput($result);
    expect($result->failed())->toBeTrue()
        ->and($output)->toContain('テスト用 DB ではないため中止しました')
        ->and($output)->toContain('APP_ENV=local')
        ->and($output)->not->toContain('Unknown database');
});

test('設定がキャッシュされていたら（config:cache）、テストは DB を作り直す前に中止する', function () {
    $cachePath = sys_get_temp_dir().'/stockflow-guard-'.bin2hex(random_bytes(6)).'.php';

    try {
        // 開発用の設定（APP_ENV=local・開発用 DB）をキャッシュする。phpunit.xml の環境変数はキャッシュに勝てない
        $cache = Process::path(base_path())
            ->env(['APP_ENV' => 'local', 'DB_DATABASE' => GUARD_PROBE_DATABASE, 'APP_CONFIG_CACHE' => $cachePath])
            ->timeout(120)
            ->run(['php', 'artisan', 'config:cache']);
        expect($cache->successful())->toBeTrue($cache->errorOutput())
            ->and(file_exists($cachePath))->toBeTrue();

        $result = Process::path(base_path())
            ->env(['APP_ENV' => 'testing', 'DB_DATABASE' => 'testing', 'APP_CONFIG_CACHE' => $cachePath])
            ->timeout(120)
            ->run(['php', 'vendor/bin/pest', GUARD_PROBE_TEST]);

        $output = guardProbeOutput($result);
        expect($result->failed())->toBeTrue()
            ->and($output)->toContain('テスト用 DB ではないため中止しました')
            ->and($output)->toContain('設定がキャッシュされています')
            ->and($output)->not->toContain('Unknown database');
    } finally {
        if (file_exists($cachePath)) {
            unlink($cachePath);
        }
    }
});
