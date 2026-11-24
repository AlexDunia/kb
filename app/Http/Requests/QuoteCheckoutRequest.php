<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class QuoteCheckoutRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return ['discount' => ['nullable', 'array:event_id,code'], 'discount.event_id' => ['required_with:discount', 'integer'], 'discount.code' => ['required_with:discount', 'string', 'regex:/^[A-Za-z0-9-]{3,20}$/'], 'items' => ['required', 'array', 'min:1', 'max:25'], 'items.*.ticket_type_id' => ['required', 'integer', 'distinct', 'exists:ticket_types,id'], 'items.*.quantity' => ['required', 'integer', 'min:1', 'max:20']];
    }
}
