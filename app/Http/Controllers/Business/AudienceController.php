<?php

namespace App\Http\Controllers\Business;

use App\Actions\AI\RewriteCaptionAction;
use App\Actions\Audience\GetAudienceAction;
use App\Actions\Audience\ImportAudienceContactsAction;
use App\Actions\Audience\SendAudienceBroadcastAction;
use App\Contracts\Entitlements;
use App\Enums\Feature;
use App\Enums\WritingTool;
use App\Exceptions\AI\AiBudgetExceededException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Business\SendBroadcastRequest;
use App\Models\AudienceContact;
use App\Models\Post;
use App\Services\Workspace\CurrentWorkspace;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Phase GTM-2 — the creator's email list at `/audience`: who is on it, bringing a list in, and
 * writing one-off emails to a segment.
 *
 * Thin: one read Action, one Action per write. Like `/bio`, `/store` and `/deals`, the page renders
 * without `Feature::EmailAutomation` and explains what upgrading would unlock — the list itself is
 * still shown, because the contacts are the creator's own data and were captured whatever the plan.
 */
class AudienceController extends Controller
{
    public function index(Request $request, CurrentWorkspace $workspace, GetAudienceAction $audience): Response
    {
        $brand = $workspace->brand();
        $this->authorize('viewAny', [Post::class, $brand]);

        $organization = $brand->organization;

        return Inertia::render('business/audience', [
            ...$audience($brand),
            'entitled' => $organization !== null && app(Entitlements::class)->allows($organization, Feature::EmailAutomation),
            'canManage' => $request->user()?->can('update', $brand) ?? false,
        ]);
    }

    public function import(Request $request, CurrentWorkspace $workspace, ImportAudienceContactsAction $import): RedirectResponse
    {
        $brand = $workspace->brand();
        $this->authorize('update', $brand);

        $validated = $request->validate([
            'csv' => ['required', 'string', 'max:200000'],
        ]);

        $result = $import($brand, $validated['csv']);

        Inertia::flash('toast', ['type' => 'success', 'message' => __(
            ':imported invited, :known already on your list, :skipped lines skipped.',
            ['imported' => $result['imported'], 'known' => $result['already_known'], 'skipped' => $result['skipped']],
        )]);

        return back();
    }

    public function destroyContact(AudienceContact $contact, CurrentWorkspace $workspace): RedirectResponse
    {
        $brand = $workspace->brand();
        $this->authorize('update', $brand);
        abort_unless($contact->client_id === $brand->id, 404);

        $contact->delete();

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Contact removed.')]);

        return back();
    }

    public function send(SendBroadcastRequest $request, CurrentWorkspace $workspace, SendAudienceBroadcastAction $action): RedirectResponse
    {
        $brand = $workspace->brand();
        $this->authorize('update', $brand);

        $broadcast = $action($brand, $request->user(), $request->validated());

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Sent to :count people.', [
            'count' => $broadcast->recipients,
        ])]);

        return back();
    }

    /**
     * Step GTM-2.3 — "Draft with AI": the owner's rough notes, rewritten in the brand voice.
     *
     * `shortcut: this reuses the caption rewrite seam (WritingTool::Expand) rather than an
     * email-specific provider method, so it inherits the brand grounding, the banned-phrase
     * guardrail and the AI credit metering for free; upgrade to its own AiProvider method when
     * subject lines or longer-form structure are wanted.`
     *
     * It returns text and nothing else. **Nothing here sends** — only {@see self::send()} does,
     * and only from a human request.
     */
    public function draft(Request $request, CurrentWorkspace $workspace, RewriteCaptionAction $rewrite): JsonResponse
    {
        $brand = $workspace->brand();
        $this->authorize('update', $brand);

        $validated = $request->validate([
            'notes' => ['required', 'string', 'max:2000'],
        ]);

        try {
            $text = $rewrite($brand, $validated['notes'], WritingTool::Expand);
        } catch (AiBudgetExceededException $e) {
            return response()->json(['message' => $e->getMessage(), 'budgetExceeded' => true], 422);
        }

        return response()->json(['text' => $text]);
    }
}
