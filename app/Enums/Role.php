<?php

namespace App\Enums;

/** ユーザーのロール（docs/spec.md §2）。ロールごとの権限は App\Auth\Permission */
enum Role: string
{
    case Admin = 'admin';
    case Staff = 'staff';
    case Viewer = 'viewer';

    /** 画面に出すロール名 */
    public function label(): string
    {
        return match ($this) {
            self::Admin => '管理者',
            self::Staff => '担当者',
            self::Viewer => '閲覧者',
        };
    }
}
