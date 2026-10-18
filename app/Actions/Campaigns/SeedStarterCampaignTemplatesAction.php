<?php

namespace App\Actions\Campaigns;

use App\Enums\CampaignObjective;
use App\Models\CampaignTemplate;
use App\Models\Organization;

/**
 * Step C-3.4 — every new organization starts with three templates it can use, edit or delete:
 * Product launch (4 weeks), Seasonal sale (2 weeks), Always-on month (4 weeks). Idempotent: an org
 * that already has starter templates is left alone.
 */
class SeedStarterCampaignTemplatesAction
{
    public function __invoke(Organization $organization): void
    {
        if (CampaignTemplate::query()->withoutOrgScope()->where('org_id', $organization->id)->where('is_starter', true)->exists()) {
            return;
        }

        foreach (self::starters() as $template) {
            CampaignTemplate::create([...$template, 'org_id' => $organization->id, 'is_starter' => true]);
        }
    }

    /**
     * @return list<array<string, mixed>>
     */
    public static function starters(): array
    {
        $weekly = fn (array $days, string $platform, string $title, ?string $format, int $phase) => array_map(fn (int $day) => [
            'title' => $title,
            'platform' => $platform,
            'format' => $format,
            'pillar' => null,
            'phase_index' => $phase,
            'day_offset' => $day,
            'time' => '09:00',
        ], $days);

        return [
            [
                'name' => 'Product launch',
                'description' => 'Four weeks: tease it, launch it, keep it going, wrap it up.',
                'objective' => CampaignObjective::Launch,
                'duration_days' => 28,
                'brief' => [
                    'background' => 'Something new is coming from {client_name}.',
                    'objective_detail' => 'Build anticipation, land the launch, and keep momentum for two weeks after.',
                    'key_messages' => ['What it is', 'Why it matters now', 'Where to get it'],
                    'call_to_action' => 'Shop now',
                    'success_measures' => 'Every launch-week post out on time, first-time approval above 80%.',
                ],
                'phases' => [
                    ['name' => 'Teaser', 'start_offset' => 0, 'length' => 6, 'colour' => 'lilac'],
                    ['name' => 'Launch', 'start_offset' => 6, 'length' => 6, 'colour' => 'teal'],
                    ['name' => 'Sustain', 'start_offset' => 12, 'length' => 12, 'colour' => 'electric'],
                    ['name' => 'Wrap', 'start_offset' => 24, 'length' => 4, 'colour' => 'lime'],
                ],
                'deliverables' => [
                    ...$weekly([1, 4], 'instagram', 'Teaser', 'story', 0),
                    ...$weekly([6], 'instagram', 'Launch day reel', 'reel', 1),
                    ...$weekly([6], 'linkedin', 'Launch announcement', 'post', 1),
                    ...$weekly([8, 10], 'instagram', 'Launch carousel', 'carousel', 1),
                    ...$weekly([14, 18, 21], 'instagram', 'In the wild', 'reel', 2),
                    ...$weekly([26], 'instagram', 'Thank you', 'post', 3),
                ],
            ],
            [
                'name' => 'Seasonal sale',
                'description' => 'Two weeks around a sale: warm-up, the sale, the last call.',
                'objective' => CampaignObjective::Sales,
                'duration_days' => 14,
                'brief' => [
                    'background' => '{client_name} is running a seasonal sale.',
                    'offer' => 'Describe the discount and what it covers.',
                    'call_to_action' => 'Shop the sale',
                    'mandatories' => ['Sale dates', 'Terms apply line'],
                ],
                'phases' => [
                    ['name' => 'Warm-up', 'start_offset' => 0, 'length' => 4, 'colour' => 'lilac'],
                    ['name' => 'Sale', 'start_offset' => 4, 'length' => 8, 'colour' => 'teal'],
                    ['name' => 'Last call', 'start_offset' => 12, 'length' => 2, 'colour' => 'lime'],
                ],
                'deliverables' => [
                    ...$weekly([1], 'instagram', 'Coming soon', 'story', 0),
                    ...$weekly([4], 'instagram', 'Sale is on', 'carousel', 1),
                    ...$weekly([4], 'facebook', 'Sale is on', 'post', 1),
                    ...$weekly([7, 10], 'instagram', 'Pick of the sale', 'reel', 1),
                    ...$weekly([12], 'instagram', 'Last chance', 'story', 2),
                ],
            ],
            [
                'name' => 'Always-on month',
                'description' => 'A steady month of content across the brand’s pillars.',
                'objective' => CampaignObjective::Engagement,
                'duration_days' => 28,
                'brief' => [
                    'background' => 'A month of always-on content for {client_name}.',
                    'success_measures' => 'Three posts a week, every week, on time.',
                ],
                'phases' => [
                    ['name' => 'Month', 'start_offset' => 0, 'length' => 28, 'colour' => 'teal'],
                ],
                'deliverables' => [
                    ...$weekly([0, 2, 4, 7, 9, 11, 14, 16, 18, 21, 23, 25], 'instagram', 'Always-on post', null, 0),
                ],
            ],
        ];
    }
}
