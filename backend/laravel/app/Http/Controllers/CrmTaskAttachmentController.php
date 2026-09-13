<?php

namespace App\Http\Controllers;

use App\Models\CrmTask;
use App\Models\CrmTaskAttachment;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class CrmTaskAttachmentController extends Controller
{
    public function store(Request $request, CrmTask $task): RedirectResponse
    {
        $maxKb = (int) config('crm.tasks.files.max_mb', 25) * 1024;
        $validated = $request->validate([
            'files' => ['required', 'array', 'min:1', 'max:'.config('crm.tasks.files.max_per_upload', 8)],
            'files.*' => ['required', 'file', 'max:'.$maxKb, function (string $attribute, mixed $value, $fail): void {
                $this->refuseExecutable($value, $fail);
            }],
            'caption' => ['nullable', 'string', 'max:1000'],
        ]);
        foreach ($validated['files'] as $file) {
            $path = $file->store('crm-tasks/'.$task->id.'/'.now()->format('Y/m'), 'local');
            if (! is_string($path)) {
                continue;
            }
            $name = Str::limit((string) preg_replace('/[\x00-\x1f\/\\\\]+/u', '', $file->getClientOriginalName()), 180, '') ?: 'file';
            $task->attachments()->create([
                'user_id' => $request->user()?->id, 'path' => $path, 'name' => $name,
                'mime' => $file->getMimeType(), 'size' => $file->getSize() ?: 0,
                'kind' => CrmTaskAttachment::kindFor($name), 'caption' => $validated['caption'] ?? null,
            ]);
        }

        return back()->with('success', 'Files attached');
    }

    public function show(CrmTaskAttachment $attachment): BinaryFileResponse
    {
        abort_unless(Storage::disk('local')->exists($attachment->path), 404);
        $disposition = in_array($attachment->kind, ['image', 'video', 'audio'], true) ? 'inline' : 'attachment';

        return response()->file(Storage::disk('local')->path($attachment->path), [
            'Content-Type' => $attachment->mime ?: 'application/octet-stream',
            'Content-Disposition' => $disposition.'; filename="'.str_replace('"', '', $attachment->name).'"',
            'X-Content-Type-Options' => 'nosniff', 'Cache-Control' => 'private, max-age=3600',
        ]);
    }

    public function destroy(CrmTaskAttachment $attachment): RedirectResponse
    {
        $attachment->delete();

        return back()->with('success', 'Attachment deleted');
    }

    private function refuseExecutable(mixed $value, callable $fail): void
    {
        if (! $value instanceof UploadedFile) {
            return;
        }
        $extension = Str::lower(Str::afterLast($value->getClientOriginalName(), '.'));
        if (in_array($extension, (array) config('crm.chat.files.blocked_extensions', []), true)) {
            $fail('Executable files are not accepted.');
        }
    }
}
