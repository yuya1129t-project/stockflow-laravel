<?php

namespace App\Http\Requests;

use App\Models\SalesOrder;
use App\Orders\OrderValidation;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

/**
 * 受注の作成・編集の入力（docs/spec.md §3「SalesOrder」・§8）。明細の規則とエラー文は発注と共通（App\Orders\OrderValidation）。
 *
 * 受注日は作成した日（Asia/Tokyo）で、伝票番号の日付と一致させるため入力では受け取らない。金額（小計・税・合計）も受け取らず、
 * サーバーで明細から計算する。編集の version（画面を開いた時点の updated_at）は検証せず、サービスで今の値と比べる（違えば競合）
 */
class SalesOrderRequest extends FormRequest
{
    /** 作成は create、編集は update の Policy（ルートの can: に加えて二重に確認する） */
    public function authorize(): bool
    {
        $order = $this->editingOrder();

        return $order !== null
            ? $this->user()?->can('update', $order) === true
            : $this->user()?->can('create', SalesOrder::class) === true;
    }

    protected function prepareForValidation(): void
    {
        $this->replace([
            'customer_id' => OrderValidation::normalizeInteger($this->input('customer_id')),
            'due_date' => OrderValidation::normalizeDate($this->input('due_date')),
            'note' => OrderValidation::normalizeNote($this->input('note')),
            'lines' => OrderValidation::normalizeLines($this->input('lines')),
            'version' => $this->input('version'),
        ]);
    }

    /** @return array<string, list<string|ValidationRule|Closure>> */
    public function rules(): array
    {
        return [
            'customer_id' => ['bail', 'required', OrderValidation::idRule('顧客を選択してください')],
            'due_date' => OrderValidation::optionalDateRules(),
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
            'customer_id.required' => '顧客を選択してください',
        ];
    }

    /** @return array<string, string> */
    public function attributes(): array
    {
        return [
            'customer_id' => '顧客',
            'due_date' => '納期',
            'note' => '備考',
            ...OrderValidation::lineAttributes(),
        ];
    }

    /**
     * 検証済みの値（ID・数量・単価は int）
     *
     * @return array{customer_id: int, due_date: string|null, note: string|null, lines: list<array{product_id: int, quantity: int, unit_price: int}>}
     */
    public function salesOrderInput(): array
    {
        /** @var array<string, mixed> $validated */
        $validated = $this->validated();
        $dueDate = $validated['due_date'] ?? null;
        $note = $validated['note'] ?? null;
        $lines = $validated['lines'] ?? [];

        return [
            'customer_id' => (int) (is_string($validated['customer_id'] ?? null) ? $validated['customer_id'] : 0),
            'due_date' => is_string($dueDate) ? $dueDate : null,
            'note' => is_string($note) ? $note : null,
            'lines' => OrderValidation::toLines(is_array($lines) ? $lines : []),
        ];
    }

    /** 編集の version（画面を開いた時点の updated_at。形式はサービスで確かめる） */
    public function version(): mixed
    {
        return $this->input('version');
    }

    private function editingOrder(): ?SalesOrder
    {
        $order = $this->route('salesOrder');

        return $order instanceof SalesOrder ? $order : null;
    }
}
