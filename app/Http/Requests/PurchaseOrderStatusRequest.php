<?php

namespace App\Http\Requests;

use App\Enums\PurchaseOrderStatus;
use App\Models\PurchaseOrder;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\In;

/**
 * 発注のステータス変更の入力（変更先 to と、詳細画面に表示した時点の version）。
 * 遷移できるか・version が今の値と同じかは、ロックした後にサービス（PurchaseOrderService::changeStatus）で確かめる
 */
class PurchaseOrderStatusRequest extends FormRequest
{
    /** ルートの can:order.edit に加えて Policy でも確認する */
    public function authorize(): bool
    {
        $order = $this->route('purchaseOrder');

        return $order instanceof PurchaseOrder && $this->user()?->can('changeStatus', $order) === true;
    }

    /** @return array<string, list<string|In>> */
    public function rules(): array
    {
        return [
            'to' => ['required', 'string', Rule::in([PurchaseOrderStatus::Ordered->value, PurchaseOrderStatus::Received->value, PurchaseOrderStatus::Cancelled->value])],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'to.required' => '変更先のステータスが正しくありません',
            'to.string' => '変更先のステータスが正しくありません',
            'to.in' => '変更先のステータスが正しくありません',
        ];
    }

    public function target(): PurchaseOrderStatus
    {
        return PurchaseOrderStatus::from($this->string('to')->toString());
    }

    public function version(): mixed
    {
        return $this->input('version');
    }
}
