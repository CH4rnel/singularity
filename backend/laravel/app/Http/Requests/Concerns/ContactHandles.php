<?php

namespace App\Http\Requests\Concerns;

use App\Support\CrmContactUrl;
use App\Support\Handles;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Unique;

/**
 * The handle fields of a contact, shared by the two requests that write them.
 *
 * Create and edit are the same form on two screens, and a field that is
 * normalised on the way in but not on the way back out would let an edit
 * quietly undo what the create had cleaned up.
 */
trait ContactHandles
{
    /**
     * @return array<string, array<int, mixed>>
     */
    protected function handleRules(?int $ignore = null): array
    {
        return [
            'name' => ['nullable', 'string', 'max:255'],
            'email' => ['nullable', 'email', 'max:255'],
            'telegram' => ['nullable', 'string', 'max:255', $this->unclaimed('telegram', $ignore)],
            // X allows letters, digits and underscore up to fifteen; anything
            // else was a URL we failed to read rather than a handle.
            'x_handle' => ['nullable', 'string', 'regex:/^[A-Za-z0-9_]{1,15}$/', $this->unclaimed('x_handle', $ignore)],
            // No `unclaimed` on either address, deliberately — see below.
            'evm_address' => ['nullable', 'string', 'regex:/^0x[a-fA-F0-9]{40}$/'],
            'solana_address' => ['nullable', 'string', 'regex:/^[1-9A-HJ-NP-Za-km-z]{32,44}$/'],
            'tags' => ['nullable', 'array'],
            'tags.*' => ['string', 'max:50'],
            'contact_link_url' => [
                'nullable',
                'string',
                'max:2048',
                function (string $attribute, mixed $value, \Closure $fail): void {
                    if ($value !== null && CrmContactUrl::normalise($value) === null) {
                        $fail('Use an http(s), mailto or tel contact link.');
                    }
                },
            ],
            'contact_link_label' => ['nullable', 'string', 'max:80'],
        ];
    }

    /**
     * Nobody on the books answers to this already — for handles only.
     *
     * A handle is an account and an account is one person: `@fomo_person`
     * typed onto a second record is the first record again, which is easy to
     * do when a base of hundreds is entered by hand and hard to notice
     * afterwards, since the two halves then age apart.
     *
     * **An address is not that.** It is a place value can sit, and more than
     * one person can stand behind it: an exchange deposit address, a shared
     * or custodial wallet, a treasury several people are filed against, a
     * whale whose leads are tracked separately. Refusing the second record
     * there refuses a fact about the world — and the console already has the
     * right instrument for saying "these are one person": the identity graph,
     * which joins records through the address they share and prints them on
     * the dossier as such, with the evidence, instead of forbidding the entry
     * and losing it.
     *
     * Soft-deleted rows are ignored: a record somebody deleted is not a
     * person they may not add again.
     */
    private function unclaimed(string $column, ?int $ignore): Unique
    {
        return Rule::unique('crm_contacts', $column)
            ->whereNull('deleted_at')
            ->ignore($ignore);
    }

    /**
     * @return array<string, string>
     */
    protected function handleMessages(): array
    {
        return [
            'telegram.unique' => 'Somebody with this Telegram is already on the books.',
            'x_handle.unique' => 'Somebody with this X handle is already on the books.',
        ];
    }

    /**
     * Read the pasted profile link before the rules see it.
     *
     * An empty field arrives as `''` from a form and as absent from a script;
     * both mean "no handle", and only `null` survives a nullable column
     * without turning into an empty string nobody can search for.
     */
    protected function normaliseHandles(): void
    {
        $normalised = [];

        if ($this->has('telegram')) {
            $normalised['telegram'] = Handles::telegram($this->input('telegram'));
        }

        if ($this->has('x_handle')) {
            $normalised['x_handle'] = Handles::x($this->input('x_handle'));
        }

        if ($this->has('contact_link_url')) {
            $rawUrl = trim((string) $this->input('contact_link_url'));
            $normalised['contact_link_url'] = $rawUrl === ''
                ? null
                : (CrmContactUrl::normalise($rawUrl) ?? $rawUrl);
        }

        if ($this->has('contact_link_label')) {
            $label = trim((string) $this->input('contact_link_label'));
            $normalised['contact_link_label'] = $label === '' ? null : $label;
        }

        foreach (['name', 'email', 'evm_address', 'solana_address'] as $field) {
            if ($this->has($field)) {
                $value = trim((string) $this->input($field));
                $normalised[$field] = $value === '' ? null : $value;
            }
        }

        $this->merge($normalised);
    }
}
