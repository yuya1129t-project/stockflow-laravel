<?php

namespace App\Http\Requests;

use App\Enums\SalesOrderStatus;
use App\Models\SalesOrder;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\In;

/**
 * 受注のステータス変更の入力（変更先 to と、詳細画面に表示した時点の version）。
 * 遷移できるか・version が今の値と同じかは、ロックした後にサービス（SalesOrderService::changeStatus）で確かめる
 */
class SalesOrderStatusRequest extends FormRequest
{
    /** ルートの can:order.edit に加えて Policy でも確認する */
    public function authorize(): bool
    {
        $order = $this->route('salesOrder');

        return $order instanceof SalesOrder && $this->user()?->can('changeStatus', $order) === true;
    }

    /** @return array<string, list<string|In>> */
    public function rules(): array
    {
        return [
            'to' => ['required', 'string', Rule::in([SalesOrderStatus::Confirmed->value, SalesOrderStatus::Shipped->value, SalesOrderStatus::Cancelled->value])],
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

    public function target(): SalesOrderStatus
    {
        return SalesOrderStatus::from($this->string('to')->toString());
    }

    public function version(): mixed
    {
        return $this->input('version');
    }
}
