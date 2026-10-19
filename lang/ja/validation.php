<?php

// 入力検証のメッセージ（日本語）。ここにない規則は英語（Laravel 既定）になるので、使う規則を増やしたら追加する
return [
    'email' => ':attribute の形式が正しくありません',
    'required' => ':attribute を入力してください',
    'string' => ':attribute は文字列で入力してください',

    'attributes' => [
        'email' => 'メールアドレス',
        'password' => 'パスワード',
    ],
];
