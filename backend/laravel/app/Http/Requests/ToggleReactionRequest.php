<?php

namespace App\Http\Requests;

use App\Models\Reaction;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ToggleReactionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'reactable_type' => ['required', Rule::in(['proposal', 'comment'])],
            'reactable_id' => ['required', 'integer'],
            'emoji' => ['required', Rule::in(Reaction::PALETTE)],
        ];
    }
}
