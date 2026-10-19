<?php

namespace App\Http\Requests;

use App\Models\PurchaseOrder;
use App\Orders\OrderValidation;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

/**
 * 発注の作成・編集の入力（docs/spec.md §3「PurchaseOrder」・§8）。明細の規則とエラー文は受注と共通（App\Orders\OrderValidation）。
 *
 * 発注日は作成した日（Asia/Tokyo）で、伝票番号の日付と一致させるため入力では受け取らない。金額（小計・税・合計）も受け取らず、
 * サーバーで明細から計算する。編集の version（画面を開いた時点の updated_at）は検証せず、サービスで今の値と比べる（違えば競合）
 */
class PurchaseOrderRequest extends FormRequest
{
    /** 作成は create、編集は update の Policy（ルートの can: に加えて二重に確認する） */
    public function authorize(): bool
    {
        $order = $this->editingOrder();

        return $order !== null
            ? $this->user()?->can('update', $order) === true
            : $this->user()?->can('create', PurchaseOrder::class) === true;
    }

    protected function prepareForValidation(): void
    {
        $this->replace([
            'supplier_id' => OrderValidation::normalizeInteger($this->input('supplier_id')),
            'expected_date' => OrderValidation::normalizeDate($this->input('expected_date')),
            'note' => OrderValidation::normalizeNote($this->input('note')),
            'lines' => OrderValidation::normalizeLines($this->input('lines')),
            'version' => $this->input('version'),
        ]);
    }

    /** @return array<string, list<string|ValidationRule|Closure>> */
    public function rules(): array
    {
        return [
            'supplier_id' => ['bail', 'required', OrderValidation::idRule('仕入先を選択してください')],
            'expected_date' => OrderValidation::optionalDateRules(),
            'note' => OrderValidation::noteRules(),
            ...OrderValidation::lineRules(),
        ];
    }

    /** @return list<Closure(Validator): void> */
    public function after(): array
    {
        return [OrderValidation::checkLines(...)];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            ...OrderValidation::messages(),
            'supplier_id.required' => '仕入先を選択してください',
        ];
    }

    /** @return array<string, string> */
    public function attributes(): array
    {
        return [
            'supplier_id' => '仕入先',
            'expected_date' => '入荷予定日',
            'note' => '備考',
            ...OrderValidation::lineAttributes(),
        ];
    }

    /**
     * 検証済みの値（ID・数量・単価は int）
     *
     * @return array{supplier_id: int, expected_date: string|null, note: string|null, lines: list<array{product_id: int, quantity: int, unit_price: int}>}
     */
    public function purchaseOrderInput(): array
    {
        /** @var array<string, mixed> $validated */
        $validated = $this->validated();
        $expectedDate = $validated['expected_date'] ?? null;
        $note = $validated['note'] ?? null;
        $lines = $validated['lines'] ?? [];

        return [
            'supplier_id' => (int) (is_string($validated['supplier_id'] ?? null) ? $validated['supplier_id'] : 0),
            'expected_date' => is_string($expectedDate) ? $expectedDate : null,
            'note' => is_string($note) ? $note : null,
            'lines' => OrderValidation::toLines(is_array($lines) ? $lines : []),
        ];
    }

    /** 編集の version（画面を開いた時点の updated_at。形式はサービスで確かめる） */
    public function version(): mixed
    {
        return $this->input('version');
    }

    private function editingOrder(): ?PurchaseOrder
    {
        $order = $this->route('purchaseOrder');

        return $order instanceof PurchaseOrder ? $order : null;
    }
}
