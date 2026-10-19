<?php

/**
 * CORS（ブラウザからの別オリジンの要求）。REST API（/api/v1。docs/spec.md §6-2）はサーバー間の利用を想定し、許可しない
 * （Next.js 版と同じ。Laravel の既定は api/* に Access-Control-Allow-Origin: * を返す）。
 * /api-docs の Swagger UI は同じオリジンから呼ぶので CORS は要らない
 */
return [

    'paths' => [],

    'allowed_methods' => ['*'],

    'allowed_origins' => [],

    'allowed_origins_patterns' => [],

    'allowed_headers' => ['*'],

    'exposed_headers' => [],

    'max_age' => 0,

    'supports_credentials' => false,

];
