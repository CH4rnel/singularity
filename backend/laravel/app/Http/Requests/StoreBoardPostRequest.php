<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\File;

class StoreBoardPostRequest extends FormRequest
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
            'title' => $this->route('thread') ? ['prohibited'] : ['required', 'string', 'max:160'],
            'body' => ['nullable', 'required_without:image', 'string', 'max:4000'],
            'image' => ['nullable', File::image()->extensions(['jpg', 'jpeg', 'png', 'webp'])->max('2mb')->dimensions(Rule::dimensions()->maxWidth(2400)->maxHeight(2400))],
            'parent_id' => ['nullable', Rule::exists('board_posts', 'id')->where('board_thread_id', $this->route('thread')?->id)->whereNull('hidden_at')],
        ];
    }
}
