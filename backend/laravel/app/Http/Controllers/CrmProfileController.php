<?php

namespace App\Http\Controllers;

use App\Models\CrmOperatorProfile;
use App\Models\CrmTask;
use App\Models\CrmTaskComment;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\File;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

class CrmProfileController extends Controller
{
    public function show(Request $request): Response
    {
        $user = $request->user();
        $profile = CrmOperatorProfile::firstOrNew(['user_id' => $user->id]);
        $completed = CrmTask::query()->where('assigned_to_user_id', $user->id)->whereNull('external_id')->where('status', 'done')->count();
        $rank = match (true) {
            $completed >= 100 => 3, $completed >= 25 => 2, $completed >= 5 => 1, default => 0
        };

        return Inertia::render('crm/Profile', [
            'profile' => $this->profile($user, $profile),
            'stats' => [
                'completed' => $completed,
                'active' => CrmTask::query()->where('assigned_to_user_id', $user->id)->active()->count(),
                'created' => CrmTask::query()->where('created_by_user_id', $user->id)->whereNull('external_id')->count(),
                'comments' => CrmTaskComment::query()->where('user_id', $user->id)->where('created_at', '>=', now()->subDays(30))->count(),
                'rank' => $rank,
            ],
        ]);
    }

    public function update(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'display_name' => ['required', 'string', 'max:80'], 'role' => ['nullable', 'string', 'max:120'],
            'bio' => ['nullable', 'string', 'max:2000'], 'contacts' => ['required', 'array:email,phone,telegram,x,discord,github,website'],
            'contacts.email' => ['nullable', 'email', 'max:254'], 'contacts.phone' => ['nullable', 'string', 'max:40'],
            'contacts.telegram' => ['nullable', 'string', 'max:80'], 'contacts.x' => ['nullable', 'string', 'max:80'],
            'contacts.discord' => ['nullable', 'string', 'max:80'], 'contacts.github' => ['nullable', 'string', 'max:80'],
            'contacts.website' => ['nullable', 'url:http,https', 'max:255'],
            'avatar' => ['nullable', File::image()->extensions(['jpg', 'jpeg', 'png', 'webp'])->max('2mb')], 'remove_avatar' => ['sometimes', 'boolean'],
        ]);
        $user = $request->user();
        $profile = CrmOperatorProfile::firstOrNew(['user_id' => $user->id]);
        $old = $profile->avatar_path;
        $profile->fill(collect($validated)->except(['avatar', 'remove_avatar'])->all());
        $profile->registered_at ??= now();
        if ($request->hasFile('avatar')) {
            $profile->avatar_path = $request->file('avatar')->store("crm-avatars/{$user->id}", 'local');
        } elseif ($request->boolean('remove_avatar')) {
            $profile->avatar_path = null;
        }
        $profile->save();
        if ($old && $old !== $profile->avatar_path) {
            Storage::disk('local')->delete($old);
        }

        return back()->with('success', 'Profile saved');
    }

    public function theme(Request $request): JsonResponse
    {
        $validated = $request->validate(['theme' => ['required', Rule::in(CrmOperatorProfile::THEMES)]]);
        CrmOperatorProfile::updateOrCreate(['user_id' => $request->user()->id], ['theme' => $validated['theme']]);

        return response()->json($validated);
    }

    public function avatar(Request $request): StreamedResponse
    {
        $path = CrmOperatorProfile::where('user_id', $request->user()->id)->value('avatar_path');
        abort_unless($path && Storage::disk('local')->exists($path), 404);

        return Storage::disk('local')->response($path, null, ['Cache-Control' => 'private, no-store', 'X-Content-Type-Options' => 'nosniff']);
    }

    private function profile(User $user, CrmOperatorProfile $profile): array
    {
        return ['display_name' => $profile->display_name ?: $user->name, 'role' => $profile->role, 'bio' => $profile->bio,
            'contacts' => $profile->contacts ?? [], 'theme' => $profile->theme ?: 'cyberia', 'registered_at' => $profile->registered_at?->toIso8601String(),
            'avatar' => $profile->avatar_path ? route('crm.profile.avatar', ['v' => basename($profile->avatar_path)]) : $user->avatar, 'account_email' => $user->email];
    }
}
