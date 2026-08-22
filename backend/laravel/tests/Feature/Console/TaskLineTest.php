<?php

use App\Models\CrmContact;
use App\Models\User;
use App\Services\Console\TaskLine;

/**
 * The one-line composer.
 *
 * Forgiving on purpose: a task with a clumsy title is worth infinitely more
 * than a rejected one, so nothing here ever fails — an `@name` that matches
 * nobody stays in the sentence.
 */
beforeEach(function () {
    config()->set('crm.admin_wallets', ['0x00000000000000000000000000000000000000aa']);
    config()->set('crm.console.timezone', 'Europe/Moscow');
});

it('lifts the assignee, the date and the person out of the sentence', function () {
    $operator = User::factory()->create([
        'name' => 'lain',
        'wallet_address' => '0x00000000000000000000000000000000000000aa',
    ]);
    $contact = CrmContact::factory()->create(['name' => 'Nakamoto Ghost']);

    $parsed = TaskLine::parse('написать про лимиты моста @lain !завтра #Nakamoto');

    expect($parsed['title'])->toBe('написать про лимиты моста')
        ->and($parsed['assigned_to_user_id'])->toBe($operator->id)
        ->and($parsed['crm_contact_id'])->toBe($contact->id)
        ->and($parsed['due_at'])->not->toBeNull()
        ->and($parsed['unresolved'])->toBe([]);
});

it('reads both languages of "tomorrow" and a written date', function () {
    expect(TaskLine::parse('x !tomorrow')['due_at'])->not->toBeNull()
        ->and(TaskLine::parse('x !завтра')['due_at'])->not->toBeNull()
        ->and(TaskLine::parse('x !28.08')['due_at'])->toContain('-08-28')
        ->and(TaskLine::parse('x !2026-09-01')['due_at'])->toContain('2026-09-01');
});

it('keeps a token that matches nothing rather than throwing the line away', function () {
    $parsed = TaskLine::parse('проверить выплату @никого #никому');

    expect($parsed['title'])->toBe('проверить выплату @никого #никому')
        ->and($parsed['assigned_to_user_id'])->toBeNull()
        ->and($parsed['unresolved'])->toBe(['@никого', '#никому']);
});

it('leaves a plain sentence exactly as it was typed', function () {
    expect(TaskLine::parse('обновить сертификаты explorer и rpc'))
        ->toMatchArray([
            'title' => 'обновить сертификаты explorer и rpc',
            'assigned_to_user_id' => null,
            'due_at' => null,
            'crm_contact_id' => null,
        ]);
});
