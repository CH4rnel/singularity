<?php

use App\Models\CrmTask;
use App\Models\CrmTaskAttachment;
use App\Models\CrmTaskAttachmentComment;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

test('an operator can attach private media and comment under it', function () {
    Storage::fake('local');
    $operator = User::factory()->crmAdmin()->create();
    $task = CrmTask::factory()->create();
    $this->actingAs($operator)->post(route('crm.tasks.attachments.store', $task), [
        'files' => [UploadedFile::fake()->image('screen.jpg', 800, 600)], 'caption' => 'Broken state',
    ])->assertSessionHasNoErrors();
    $file = CrmTaskAttachment::sole();
    expect($file->kind)->toBe('image')->and($file->caption)->toBe('Broken state');
    Storage::disk('local')->assertExists($file->path);
    $this->get(route('crm.tasks.attachments.show', $file))->assertOk()->assertHeader('X-Content-Type-Options', 'nosniff');
    $this->post(route('crm.tasks.attachments.comments.store', $file), ['body' => 'Fixed in the next build'])->assertSessionHasNoErrors();
    $this->get(route('crm.tasks.show', $task))->assertInertia(fn ($page) => $page
        ->where('task.attachments.0.name', 'screen.jpg')->where('task.attachments.0.comments.0.body', 'Fixed in the next build'));
});

test('task files stay behind the CRM gate and executables are refused', function () {
    Storage::fake('local');
    $task = CrmTask::factory()->create();
    $stranger = User::factory()->create();
    $this->actingAs($stranger)->post(route('crm.tasks.attachments.store', $task), ['files' => [UploadedFile::fake()->create('data.txt')]])->assertNotFound();
    $operator = User::factory()->crmAdmin()->create();
    $this->actingAs($operator)->post(route('crm.tasks.attachments.store', $task), ['files' => [UploadedFile::fake()->create('run.exe')]])->assertSessionHasErrors('files.0');
    expect(CrmTaskAttachment::count())->toBe(0);
});

test('task uploads accept groups of files up to five megabytes each', function () {
    Storage::fake('local');
    $operator = User::factory()->crmAdmin()->create();
    $task = CrmTask::factory()->create();

    $this->actingAs($operator)->post(route('crm.tasks.attachments.store', $task), [
        'files' => [
            UploadedFile::fake()->create('first.png', 5 * 1024, 'image/png'),
            UploadedFile::fake()->create('second.png', 5 * 1024, 'image/png'),
        ],
    ])->assertSessionHasNoErrors();

    expect(CrmTaskAttachment::count())->toBe(2);

    $this->post(route('crm.tasks.attachments.store', $task), [
        'files' => [UploadedFile::fake()->create('too-large.png', (5 * 1024) + 1, 'image/png')],
    ])->assertSessionHasErrors('files.0');

    expect(CrmTaskAttachment::count())->toBe(2);
});

test('deleting an attachment removes its bytes and comments', function () {
    Storage::fake('local');
    $operator = User::factory()->crmAdmin()->create();
    $task = CrmTask::factory()->create();
    $this->actingAs($operator)->post(route('crm.tasks.attachments.store', $task), ['files' => [UploadedFile::fake()->create('notes.pdf')]]);
    $file = CrmTaskAttachment::sole();
    $this->post(route('crm.tasks.attachments.comments.store', $file), ['body' => 'Reviewed']);
    $this->delete(route('crm.tasks.attachments.destroy', $file))->assertRedirect();
    Storage::disk('local')->assertMissing($file->path);
    expect(CrmTaskAttachment::count())->toBe(0)->and(CrmTaskAttachmentComment::count())->toBe(0);
});

test('deleting a task removes all attachment bytes', function () {
    Storage::fake('local');
    $operator = User::factory()->crmAdmin()->create();
    $task = CrmTask::factory()->create();
    $this->actingAs($operator)->post(route('crm.tasks.attachments.store', $task), ['files' => [UploadedFile::fake()->create('brief.pdf')]]);
    $path = CrmTaskAttachment::sole()->path;
    $this->delete(route('crm.tasks.destroy', $task))->assertRedirect(route('crm.tasks.index'));
    Storage::disk('local')->assertMissing($path);
});
