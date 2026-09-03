<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StorePushSubscriptionRequest extends FormRequest
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
            'endpoint' => ['required', 'url', 'max:500'],
            'keys' => ['required', 'array'],
            'keys.p256dh' => ['required', 'string'],
            'keys.auth' => ['required', 'string'],
            // The browser's own language, sent once here because this is the
            // only moment it talks to us about something the server will later
            // act on without it. See the notification_locale migration.
            'locale' => ['sometimes', 'nullable', 'string', 'max:12'],
        ];
    }
}
