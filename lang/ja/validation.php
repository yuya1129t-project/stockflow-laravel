<?php

// 入力検証のメッセージ（日本語）。ここにない規則は英語（Laravel 既定）になるので、使う規則を増やしたら追加する
return [
    'boolean' => ':attribute の指定が正しくありません',
    'email' => ':attribute の形式が正しくありません',
    'enum' => ':attribute の値が正しくありません',
    'max' => [
        'string' => ':attribute は :max 文字以内で入力してください',
    ],
    'min' => [
        'string' => ':attribute は :min 文字以上で入力してください',
    ],
    // パスワードの規則（Illuminate\Validation\Rules\Password。本番の Password::defaults で使う）
    'password' => [
        'letters' => ':attribute には英字を 1 文字以上含めてください',
        'mixed' => ':attribute には英字の大文字と小文字をそれぞれ 1 文字以上含めてください',
        'numbers' => ':attribute には数字を 1 文字以上含めてください',
        'symbols' => ':attribute には記号を 1 文字以上含めてください',
        'uncompromised' => 'この :attribute は過去に漏洩したパスワードの一覧に含まれています。別のパスワードにしてください',
    ],
    'regex' => ':attribute の形式が正しくありません',
    'required' => ':attribute を入力してください',
    'string' => ':attribute は文字列で入力してください',
    'unique' => 'この :attribute はすでに使われています',

    'attributes' => [
        'email' => 'メールアドレス',
        'password' => 'パスワード',
    ],
];
