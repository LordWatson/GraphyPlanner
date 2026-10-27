<?php

namespace App\Http\Controllers;

use App\Actions\Inbox\AssignConversationAction;
use App\Actions\Inbox\DraftInboxReplyAction;
use App\Actions\Inbox\ListInboxConversationsAction;
use App\Actions\Inbox\SendInboxReplyAction;
use App\Actions\Inbox\TransitionConversationAction;
use App\Enums\CommentSentiment;
use App\Enums\InboxStatus;
use App\Enums\Platform;
use App\Enums\Role;
use App\Exceptions\AI\AiBudgetExceededException;
use App\Exceptions\Inbox\CommentSourceException;
use App\Http\Requests\Inbox\DraftInboxReplyRequest;
use App\Http\Requests\Inbox\InboxIndexRequest;
use App\Http\Requests\Inbox\ReplyToInboxConversationRequest;
use App\Http\Requests\Inbox\UpdateInboxConversationRequest;
use App\Jobs\SyncOrganizationCommentsJob;
use App\Models\Client;
use App\Models\InboxConversation;
use App\Models\SavedReply;
use App\Models\User;
use App\Services\AI\AiAccess;
use App\Services\Publishing\PlatformCapabilities;
use App\Support\Inbox\InboxPresenter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Collection;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Phase M-9 — the social inbox (`/inbox`): every comment on a client's published posts, triaged,
 * assigned, resolved and replied to. Thin: it validates, calls Actions and renders. The list is
 * server-rendered; the open conversation and every action return JSON for the two-pane SPA. A
 * `ClientReviewer` never reaches here (policy + `portal.redirect`).
 */
class InboxController extends Controller
{
    public function __construct(private readonly AiAccess $access) {}

    public function index(InboxIndexRequest $request, ListInboxConversationsAction $list, PlatformCapabilities $platforms): Response
    {
        $user = $request->user();
        $filters = $request->filters();

        $staff = $this->assignableStaff($user);

        return Inertia::render('inbox/index', [
            'filters' => $filters,
            'conversations' => $list($user, $filters)->through(fn (InboxConversation $c) => InboxPresenter::row($c)),
            'replyNeededCount' => $list->query($user, ['status' => InboxStatus::Open->value, 'reply_needed' => true])->count(),
            'filterOptions' => $this->filterOptions($platforms, $staff),
            'savedReplies' => SavedReply::query()
                ->orderBy('title')
                ->get(['id', 'title', 'body', 'shortcut', 'client_id'])
                ->all(),
            'aiEnabled' => $this->access->canUse($user),
            // Step CG-1.1: hide the assignment UI for a one-seat workspace (a solo creator never
            // assigns a comment to anyone). Agency studios with a team keep it.
            'showAssignment' => $staff->count() > 1,
            'can' => ['act' => $user->can('viewAny', InboxConversation::class) && in_array($user->role, [Role::Owner, Role::Strategist, Role::Designer], true)],
        ]);
    }

    public function show(InboxConversation $conversation): JsonResponse
    {
        $this->authorize('view', $conversation);
        $user = request()->user();

        return response()->json([
            'conversation' => InboxPresenter::detail($conversation->load(['client', 'socialAccount', 'assignee', 'messages.sender', 'postTarget.post.assets']), $user),
        ]);
    }

    public function update(UpdateInboxConversationRequest $request, InboxConversation $conversation, AssignConversationAction $assign, TransitionConversationAction $transition): JsonResponse
    {
        $this->authorize('act', $conversation);
        $user = $request->user();

        match ($request->string('action')->toString()) {
            'assign' => $assign($conversation, $this->resolveAssignee($request->integer('assigned_to'))),
            'snooze' => $transition($conversation, InboxStatus::Snoozed, $user, $request->date('snoozed_until')),
            'resolve' => $transition($conversation, InboxStatus::Resolved, $user),
            'reopen' => $transition($conversation, InboxStatus::Open, $user),
            default => null,
        };

        return $this->detail($conversation, $user);
    }

