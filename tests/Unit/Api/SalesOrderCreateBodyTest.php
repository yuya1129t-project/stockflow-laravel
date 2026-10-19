<?php

use App\Api\V1\ApiJsonBody;
use App\Api\V1\ApiQuery;
use App\Api\V1\SalesOrderApi;
use App\Api\V1\SalesOrderCreateBody;
use App\Orders\OrderValidation;
use Tests\Support\OpenApiContract;

/**
 * REST API の入力の規則（本文 SalesOrderCreateBody・クエリ ApiQuery・本文の読み取り ApiJsonBody）の上限が、
 * docs/openapi.json（Next.js 版と共通の契約）の値と一致することの確認と、検査の部品の単体テスト
 */
function openApiSchema(string $name): stdClass
{
    return OpenApiContract::document()->components->schemas->{$name};
}

test('受注の登録の本文の上限は openapi.json の SalesOrderCreate と同じ', function () {
    $create = openApiSchema('SalesOrderCreate');
    $line = openApiSchema('SalesOrderCreateLine');

    expect($create->properties->customerCode->maxLength)->toBe(SalesOrderCreateBody::MAX_CODE)
        ->and($create->properties->customerCode->minLength)->toBe(1)
        ->and($line->properties->productCode->maxLength)->toBe(SalesOrderCreateBody::MAX_CODE)
        ->and($line->properties->productCode->minLength)->toBe(1)
        ->and($line->properties->quantity->minimum)->toBe(1)
        ->and($line->properties->quantity->maximum)->toBe(OrderValidation::MAX_QUANTITY)
        ->and($create->properties->lines->minItems)->toBe(1)
        ->and($create->properties->lines->maxItems)->toBe(OrderValidation::MAX_LINES)
        ->and($create->properties->note->anyOf[0]->maxLength)->toBe(OrderValidation::MAX_NOTE)
        ->and($create->required)->toBe(['customerCode', 'lines'])
        ->and($line->required)->toBe(['productCode', 'quantity'])
        ->and(array_keys(get_object_vars($create->properties)))->toBe(['customerCode', 'dueDate', 'note', 'lines'])
        ->and(array_keys(get_object_vars($line->properties)))->toBe(['productCode', 'quantity'])
        ->and($create->additionalProperties)->toBeFalse()
        ->and($line->additionalProperties)->toBeFalse();
});

test('クエリ・本文の上限は openapi.json と同じ', function () {
    $doc = OpenApiContract::document();
    $parameters = $doc->components->parameters;
    $products = $doc->paths->{'/api/v1/products'}->get->parameters;
    $orders = $doc->paths->{'/api/v1/sales-orders'}->get->parameters;

    expect($parameters->page->schema->maximum)->toBe(ApiQuery::PAGE_MAX)
        ->and($parameters->page->schema->default)->toBe(1)
        ->and($parameters->perPage->schema->maximum)->toBe(ApiQuery::PER_PAGE_MAX)
        ->and($parameters->perPage->schema->default)->toBe(ApiQuery::PER_PAGE_DEFAULT)
        ->and($products[0]->name)->toBe('q')
        ->and($products[0]->schema->maxLength)->toBe(ApiQuery::QUERY_MAX_LENGTH)
        ->and($orders[0]->schema->enum)->toBe(['draft', 'confirmed', 'shipped', 'cancelled'])
        ->and($doc->components->responses->ContentTooLarge->description)->toContain((string) ApiJsonBody::MAX_BYTES);
});

test('日付の受け付ける範囲は openapi.json の pattern（2000〜2099 年）と同じ', function (string $value, bool $accepted) {
    $pattern = OpenApiContract::document()->components->schemas->SalesOrder->properties->orderDate->pattern;

    expect(ApiQuery::isDate($value))->toBe($accepted)
        // pattern に合わないものは必ず拒否する（pattern に合っても実在しない日付は format: date で拒否される）
        ->and(! $accepted || preg_match('/'.$pattern.'/', $value) === 1)->toBeTrue();
})->with([
    ['2000-01-01', true],
    ['2099-12-31', true],
    ['2028-02-29', true],
    ['1999-12-31', false],
    ['2100-01-01', false],
    ['2027-02-29', false],
    ['2026-13-01', false],
    ['2026-1-01', false],
    ["2026-10-19\n", false],
    ['２０２６-10-19', false],
]);

test('クエリ文字列は同じ名前の値をすべて残す（+ は空白、%00 は NUL）', function () {
    expect(ApiQuery::parse('page=1&page=2&q=a+b&x&=v&q2=%00&&'))->toBe([
        'page' => ['1', '2'],
        'q' => ['a b'],
        'x' => [''],
        '' => ['v'],
        'q2' => ["\0"],
    ]);
});

test('JSON の Content-Type の判定', function (?string $contentType, bool $json) {
    expect(ApiJsonBody::isJsonMediaType($contentType))->toBe($json);
})->with([
    ['application/json', true],
    ['Application/JSON', true],
    ['application/json; charset=utf-8', true],
    ['application/json;charset="UTF-8"', true],
    ['application/json; foo=bar', true],
    ['application/problem+json', true],
    ['application/json; charset=shift_jis', false],
    ['text/json', false],
    ['text/plain', false],
    ['application/jsonx', false],
    ['', false],
    [null, false],
]);

test('JSON Pointer（RFC 6901 の ~ と / のエスケープ）', function () {
    expect(ApiJsonBody::pointer([]))->toBe('#')
        ->and(ApiJsonBody::pointer(['lines', 0, 'quantity']))->toBe('#/lines/0/quantity')
        ->and(ApiJsonBody::pointer(['a/b', 'c~d']))->toBe('#/a~1b/c~0d');
});

test('画面の項目のキーを本文の JSON Pointer に読み替える', function (string $key, ?string $pointer) {
    expect(SalesOrderApi::pointerFor($key))->toBe($pointer);
})->with([
    ['customer_id', '#/customerCode'],
    ['due_date', '#/dueDate'],
    ['note', '#/note'],
    ['lines', '#/lines'],
    ['lines.3.product_id', '#/lines/3/productCode'],
    ['lines.3.unit_price', '#/lines/3/productCode'],
    ['lines.3.quantity', '#/lines/3/quantity'],
    ['form', null],
    ['conflict', null],
]);
