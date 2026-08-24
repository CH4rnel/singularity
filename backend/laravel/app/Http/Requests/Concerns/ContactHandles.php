<?php

namespace App\Http\Requests\Concerns;

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
            'evm_address' => ['nullable', 'string', 'regex:/^0x[a-fA-F0-9]{40}$/', $this->unclaimed('evm_address', $ignore)],
            'solana_address' => ['nullable', 'string', 'regex:/^[1-9A-HJ-NP-Za-km-z]{32,44}$/', $this->unclaimed('solana_address', $ignore)],
            'tags' => ['nullable', 'array'],
            'tags.*' => ['string', 'max:50'],
        ];
    }

    /**
     * Nobody on the books answers to this already.
     *
     * A handle and an address each name exactly one person, so a second
     * record carrying one is a duplicate of the first — which is easy to
     * create when a base of hundreds is being added to by hand, and hard to
     * notice afterwards, since the two halves of the person then age apart.
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
            'evm_address.unique' => 'Somebody with this EVM address is already on the books.',
            'solana_address.unique' => 'Somebody with this Solana address is already on the books.',
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

        foreach (['name', 'email', 'evm_address', 'solana_address'] as $field) {
            if ($this->has($field)) {
                $value = trim((string) $this->input($field));
                $normalised[$field] = $value === '' ? null : $value;
            }
        }

        $this->merge($normalised);
    }
}
