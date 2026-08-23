<?php

namespace App\Http\Requests;

use App\Http\Requests\Concerns\ContactHandles;
use App\Models\CrmContact;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateCrmContactRequest extends FormRequest
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
        $contact = $this->route('contact');

        return [
            // Editing a record must not collide with the record being edited.
            ...$this->handleRules($contact instanceof CrmContact ? $contact->id : null),
            'type' => ['sometimes', Rule::in(CrmContact::TYPES)],
            'status' => ['sometimes', Rule::in(CrmContact::STATUSES)],
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
