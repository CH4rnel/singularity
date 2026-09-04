<?php

namespace App\Services\Tracker;

use App\Models\TrackerRelease;

/**
 * Reading the index.
 *
 * One place, because the site page, the wallet screen and the JSON API are
 * three readers of the same list and three query builders is three subtly
 * different answers to "what is on this tracker".
 *
 * Sorting defaults to newest rather than to seeders: a release with nobody on
 * it yet is exactly the one that needs to be seen, and a tracker sorted by
 * swarm size buries every new upload under the same twenty torrents forever.
 */
final class ReleaseIndex
{
    public const SORTS = ['new', 'seeders', 'size', 'name'];

    /**
     * @param  array<string, mixed>  $filters
     * @return array<string, mixed>
     */
    public function search(array $filters): array
    {
        $perPage = (int) config('tracker.per_page', 30);
        $page = max(1, (int) ($filters['page'] ?? 1));
        $query = TrackerRelease::query()->listed();

        $term = trim((string) ($filters['q'] ?? ''));

        if ($term !== '') {
            // An info hash pasted into the search box is a lookup, not a
            // search: it is the one term that identifies exactly one row, and
            // matching it against names would return nothing.
            if (preg_match('/^[0-9a-fA-F]{40}$/', $term)) {
                $query->where('info_hash', strtolower($term));
            } else {
                $like = '%'.str_replace(['%', '_'], ['\%', '\_'], $term).'%';
                $query->where(fn ($inner) => $inner
                    ->where('name', 'like', $like)
                    ->orWhere('description', 'like', $like));
            }
        }

        $category = (string) ($filters['category'] ?? '');

        if (in_array($category, (array) config('tracker.categories', []), true)) {
            $query->where('category', $category);
        }

        $owner = (string) ($filters['owner'] ?? '');

        if (preg_match('/^0x[0-9a-fA-F]{40}$/', $owner) === 1) {
            $query->where('owner', strtolower($owner));
        }

        $sort = (string) ($filters['sort'] ?? 'new');

        match (in_array($sort, self::SORTS, true) ? $sort : 'new') {
            'seeders' => $query->orderByDesc('seeders')->orderByDesc('id'),
            'size' => $query->orderByDesc('size_bytes')->orderByDesc('id'),
            'name' => $query->orderBy('name')->orderByDesc('id'),
            default => $query->orderByDesc('id'),
        };

        $total = (clone $query)->count();

        $releases = $query
            ->forPage($page, $perPage)
            ->get()
            ->map(fn (TrackerRelease $release) => $release->toPublicArray())
            ->all();

        return [
            'releases' => $releases,
            'total' => $total,
            'page' => $page,
            'pages' => (int) max(1, ceil($total / $perPage)),
            'filters' => [
                'q' => $term,
                'category' => $category,
                'sort' => in_array($sort, self::SORTS, true) ? $sort : 'new',
                'owner' => $owner,
            ],
        ];
    }

    /** Everything the screens need to draw the tracker but cannot invent. */
    public function context(): array
    {
        $chain = (array) config('tracker.chains.49406', []);

        return [
            'announce_url' => (string) config('tracker.announce_url'),
            'categories' => array_values((array) config('tracker.categories', [])),
            'sorts' => self::SORTS,
            'chain_id' => 49406,
            'collection' => $chain['collection'] ?? null,
            'explorer_url' => $chain['explorer_url'] ?? null,
        ];
    }
}
