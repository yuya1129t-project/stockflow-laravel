<?php

namespace App\Enums;

/** ユーザーのロール（docs/spec.md §2） */
enum Role: string
{
    case Admin = 'admin';
    case Staff = 'staff';
    case Viewer = 'viewer';
}
