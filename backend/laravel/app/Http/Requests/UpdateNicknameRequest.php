<?php

namespace App\Http\Requests;

use App\Support\ProfileHandle;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateNicknameRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'nickname' => [
                'required',
                'string',
                'regex:/\A'.ProfileHandle::PATTERN.'\z/D',
                Rule::notIn(ProfileHandle::reserved()),
            ],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'nickname.regex' => 'Nicknames are 3-20 characters: lowercase letters, digits and underscores.',
            'nickname.not_in' => 'This nickname is reserved by Cyberia.',
        ];
    }
}
