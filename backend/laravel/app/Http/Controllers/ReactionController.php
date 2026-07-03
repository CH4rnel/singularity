<?php

namespace App\Http\Controllers;

use App\Http\Requests\ToggleReactionRequest;
use App\Models\Proposal;
use App\Models\ProposalComment;
use App\Models\Reaction;
use App\Services\Dao\DaoNotifier;
use Illuminate\Http\RedirectResponse;

class ReactionController extends Controller
{
    /** Whitelist: input types never map to arbitrary morph classes. */
    private const REACTABLES = [
        'proposal' => Proposal::class,
        'comment' => ProposalComment::class,
    ];

    public function __construct(private DaoNotifier $notifier) {}

    public function toggle(ToggleReactionRequest $request): RedirectResponse
    {
        $class = self::REACTABLES[$request->validated('reactable_type')];
        $reactable = $class::query()->findOrFail($request->validated('reactable_id'));

        $attributes = [
            'user_id' => $request->user()->id,
            'reactable_type' => $class,
            'reactable_id' => $reactable->getKey(),
            'emoji' => $request->validated('emoji'),
        ];

        $existing = Reaction::where($attributes)->first();

        if ($existing) {
            $existing->delete();

            return back();
        }

        $reaction = Reaction::create($attributes);

        $this->notifier->reactionAdded($reaction);

        return back();
    }
}
