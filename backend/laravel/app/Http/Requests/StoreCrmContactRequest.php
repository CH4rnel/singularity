<?php

namespace App\Http\Requests;

use App\Http\Requests\Concerns\ContactHandles;
use App\Models\CrmContact;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreCrmContactRequest extends FormRequest
{
    use ContactHandles;

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
            ...$this->handleRules(null),
            'type' => ['required', Rule::in(CrmContact::TYPES)],
            'status' => ['required', Rule::in(CrmContact::STATUSES)],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return $this->handleMessages();
    }

    protected function prepareForValidation(): void
    {
        $this->normaliseHandles();
    }
}
