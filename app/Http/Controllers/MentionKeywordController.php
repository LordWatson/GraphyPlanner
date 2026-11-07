<?php

namespace App\Http\Controllers;

use App\Http\Requests\Inbox\StoreMentionKeywordRequest;
use App\Models\MentionKeyword;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Inbox → Mention alerts: the words the studio wants to hear about. Thin — the matching and the
 * notifying live in `App\Services\Inbox\MentionAlerts`, called from comment ingestion.
 */
class MentionKeywordController extends Controller
{
    public function index(Request $request): Response
    {
        $this->authorize('viewAny', MentionKeyword::class);

        return Inertia::render('inbox/alerts', [
            'keywords' => MentionKeyword::query()
                ->with('author:id,name')
                ->orderBy('term')
                ->get()
                ->map(fn (MentionKeyword $keyword) => [
                    'id' => $keyword->id,
                    'term' => $keyword->term,
                    'added_by' => $keyword->author?->name,
                    'matches' => $keyword->match_count,
                ])
                ->all(),
            'can' => ['manage' => $request->user()->can('create', MentionKeyword::class)],
        ]);
    }

    public function store(StoreMentionKeywordRequest $request): RedirectResponse
    {
        MentionKeyword::create([
            'org_id' => $request->user()->org_id,
            'created_by' => $request->user()->id,
            'term' => trim((string) $request->validated('term')),
        ]);

        return back()->with('status', 'Watching that word.');
    }

    public function destroy(MentionKeyword $mentionKeyword): RedirectResponse
    {
        $this->authorize('delete', $mentionKeyword);

        $mentionKeyword->delete();

        return back()->with('status', 'Stopped watching that word.');
    }
}
