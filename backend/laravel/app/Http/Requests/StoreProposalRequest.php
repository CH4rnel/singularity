<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class StoreProposalRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'dao_id' => 'required|exists:daos,id',
            'title' => 'required|string|max:255',
            'description' => 'nullable|string',
            'ends_at' => 'nullable|date|after:now',
        ];
    }
}
