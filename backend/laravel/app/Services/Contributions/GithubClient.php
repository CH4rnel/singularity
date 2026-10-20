<?php

namespace App\Services\Contributions;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * The few GitHub REST reads the reward engine needs. Everything it reads is
 * public, so the token (when set) is only there for the rate limit.
 *
 * A read that fails answers null, never an empty list: an empty page would
 * look like a repository nobody contributed to, and that is a claim about the
 * world this class has no grounds to make.
 */
class GithubClient
{
    private function request(): PendingRequest
    {
        $request = Http::baseUrl(rtrim((string) config('contributions.github.api'), '/'))
            ->timeout((int) config('contributions.github.timeout', 15))
            ->withHeaders([
                'Accept' => 'application/vnd.github+json',
                'X-GitHub-Api-Version' => '2022-11-28',
                'User-Agent' => 'cyberia-contributions',
            ]);

        $token = (string) config('contributions.github.token');

        return $token !== '' ? $request->withToken($token) : $request;
    }

    /**
     * @param  array<string, scalar>  $query
     * @return ?array<mixed>
     */
    private function get(string $path, array $query = []): ?array
    {
        try {
            $response = $this->request()->get($path, $query);
        } catch (Throwable) {
            return null;
        }

        return $response->successful() && is_array($response->json()) ? $response->json() : null;
    }

    /**
     * Closed pull requests, most recently updated first.
     *
     * @return ?list<array<string, mixed>>
     */
    public function closedPullRequests(string $repository, int $page): ?array
    {
        return $this->get("/repos/{$repository}/pulls", [
            'state' => 'closed',
            'sort' => 'updated',
            'direction' => 'desc',
            'per_page' => 100,
            'page' => $page,
        ]);
    }

    /**
     * One pull request in full: the list omits its size and who merged it.
     *
     * @return ?array<string, mixed>
     */
    public function pullRequest(string $repository, int $number): ?array
    {
        return $this->get("/repos/{$repository}/pulls/{$number}");
    }

    /**
     * Closed issues updated since a moment. GitHub returns pull requests here
     * too; the caller drops them.
     *
     * @return ?list<array<string, mixed>>
     */
    public function closedIssues(string $repository, string $sinceIso): ?array
    {
        return $this->get("/repos/{$repository}/issues", [
            'state' => 'closed',
            'since' => $sinceIso,
            'per_page' => 100,
        ]);
    }

    /**
     * An account by its numeric id, which survives a rename.
     *
     * @return ?array<string, mixed>
     */
    public function account(string $githubId): ?array
    {
        return $this->get("/user/{$githubId}");
    }

    /**
     * A file on the default branch, decoded; null when it is not there.
     */
    public function file(string $repository, string $path): ?string
    {
        $body = $this->get("/repos/{$repository}/contents/{$path}");

        if (! is_array($body) || ! is_string($body['content'] ?? null)) {
            return null;
        }

        $decoded = base64_decode(str_replace("\n", '', $body['content']), true);

        return $decoded === false ? null : $decoded;
    }
}
