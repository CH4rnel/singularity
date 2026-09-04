<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdateProposalRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * The deadline may be moved in either direction — back to end a vote
     * early, forward to reopen one — but never emptied: a proposal without a
     * deadline is a proposal nothing can close.
     */
    public function rules(): array
    {
        return [
            'title' => 'sometimes|required|string|max:255',
            'description' => 'nullable|string',
            'ends_at' => 'sometimes|required|date',
        ];
    }
}
