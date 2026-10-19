<?php

namespace App\Support\Database;

/** DestructiveOperationGuard の判定結果 */
final readonly class GuardResult
{
    private function __construct(
        public bool $ok,
        /** 表示用の接続先（host:port/db。パスワードは含めない） */
        public string $target,
        /** 拒否の理由（許可のときは null） */
        public ?string $reason,
    ) {}

    public static function allow(string $target): self
    {
        return new self(true, $target, null);
    }

    public static function refuse(string $reason, string $target): self
    {
        return new self(false, $target, $reason);
    }
}
