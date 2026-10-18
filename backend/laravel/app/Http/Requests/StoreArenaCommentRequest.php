<?php

namespace App\Http\Requests;

use App\Models\ArenaSeason;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreArenaCommentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) config('arena.enabled') && $this->user() !== null;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $season = ArenaSeason::query()->where('slug', config('arena.slug'))->first();

        return [
            'body' => ['required', 'string', 'max:2000'],
            'parent_id' => ['nullable', 'integer', Rule::exists('arena_comments', 'id')
                ->where('arena_season_id', $season?->id ?? 0)->whereNull('parent_id')->whereNull('hidden_at')],
        ];
    }
}
