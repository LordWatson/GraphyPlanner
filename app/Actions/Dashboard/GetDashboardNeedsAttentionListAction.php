<?php

namespace App\Actions\Dashboard;

use App\Actions\Home\GetNeedsAttentionItemsAction;
use App\Models\User;
use Illuminate\Support\Str;

class GetDashboardNeedsAttentionListAction
{
    public function __construct(
        private readonly GetNeedsAttentionItemsAction $getNeedsAttentionItems,
    ) {}

    /**
     * Flatten the Step 0.12 "Home / needs-attention" categories into a single, ranked list for the
     * dashboard's "Needs attention" card: failed publishes first, then disconnected accounts, then
     * posts waiting on internal review, then posts waiting on the client, then posts missing media. Reuses `GetNeedsAttentionItemsAction`
     * so the dashboard and the `/home` page never disagree on what needs attention.
     *
     * Step I-2.2: each bucket only fetches `$limit` rows, and `total` is the sum of the buckets'
     * SQL counts — the card no longer builds a 100-row list just to count it.
     *
     * @return array{rows: list<array<string, mixed>>, total: int}
     */
    public function __invoke(User $user, int $limit = 4): array
    {
        $items = ($this->getNeedsAttentionItems)($user, $limit);

        $rows = [];

        foreach ($items['failedPublishes']['items'] as $post) {
            $rows[] = $this->postRow($post, 'failed');
        }

        foreach ($items['disconnectedAccounts']['items'] as $account) {
            $rows[] = [
                'key' => 'account-'.$account['account_id'],
                'type' => 'disconnected',
                'title' => '@'.$account['handle'].' '.Str::lower($account['connection_status_label']),
                'subtitle' => $account['platform_label'].' · reconnect required',
                'client_id' => $account['client_id'],
                'client_name' => $account['client_name'],
                'post_id' => null,
            ];
        }

        foreach ($items['internalReviews']['items'] as $post) {
            $rows[] = $this->postRow($post, 'internal_review');
        }

        foreach ($items['waitingApprovals']['items'] as $post) {
            $rows[] = $this->postRow($post, 'waiting');
        }

        foreach ($items['missingMedia']['items'] as $post) {
            $rows[] = $this->postRow($post, 'missing_media');
        }

        return [
            'rows' => array_slice($rows, 0, $limit),
            'total' => array_sum(array_column($items, 'total')),
        ];
    }

    /**
     * @param  array<string, mixed>  $post
     * @return array<string, mixed>
     */
    private function postRow(array $post, string $type): array
    {
        return [
            'key' => 'post-'.$type.'-'.$post['post_id'],
            'type' => $type,
            'title' => $post['master_caption'] ? Str::limit($post['master_caption'], 60) : 'Untitled post',
            'subtitle' => $post['reason'],
            'client_id' => $post['client_id'],
            'client_name' => $post['client_name'],
            'post_id' => $post['post_id'],
        ];
    }
}
