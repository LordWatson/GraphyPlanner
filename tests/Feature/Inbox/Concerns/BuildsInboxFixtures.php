<?php

namespace Tests\Feature\Inbox\Concerns;

use App\Enums\ConnectionStatus;
use App\Enums\MessageDirection;
use App\Enums\Platform;
use App\Enums\Role;
use App\Models\Client;
use App\Models\InboxConversation;
use App\Models\Organization;
use App\Models\SocialAccount;
use App\Models\User;

/**
 * Phase M-9 — hand-built inbox fixtures: an org with a connected comment-supporting account and a
 * conversation with an inbound comment, plus staff users per role.
 */
trait BuildsInboxFixtures
{
    protected function inboxOrg(): Organization
    {
        return Organization::factory()->create(['upload_post_key' => 'up-key', 'inbox_sla_hours' => 24]);
    }

    protected function staff(Organization $org, Role $role = Role::Strategist): User
    {
        return User::factory()->create(['org_id' => $org->id, 'role' => $role]);
    }

    protected function inboxClient(Organization $org): Client
    {
        return Client::factory()->for($org, 'organization')->create();
    }

    protected function inboxAccount(Client $client, Platform $platform = Platform::Instagram): SocialAccount
    {
        return SocialAccount::factory()->for($client)->create([
            'org_id' => $client->org_id,
            'platform' => $platform,
            'connection_status' => ConnectionStatus::Connected,
            'external_account_id' => 'profile-'.$client->id,
        ]);
    }

    protected function conversation(Organization $org, ?Client $client = null, array $attributes = []): InboxConversation
    {
        $client ??= $this->inboxClient($org);
        $account = $this->inboxAccount($client);

        $conversation = InboxConversation::factory()->forAccount($account)->create($attributes);

        $conversation->messages()->create([
            'org_id' => $org->id,
            'external_message_id' => 'c-'.$conversation->id,
            'direction' => MessageDirection::Inbound,
            'author_name' => 'Ada',
            'body' => 'Do you ship to Dubai?',
            'posted_at' => now()->subHour(),
        ]);

        return $conversation->fresh() ?? $conversation;
    }
}
