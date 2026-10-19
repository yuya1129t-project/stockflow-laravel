<?php

use App\Auth\Permission;
use App\Enums\Role;

/**
 * 権限表（App\Auth\Permission）が docs/spec.md §2 の表と一致することを固定する。
 * 期待値は spec の Markdown を読み取って作る（spec と実装のどちらかだけを変えると落ちる）
 */

/**
 * spec の §2 の表を「機能 → [admin, staff, viewer] の ○×」に読み取る
 *
 * @return array<string, array<string, bool>>
 */
function specPermissionTable(): array
{
    $spec = file_get_contents(__DIR__.'/../../../docs/spec.md');
    expect($spec)->toBeString();

    $sections = preg_split('/^## /m', (string) $spec) ?: [];
    $section = collect($sections)->first(fn (string $s) => str_starts_with($s, '2. ロールと権限'));
    expect($section)->toBeString('spec.md に「## 2. ロールと権限」が見つかりません');

    $rows = collect(explode("\n", (string) $section))
        ->filter(fn (string $line) => str_starts_with($line, '|'))
        ->map(fn (string $line) => array_map('trim', array_slice(explode('|', $line), 1, -1)))
        ->values();

    expect($rows[0])->toBe(['機能', 'admin', 'staff', 'viewer']);

    $table = [];
    foreach ($rows->slice(2) as [$label, $admin, $staff, $viewer]) {
        foreach ([$admin, $staff, $viewer] as $mark) {
            expect($mark)->toBeIn(['○', '×']);
        }
        $table[$label] = ['admin' => $admin === '○', 'staff' => $staff === '○', 'viewer' => $viewer === '○'];
    }

    return $table;
}

test('spec の表の行と権限定義の操作が 1 対 1 に対応する（9 操作）', function () {
    $labels = array_map(fn (Permission $p) => $p->label(), Permission::cases());

    expect(Permission::cases())->toHaveCount(9);
    expect(collect($labels)->sort()->values()->all())
        ->toBe(collect(array_keys(specPermissionTable()))->sort()->values()->all());
});

test('全 27 通り（操作 9 × ロール 3）の許可が spec の表と一致する', function () {
    $table = specPermissionTable();
    $checked = 0;

    foreach (Permission::cases() as $permission) {
        foreach (Role::cases() as $role) {
            expect($permission->allows($role))
                ->toBe($table[$permission->label()][$role->value], "{$role->value} × {$permission->value}");
            $checked++;
        }
    }

    expect($checked)->toBe(27);
});

test('grantedTo はロールに許可された操作だけを返す', function () {
    expect(Permission::grantedTo(Role::Admin))->toBe(Permission::cases());
    expect(Permission::grantedTo(Role::Viewer))->toBe([Permission::View, Permission::CsvExport]);
    expect(Permission::grantedTo(Role::Staff))->not->toContain(Permission::MasterDeactivate, Permission::UserManage, Permission::AuditView);
});
