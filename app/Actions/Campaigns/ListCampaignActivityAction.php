<?php

namespace App\Actions\Campaigns;

use App\Enums\CampaignStatus;
use App\Enums\PostStatus;
use App\Models\Campaign;
use App\Models\Post;
use App\Models\User;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Step C-1.2 — the Activity tab: the campaign's own log merged with the status changes of its
 * posts, newest first, paged in SQL with one UNION. Names and captions are resolved with one
 * query each per page.
 */
class ListCampaignActivityAction
{
    public const PER_PAGE = 30;

    /**
     * @return LengthAwarePaginator<int, array<string, mixed>>
     */
    public function __invoke(Campaign $campaign, int $perPage = self::PER_PAGE): LengthAwarePaginator
    {
        $campaignEvents = DB::table('campaign_activity_logs')
            ->where('campaign_id', $campaign->id)
            ->selectRaw("'campaign' as source, id, user_id, event, `from`, `to`, null as post_id, null as note, created_at");

        $postEvents = DB::table('post_activity_logs')
            ->join('posts', 'posts.id', '=', 'post_activity_logs.post_id')
            ->where('posts.campaign_id', $campaign->id)
            ->selectRaw("'post' as source, post_activity_logs.id, post_activity_logs.user_id, 'post.status_changed' as event, post_activity_logs.from_status as `from`, post_activity_logs.to_status as `to`, post_activity_logs.post_id, post_activity_logs.note, post_activity_logs.created_at");

        $page = DB::query()
            ->fromSub($campaignEvents->unionAll($postEvents), 'activity')
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->paginate($perPage);

        $rows = array_map(fn (object $row): array => (array) $row, $page->items());
        $userIds = array_values(array_unique(array_filter(array_column($rows, 'user_id'))));
        $postIds = array_values(array_unique(array_filter(array_column($rows, 'post_id'))));
        $users = User::query()->whereIn('id', $userIds)->pluck('name', 'id');
        $posts = Post::query()->withTrashed()->whereIn('id', $postIds)->pluck('master_caption', 'id');

        $items = array_map(fn (array $row): array => $this->row($row, $users->all(), $posts->all()), $rows);

        return new LengthAwarePaginator($items, $page->total(), $page->perPage(), $page->currentPage(), [
            'path' => LengthAwarePaginator::resolveCurrentPath(),
        ]);
    }

    /**
     * @param  array<string, mixed>  $row
     * @param  array<int, string>  $users
     * @param  array<int, string|null>  $posts
     * @return array<string, mixed>
     */
    private function row(array $row, array $users, array $posts): array
    {
        $event = (string) $row['event'];
        $from = $row['from'] === null ? null : (string) $row['from'];
        $to = $row['to'] === null ? null : (string) $row['to'];
        $postId = $row['post_id'] === null ? null : (int) $row['post_id'];
        $userId = $row['user_id'] === null ? null : (int) $row['user_id'];

        return [
            'key' => $row['source'].'-'.$row['id'],
            'source' => (string) $row['source'],
            'event' => $event,
            'summary' => $this->summary($event, $from, $to),
            'from' => $from,
            'to' => $to,
            'note' => $row['note'] === null ? null : (string) $row['note'],
            'user' => $userId === null ? null : ($users[$userId] ?? null),
            'post' => $postId === null ? null : [
                'id' => $postId,
                'caption' => Str::limit((string) ($posts[$postId] ?? ''), 80) ?: null,
            ],
            'created_at' => (string) $row['created_at'],
        ];
    }

    private function summary(string $event, ?string $from, ?string $to): string
    {
        $post = fn (?string $status) => mb_strtolower(PostStatus::tryFrom((string) $status)?->label() ?? (string) $status);
        $campaign = fn (?string $status) => mb_strtolower(CampaignStatus::tryFrom((string) $status)?->label() ?? (string) $status);

        return match ($event) {
            'post.status_changed' => 'Post moved to '.$post($to).'.',
            'campaign.created' => 'Campaign created.',
            'campaign.updated' => 'Campaign details edited.',
            'campaign.deleted' => 'Campaign deleted.',
            'status.changed' => 'Status changed from '.$campaign($from).' to '.$campaign($to).'.',
            'brief.updated' => 'Brief edited.',
            'brief.reopened' => 'Signed brief reopened for edits.',
            'brief.sent' => 'Brief sent to the client.',
            'brief.approved' => 'Client approved the brief.',
            'brief.changes_requested' => 'Client asked for changes to the brief.',
            'phases.updated' => 'Phases updated.',
            'deliverables.added' => 'Deliverables added to the plan.',
            'deliverable.started' => 'Post started from a deliverable.',
            'campaign.paused' => 'Campaign paused.',
            'campaign.resumed' => 'Campaign resumed.',
            'campaign.shifted' => 'Campaign dates shifted.',
            'campaign.went_live' => 'First post went live.',
            'kpis.updated' => 'KPI targets updated.',
            'report.updated' => 'Wrap report edited.',
            'report.shared' => 'Wrap report shared with the client.',
            'posts.bulk' => 'Bulk action on campaign posts.',
            'template.saved' => 'Saved as a template.',
            'invoice.added' => 'Fee added to an invoice.',
            'assets.updated' => 'Asset kit updated.',
            'comment.added' => 'New message in the conversation.',
            default => Str::of($event)->replace(['.', '_'], ' ')->ucfirst()->append('.')->toString(),
        };
    }
}
