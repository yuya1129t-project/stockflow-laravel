<?php

namespace App\Users;

use App\Models\User;
use Database\Seeders\Demo\DemoData;

/**
 * デモ用アカウント（シードの admin／staff／viewer。Database\Seeders\Demo\DemoData::USERS）。
 * 公開デモでは admin のパスワードも公開するので、閲覧者がほかの閲覧者のデモを壊せないよう、
 * 無効化・ロール変更・パスワード再設定を拒否する（API トークンの発行・失効はできる。REST API を試せるように）。
 *
 * - メールアドレスで判定する（ID はシードの投入で決まるため）。メールアドレスは画面からも API からも変更できない
 *   （アカウントの設定は外観だけ。routes/settings.php）ので、変更して保護を外すことはできない
 * - 環境を問わず常に有効（デモ専用の挙動）
 */
final class DemoAccounts
{
    public const string PROTECTED_MESSAGE = 'デモ用アカウントは変更できません（ほかの閲覧者もデモで使うため）。新しいユーザーを登録して試してください';

    public static function isDemo(User $user): bool
    {
        return in_array(strtolower($user->email), self::emails(), true);
    }

    /** @return list<string> */
    public static function emails(): array
    {
        return array_map(fn (array $user): string => $user['email'], DemoData::USERS);
    }
}
