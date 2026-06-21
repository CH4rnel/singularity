<?php

namespace App\Http\Requests;

use App\Models\CrmContact;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreCrmContactRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'name' => ['nullable', 'string', 'max:255'],
            'email' => ['nullable', 'email', 'max:255'],
            'telegram' => ['nullable', 'string', 'max:255'],
            'evm_address' => ['nullable', 'string', 'regex:/^0x[a-fA-F0-9]{40}$/'],
            'solana_address' => ['nullable', 'string', 'regex:/^[1-9A-HJ-NP-Za-km-z]{32,44}$/'],
            'type' => ['required', Rule::in(CrmContact::TYPES)],
            'status' => ['required', Rule::in(CrmContact::STATUSES)],
            'tags' => ['nullable', 'array'],
            'tags.*' => ['string', 'max:50'],
        ];
    }
}
