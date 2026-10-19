/** ログイン中のユーザー（HandleInertiaRequests が渡す項目） */
export type User = {
    id: number;
    name: string;
    email: string;
    role: 'admin' | 'staff' | 'viewer';
    /** 画面に出すロール名（管理者・担当者・閲覧者） */
    roleLabel: string;
    avatar?: string;
};

export type Auth = {
    user: User;
    /** ログイン中のユーザーに許可された操作（App\Auth\Permission の値）。表示の切り替え用で、検査はサーバー側で行う */
    permissions: string[];
};
