<?php

namespace App\Services\Console;

use App\Models\CrmContact;
use App\Models\CrmIdentityLink;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Who is one person.
 *
 * The console shows records; this answers the question records cannot, which
 * is how many people they are. A person is a **connected component** over
 * `crm_identity_links` — computed on read by union-find, never stored — so the
 * answer changes the instant an edge is added or withdrawn, and re-deriving
 * the whole graph is safe at any time.
 *
 * Computing it on read is affordable because the graph is small by nature: it
 * has one node per address or account anyone has ever transacted under, which
 * on this chain is hundreds. The day it is not, this is the class that grows a
 * cache, and nothing that reads it has to change.
 *
 * Only `strong` edges join a component. A `weak` one — a bridge recipient,
 * which may perfectly well be a friend being paid — is carried separately as a
 * suggestion, because quietly merging two customers on a guess is a worse
 * failure than showing one customer twice.
 */
class IdentityGraph
{
    /** @var array<string, string> */
    private array $parent = [];

    /** @var array<string, array<int, CrmIdentityLink>>|null */
    private ?array $edgesByNode = null;

    private bool $built = false;

    /* ------------------------------------------------------------ nodes -- */

    public static function node(string $kind, string $value): string
    {
        return $kind.':'.CrmIdentityLink::normalize($kind, $value);
    }

    /**
     * Every identity a contact record carries.
     *
     * @return array<int, string>
     */
    public static function nodesOf(CrmContact $contact): array
    {
        return array_values(array_filter([
            $contact->user_id ? self::node('user', (string) $contact->user_id) : null,
            $contact->evm_address ? self::node('evm', $contact->evm_address) : null,
            $contact->solana_address ? self::node('solana', $contact->solana_address) : null,
        ]));
    }

    /* ------------------------------------------------------- union-find -- */

    private function build(): void
    {
        if ($this->built) {
            return;
        }

        $this->built = true;
        $this->edgesByNode = [];

        foreach (CrmIdentityLink::query()->get() as $link) {
            $a = self::node($link->left_kind, $link->left_value);
            $b = self::node($link->right_kind, $link->right_value);

            foreach ([$a, $b] as $node) {
                $this->edgesByNode[$node][] = $link;
            }

            if ($link->confidence === 'strong') {
                $this->union($a, $b);
            }
        }
    }

    private function find(string $node): string
    {
        if (! isset($this->parent[$node])) {
            return $this->parent[$node] = $node;
        }

        while ($this->parent[$node] !== $node) {
            $this->parent[$node] = $this->parent[$this->parent[$node]];
            $node = $this->parent[$node];
        }

        return $node;
    }

    private function union(string $a, string $b): void
    {
        $rootA = $this->find($a);
        $rootB = $this->find($b);

        if ($rootA !== $rootB) {
            $this->parent[$rootB] = $rootA;
        }
    }

    /** Whether two identities are the same person, as far as anyone has said. */
    public function samePerson(string $a, string $b): bool
    {
        $this->build();

        return $this->find($a) === $this->find($b);
    }

    /* ------------------------------------------------------- questions -- */

    /**
     * Every contact record that belongs to the same person as this one.
     *
     * Excludes the contact asked about. Returns records, because that is what
     * a console page can link to — the component itself is an implementation
     * detail nobody needs to see.
     *
     * @return Collection<int, CrmContact>
     */
    public function contactsWith(CrmContact $contact): Collection
    {
        $this->build();

        $mine = self::nodesOf($contact);

        if ($mine === []) {
            return collect();
        }

        $roots = array_unique(array_map($this->find(...), $mine));

        return CrmContact::query()
            ->whereKeyNot($contact->getKey())
            ->get()
            ->filter(function (CrmContact $other) use ($roots): bool {
                foreach (self::nodesOf($other) as $node) {
                    if (in_array($this->find($node), $roots, true)) {
                        return true;
                    }
                }

                return false;
            })
            ->values();
    }

