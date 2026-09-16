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
     * posts waiting on the client, then posts missing media. Reuses `GetNeedsAttentionItemsAction`
     * so the dashboard and the `/home` page never disagree on what needs attention.
     *
     * @return array<int, array<string, mixed>>
     */
    public function __invoke(User $user, int $limit = 8): array
    {
        $items = ($this->getNeedsAttentionItems)($user);

        $rows = [];

        foreach ($items['failedPublishes'] as $post) {
            $rows[] = $this->postRow($post, 'failed');
        }

        foreach ($items['disconnectedAccounts'] as $account) {
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

        foreach ($items['waitingApprovals'] as $post) {
            $rows[] = $this->postRow($post, 'waiting');
        }

        foreach ($items['missingMedia'] as $post) {
            $rows[] = $this->postRow($post, 'missing_media');
        }

        return array_slice($rows, 0, $limit);
    }

    /**
     * @param  array<string, mixed>  $post
     * @return array<string, mixed>
     */
    private function postRow(array $post, string $type): array
    {
        return [
            'key' => 'post-'.$post['post_id'],
            'type' => $type,
            'title' => $post['master_caption'] ? Str::limit($post['master_caption'], 60) : 'Untitled post',
            'subtitle' => $post['reason'],
            'client_id' => $post['client_id'],
            'client_name' => $post['client_name'],
            'post_id' => $post['post_id'],
        ];
    }
}
