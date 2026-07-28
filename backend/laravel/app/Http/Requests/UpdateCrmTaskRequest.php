<?php

namespace App\Http\Requests;

use App\Models\CrmTask;
use App\Models\User;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateCrmTaskRequest extends FormRequest
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
            'title' => ['sometimes', 'required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:5000'],
            // Reassignment goes through the same allow list as creation;
            // sending null hands the task back to the unassigned pile.
            'assigned_to_user_id' => ['nullable', 'integer', Rule::in(User::crmOperators()->pluck('id')->all())],
            'status' => ['sometimes', Rule::in(CrmTask::STATUSES)],
            'priority' => ['sometimes', Rule::in(CrmTask::PRIORITIES)],
            'due_at' => ['nullable', 'date'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'assigned_to_user_id.in' => 'That user cannot be assigned CRM tasks.',
        ];
    }
}