    /**
     * The edges that touch this contact, for showing why.
     *
     * A link the console cannot justify is a link nobody will trust the second
     * time it is surprising, so every row carries its source and its evidence.
     *
     * @return Collection<int, CrmIdentityLink>
     */
    public function edgesFor(CrmContact $contact): Collection
    {
        $this->build();

        $mine = self::nodesOf($contact);
        $seen = [];
        $out = [];

        foreach ($mine as $node) {
            foreach ($this->edgesByNode[$node] ?? [] as $link) {
                if (! isset($seen[$link->id])) {
                    $seen[$link->id] = true;
                    $out[] = $link;
                }
            }
        }

        return collect($out);
    }

    /**
     * The site account behind an address, if anyone has established one.
     *
     * This is what makes an address addressable: a notification has to reach a
     * `users` row, and a wallet address is not one. Direct ownership first —
     * an account with this key attached needs no graph at all — then whatever
     * the graph has been told.
     */
    public function userForAddress(string $kind, string $address): ?User
    {
        if ($kind === 'evm') {
            $direct = User::query()->whereRaw('LOWER(wallet_address) = ?', [strtolower($address)])->first();

            if ($direct !== null) {
                return $direct;
            }
        }

        $this->build();

        $root = $this->find(self::node($kind, $address));

        foreach (CrmIdentityLink::query()->get() as $link) {
            foreach ([[$link->left_kind, $link->left_value], [$link->right_kind, $link->right_value]] as [$k, $v]) {
                if ($k === 'user' && $this->find(self::node($k, $v)) === $root) {
                    $user = User::find((int) $v);

                    if ($user !== null) {
                        return $user;
                    }
                }
            }
        }

        return null;
    }

    /* ----------------------------------------------------------- writes -- */

    /**
     * Assert a link.
     *
     * Idempotent by the ordered pair, so every derivation can be re-run. The
     * rule on a claim that is already recorded is asymmetric, and the asymmetry
     * is the point:
     *
     *   A **stronger** claim upgrades what is there. The same two identities
     *   turn up in several rows — an address is a bridge's recipient in one
     *   request and its signing sender in the next — and which of those was
     *   seen first is an accident of the id order. Refusing to upgrade would
     *   file a signed fact as a guess forever, purely because a weaker row
     *   about the same pair happened to be read earlier.
     *
     *   A **weaker or equal** claim changes nothing, so a re-derivation can
     *   never undo an operator who confirmed something by hand.
     *
     * @return array{0: ?CrmIdentityLink, 1: string} the link, and what happened
     *                                               to it: created|upgraded|kept
     */
    public function linkWithOutcome(
        string $aKind,
        string $aValue,
        string $bKind,
        string $bValue,
        string $source,
        ?string $evidence = null,
        string $confidence = 'strong',
        ?int $createdBy = null,
    ): array {
        [$lk, $lv, $rk, $rv] = CrmIdentityLink::order($aKind, $aValue, $bKind, $bValue);

        if ($lk === $rk && $lv === $rv) {
            return [null, 'kept'];
        }

        $existing = CrmIdentityLink::query()
            ->where('left_kind', $lk)
            ->where('left_value', $lv)
            ->where('right_kind', $rk)
            ->where('right_value', $rv)
            ->first();

        if ($existing !== null) {
            if ($confidence === 'strong' && $existing->confidence !== 'strong') {
                $existing->forceFill([
                    'confidence' => 'strong',
                    'source' => $source,
                    'evidence' => $evidence,
                ])->save();

                $this->forget();

                return [$existing, 'upgraded'];
            }

            return [$existing, 'kept'];
        }

        $this->forget();

        $created = CrmIdentityLink::create([
            'left_kind' => $lk,
            'left_value' => $lv,
            'right_kind' => $rk,
            'right_value' => $rv,
            'source' => $source,
            'confidence' => $confidence,
            'evidence' => $evidence,
            'created_by' => $createdBy,
            'created_at' => Carbon::now('UTC'),
        ]);

        return [$created, 'created'];
    }

    /** The same assertion, for callers that only want the row. */
    public function link(
        string $aKind,
        string $aValue,
        string $bKind,
        string $bValue,
        string $source,
        ?string $evidence = null,
        string $confidence = 'strong',
        ?int $createdBy = null,
    ): ?CrmIdentityLink {
        return $this->linkWithOutcome($aKind, $aValue, $bKind, $bValue, $source, $evidence, $confidence, $createdBy)[0];
    }

    public function forget(): void
    {
        $this->built = false;
        $this->parent = [];
        $this->edgesByNode = null;
    }
}
