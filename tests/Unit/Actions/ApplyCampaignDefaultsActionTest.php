<?php

namespace Tests\Unit\Actions;

use App\Actions\Campaigns\ApplyCampaignDefaultsAction;
use App\Models\Campaign;
use Tests\TestCase;

/**
 * Step C-4.2, extended by M-2.2: campaign defaults fill the per-channel fields as well as the
 * master caption (campaigns-enhancement.md §1.6 dependency table).
 */
class ApplyCampaignDefaultsActionTest extends TestCase
{
    private function campaign(): Campaign
    {
        return new Campaign([
            'name' => 'Spring Launch',
            'hashtags' => ['spring', 'launch'],
            'default_link_url' => 'https://alima.test/spring',
            'utm_campaign' => 'spring-launch',
        ]);
    }

    public function test_each_channel_gets_the_link_tagged_with_its_own_platform(): void
    {
        $channels = (new ApplyCampaignDefaultsAction)->applyToChannels($this->campaign(), [
            ['platform' => 'instagram'],
            ['platform' => 'linkedin', 'link_url' => 'https://alima.test/own'],
        ]);

        $this->assertStringContainsString('utm_source=instagram', (string) $channels[0]['link_url']);
        $this->assertStringContainsString('utm_campaign=spring-launch', (string) $channels[0]['link_url']);
        $this->assertSame('https://alima.test/own', $channels[1]['link_url'], 'A channel’s own link is kept.');
    }

    public function test_only_channels_with_their_own_hashtags_get_the_missing_campaign_tags(): void
    {
        $channels = (new ApplyCampaignDefaultsAction)->applyToChannels($this->campaign(), [
            ['platform' => 'instagram', 'hashtags_override' => ['brunch', 'Spring']],
            ['platform' => 'linkedin', 'hashtags_override' => null],
        ]);

        $this->assertSame(['brunch', 'Spring', 'launch'], $channels[0]['hashtags_override']);
        $this->assertNull($channels[1]['hashtags_override'], 'Inheriting channels pick the tags up from the master.');
    }

    public function test_links_for_lists_one_link_per_platform(): void
    {
        $links = (new ApplyCampaignDefaultsAction)->linksFor($this->campaign(), ['tiktok', 'tiktok', 'x']);

        $this->assertSame(['tiktok', 'x'], array_keys($links));
        $this->assertStringContainsString('utm_source=x', $links['x']);
    }
}