    public function reply(ReplyToInboxConversationRequest $request, InboxConversation $conversation, SendInboxReplyAction $send): JsonResponse
    {
        $this->authorize('reply', $conversation);
        $user = $request->user();

        $savedReplyId = $request->integer('saved_reply_id') ?: null;
        $savedReply = $savedReplyId !== null ? SavedReply::query()->find($savedReplyId) : null;

        try {
            $send($conversation, $user, $request->string('body')->toString(), $savedReply);
        } catch (CommentSourceException $e) {
            return response()->json(['message' => $e->getMessage(), 'conversation' => InboxPresenter::detail($this->fresh($conversation), $user)], 422);
        }

        return $this->detail($conversation, $user);
    }

    public function draft(DraftInboxReplyRequest $request, InboxConversation $conversation, DraftInboxReplyAction $action): JsonResponse
    {
        $this->access->authorize($request->user());
        $this->authorize('reply', $conversation);

        try {
            $text = $action($conversation, $request->string('instruction')->trim()->toString() ?: null);
        } catch (AiBudgetExceededException $e) {
            return response()->json(['message' => $e->getMessage(), 'budgetExceeded' => true], 422);
        }

        return response()->json(['text' => $text]);
    }

    public function refresh(): RedirectResponse
    {
        $user = request()->user();
        abort_unless($user instanceof User && $user->can('viewAny', InboxConversation::class), 403);

        SyncOrganizationCommentsJob::dispatch($user->org_id, force: true);

        return back()->with('status', 'Checking for new comments — this takes a moment.');
    }

    private function detail(InboxConversation $conversation, User $user): JsonResponse
    {
        return response()->json(['conversation' => InboxPresenter::detail($this->fresh($conversation), $user)]);
    }

    private function fresh(InboxConversation $conversation): InboxConversation
    {
        return $conversation->fresh(['client', 'socialAccount', 'assignee', 'messages.sender', 'postTarget.post.assets']) ?? $conversation;
    }

    private function resolveAssignee(int $userId): ?User
    {
        return $userId > 0 ? User::query()->find($userId) : null;
    }

    /**
     * Staff who can be assigned a conversation, scoped to the viewer's own organization. (`User`
     * has no global org scope, so the `org_id` filter is what keeps one org's names out of
     * another's assignee list.)
     *
     * @return Collection<int, User>
     */
    private function assignableStaff(User $user): Collection
    {
        return User::query()
            ->where('org_id', $user->org_id)
            ->whereIn('role', [Role::Owner, Role::Strategist, Role::Designer])
            ->orderBy('name')
            ->get(['id', 'name']);
    }

    /**
     * @param  Collection<int, User>  $staff
     * @return array<string, mixed>
     */
    private function filterOptions(PlatformCapabilities $platforms, Collection $staff): array
    {
        $commentable = $platforms->commentablePlatformValues();

        return [
            'statuses' => array_map(fn (InboxStatus $s) => ['value' => $s->value, 'label' => $s->label()], InboxStatus::cases()),
            'sentiments' => array_map(fn (CommentSentiment $s) => ['value' => $s->value, 'label' => $s->label()], CommentSentiment::cases()),
            'platforms' => array_values(array_map(
                fn (Platform $p) => ['value' => $p->value, 'label' => $p->label()],
                array_filter(Platform::cases(), fn (Platform $p) => in_array($p->value, $commentable, true)),
            )),
            'clients' => Client::query()
                ->orderBy('name')
                ->get(['id', 'name'])
                ->map(fn (Client $client) => ['value' => $client->id, 'label' => $client->name])
                ->all(),
            'staff' => $staff
                ->map(fn (User $member) => ['value' => $member->id, 'label' => $member->name])
                ->all(),
        ];
    }
}
