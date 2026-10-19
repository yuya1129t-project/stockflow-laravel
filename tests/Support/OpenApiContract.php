<?php

namespace Tests\Support;

use Illuminate\Testing\TestResponse;
use Opis\JsonSchema\Errors\ErrorFormatter;
use Opis\JsonSchema\Validator;
use PHPUnit\Framework\Assert;
use stdClass;

/**
 * REST API の実際の応答を、docs/openapi.json（Next.js 版と共通の契約。変更しない）で検証する（契約テスト用）。
 * Next.js 版 tests/integration/openapi-validator.ts（ajv）と同じ検査を、PHP の JSON Schema 2020-12 の実装（opis/json-schema）で行う。
 *
 * 検出するもの: 定義にない操作・ステータスコード・Content-Type、定義にあるヘッダーの欠落、本文のスキーマ違反
 * （項目の過不足（スキーマは additionalProperties: false）・型・null の可否・format（date・date-time）・pattern・範囲）。
 *
 * - components.schemas は openapi.json をそのまま 1 つの文書（$id = DOC_ID）として登録し、#/components/schemas/… の参照を
 *   その中で解決させる（OpenAPI 3.1 の Schema Object は JSON Schema 2020-12。openapi などほかの項目は未知のキーワードとして無視される）
 * - 検証に通った「メソッド パス ステータス」を checked に記録する（定義した応答をすべて一度は返したかの確認に使う）
 */
final class OpenApiContract
{
    public const string DOC_ID = 'https://stockflow.test/openapi.json';

    public const string DOCUMENT_PATH = __DIR__.'/../../docs/openapi.json';

    /** @var array<string, true> 検証に通った「メソッド パス ステータス」 */
    public static array $checked = [];

    private static ?stdClass $document = null;

    private static ?Validator $validator = null;

    public static function document(): stdClass
    {
        if (self::$document === null) {
            $contents = file_get_contents(self::DOCUMENT_PATH);
            $decoded = json_decode($contents === false ? '' : $contents, false, 512, JSON_THROW_ON_ERROR);
            assert($decoded instanceof stdClass);
            self::$document = $decoded;
        }

        return self::$document;
    }

    /**
     * 定義にある「メソッド パス ステータス」の一覧
     *
     * @return list<string>
     */
    public static function documentedResponses(): array
    {
        $result = [];
        foreach (get_object_vars(self::document()->paths) as $path => $operations) {
            foreach (get_object_vars($operations) as $method => $operation) {
                foreach (array_keys(get_object_vars($operation->responses)) as $status) {
                    $result[] = "{$method} {$path} {$status}";
                }
            }
        }

        return $result;
    }

    /**
     * 定義にある応答のスキーマで、実際の応答（ステータス・Content-Type・ヘッダー・本文）を検証する。本文（JSON を連想配列にしたもの）を返す
     *
     * @param  'get'|'post'  $method
     */
    public static function assertResponse(string $method, string $pathTemplate, TestResponse $response): mixed
    {
        $label = strtoupper($method)." {$pathTemplate}";
        $operation = self::document()->paths->{$pathTemplate}->{$method} ?? null;
        if (! $operation instanceof stdClass) {
            Assert::fail("定義にない操作です: {$label}");
        }
        $status = (string) $response->getStatusCode();
        $responses = get_object_vars($operation->responses);
        if (! array_key_exists($status, $responses)) {
            Assert::fail("定義にないステータスです: {$label} → {$status}（定義: ".implode(', ', array_keys($responses)).'）');
        }
        $spec = self::resolveResponse($responses[$status]);

        $contentType = strtolower(trim(explode(';', (string) $response->headers->get('Content-Type'))[0]));
        $content = isset($spec->content) ? get_object_vars($spec->content) : [];
        if (! array_key_exists($contentType, $content)) {
            Assert::fail("定義にない Content-Type です: {$label} → {$status} {$contentType}（定義: ".implode(', ', array_keys($content)).'）');
        }
        foreach (array_keys(isset($spec->headers) ? get_object_vars($spec->headers) : []) as $header) {
            if (! $response->headers->has($header)) {
                Assert::fail("定義にあるヘッダーがありません: {$label} → {$status} {$header}");
            }
        }

        $raw = (string) $response->getContent();
        $body = json_decode($raw, false);
        if (json_last_error() !== JSON_ERROR_NONE) {
            Assert::fail("本文が JSON ではありません: {$label} → {$status}\n{$raw}");
        }
        $schema = $content[$contentType]->schema;
        $error = self::schemaError($body, isset($schema->{'$ref'}) ? (object) ['$ref' => self::DOC_ID.$schema->{'$ref'}] : $schema);
        if ($error !== null) {
            Assert::fail("応答が定義のスキーマに合いません: {$label} → {$status}\n{$error}\n本文: {$raw}");
        }

        self::$checked["{$method} {$pathTemplate} {$status}"] = true;

        return json_decode($raw, true);
    }

    /** components.schemas の name で値を検証する（スキーマに合えば null、合わなければエラーの説明） */
    public static function schemaErrorFor(string $name, mixed $value): ?string
    {
        return self::schemaError(json_decode((string) json_encode($value), false), (object) ['$ref' => self::DOC_ID."#/components/schemas/{$name}"]);
    }

    private static function schemaError(mixed $data, object $schema): ?string
    {
        $result = self::validator()->validate($data, $schema);
        $error = $result->error();

        return $error === null ? null : (string) json_encode((new ErrorFormatter)->format($error), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
    }

    private static function validator(): Validator
    {
        if (self::$validator === null) {
            $validator = new Validator;
            $validator->setMaxErrors(10);
            $validator->parser()->setOption('defaultDraft', '2020-12');
            $validator->resolver()?->registerRaw(self::document(), self::DOC_ID);
            self::$validator = $validator;
        }

        return self::$validator;
    }

    private static function resolveResponse(mixed $response): stdClass
    {
        assert($response instanceof stdClass);
        $ref = $response->{'$ref'} ?? null;
        if (! is_string($ref)) {
            return $response;
        }
        $name = str_replace('#/components/responses/', '', $ref);
        $resolved = self::document()->components->responses->{$name} ?? null;
        if (! $resolved instanceof stdClass) {
            Assert::fail("応答の参照を解決できません: {$ref}");
        }

        return $resolved;
    }
}
