<?php

namespace App\Http\Controllers\Api\V1;

use App\Api\V1\ApiDocsPage;
use App\Http\Controllers\Controller;
use Illuminate\Http\Response;

/** REST API の閲覧画面（GET /api-docs。ログイン不要。Swagger UI。App\Api\V1\ApiDocsPage） */
class ApiDocsController extends Controller
{
    public function __invoke(): Response
    {
        return ApiDocsPage::response();
    }
}
