<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class InitializeCheckoutRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return ['checkout_token' => ['required', 'uuid'], 'visitor_id' => ['nullable', 'uuid'], 'customer' => ['required', 'array'], 'customer.first_name' => ['required', 'string', 'max:100'], 'customer.last_name' => ['required', 'string', 'max:100'], 'customer.email' => ['required', 'email:rfc', 'max:255'], 'customer.phone' => ['required', 'string', 'max:50'], 'attendees' => ['sometimes', 'array', 'max:500'], 'attendees.*' => ['array:ticket_type_id,unit_index,seat_index,answers'], 'attendees.*.ticket_type_id' => ['required', 'integer'], 'attendees.*.unit_index' => ['required', 'integer', 'min:0', 'max:19'], 'attendees.*.seat_index' => ['required', 'integer', 'min:0', 'max:499'], 'attendees.*.answers' => ['required', 'array', 'max:30'], 'discount' => ['nullable', 'array:event_id,code'], 'discount.event_id' => ['required_with:discount', 'integer'], 'discount.code' => ['required_with:discount', 'string', 'regex:/^[A-Za-z0-9-]{3,20}$/'], 'items' => ['required', 'array', 'min:1', 'max:25'], 'items.*.ticket_type_id' => ['required', 'integer', 'distinct', 'exists:ticket_types,id'], 'items.*.quantity' => ['required', 'integer', 'min:1', 'max:20']];
    }
}
