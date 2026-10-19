<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use Illuminate\Http\Response;

/**
 * OpenAPI 3.1 の定義（GET /api/v1/openapi.json。認証不要）。docs/openapi.json（Next.js 版と共通の契約）をそのまま返す。
 * API の形だけで、秘密やデータは含まない（/api-docs の閲覧画面もここを読む）
 */
class OpenApiController extends Controller
{
    public const string DOCUMENT_PATH = 'docs/openapi.json';

    public function __invoke(): Response
    {
        $document = file_get_contents(base_path(self::DOCUMENT_PATH));
        if ($document === false) {
            abort(500);
        }

        return new Response($document, 200, ['Content-Type' => 'application/json', 'Cache-Control' => 'no-store']);
    }
}
