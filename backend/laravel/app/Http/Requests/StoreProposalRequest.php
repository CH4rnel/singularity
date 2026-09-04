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
     * A deadline is required, and that is the whole of how a proposal closes:
     * status is computed from it at read time, so a proposal written without
     * one would be open forever with nothing to end it. Seven of them were,
     * until 2026_09_01_040000.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'dao_id' => 'required|exists:daos,id',
            'title' => 'required|string|max:255',
            'description' => 'nullable|string',
            'ends_at' => 'required|date|after:now',
        ];
    }
}
