<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreProposalCommentRequest extends FormRequest
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
            'body' => 'required|string',
            // One level of threading: the parent must belong to the same
            // proposal and itself be a top-level comment.
            'parent_id' => [
                'nullable',
                'integer',
                Rule::exists('proposal_comments', 'id')
                    ->where('proposal_id', $this->route('proposal')->id)
                    ->whereNull('parent_id'),
            ],
        ];
    }
}
