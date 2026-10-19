/** 画面に渡すユーザー（UserController::toProps） */
export type ManagedUser = {
    id: number;
    name: string;
    email: string;
    role: 'admin' | 'staff' | 'viewer';
    roleLabel: string;
    isActive: boolean;
    createdAt: string;
    /** デモ用アカウント（無効化・ロール変更・パスワード再設定はできない） */
    isDemo: boolean;
    /** ログイン中のユーザー自身か */
    isSelf: boolean;
};

export type RoleOption = { value: ManagedUser['role']; label: string };

/** API トークンの一覧の 1 件（トークン本体・ハッシュは含まない） */
export type ApiTokenItem = {
    id: number;
    name: string;
    createdAt: string;
    lastUsedAt: string | null;
    revokedAt: string | null;
};

/** 入力欄の文字数の上限（画面の maxlength。検証はサーバー側の App\Users\UserValidation） */
export type UserLimits = {
    name: number;
    email: number;
    token_name: number;
};

/** API トークンの発行の応答（token は平文。この応答でしか受け取れない） */
export type IssuedApiToken = { id: number; name: string; token: string };
