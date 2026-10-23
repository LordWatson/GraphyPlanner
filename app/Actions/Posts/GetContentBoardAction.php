<?php

namespace App\Actions\Posts;

use App\Models\Post;
use App\Models\User;
use App\Support\Posts\ContentHubPresenter;

/**
 * Step M-5.1 — the content hub's status board: every matching post across the roster, grouped by
 * status. Mirrors the campaign board (`GetCampaignBoardAction`) but org-wide. A board can't
 * paginate sensibly, so the match is capped; the header tells the user when the cap bit and to
 * narrow their filters.
 */
class GetContentBoardAction
{
    public const CAP = 400;

    public function __construct(private readonly ListPostsAction $list) {}

    /**
     * @param  array<string, mixed>  $filters
     * @return array{columns: list<array<string, mixed>>, capped: bool}
     */
    public function __invoke(array $filters, User $user): array
    {
        $posts = $this->list->query($filters)
            ->with(['client:id,name', 'campaign', 'targets.socialAccount', 'assets:id'])
            ->orderByDesc('updated_at')
            ->orderByDesc('id')
            ->limit(self::CAP + 1)
            ->get();

        $capped = $posts->count() > self::CAP;
        $posts = $posts->take(self::CAP);

        $columns = [];

        foreach (ContentHubPresenter::COLUMNS as $key => $statuses) {
            $columns[] = [
                'key' => $key,
                'label' => $statuses[0]->label(),
                'drop_status' => in_array($statuses[0], ContentHubPresenter::DROPPABLE, true) ? $statuses[0]->value : null,
                'posts' => $posts
                    ->filter(fn (Post $post) => in_array($post->status, $statuses, true))
                    ->map(fn (Post $post) => ContentHubPresenter::boardCard($post, $user))
                    ->values()
                    ->all(),
            ];
        }

        return ['columns' => $columns, 'capped' => $capped];
    }
}
