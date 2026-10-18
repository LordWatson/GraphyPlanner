<?php

namespace App\Actions\Posts;

use App\Models\Post;

class EvaluatePostChecklistAction
{
    /**
     * Evaluate the spec §6 pre-schedule checklist for a post and return its item-by-item result,
     * ready to be persisted onto `Post::checklist_snapshot` and rendered by the editor UI (§9).
     *
     * The exact §6 rule list wasn't available in this repo, so this is an interim definition
     * covering the concrete rule the plan does name explicitly (Phase 0 exit criterion: "Schedule
     * blocked without media unless platform is text-only") plus the other content basics an
     * editor obviously needs before a post can go out. See `.junie/modules/posts.md` for the open
     * question and the exact item list.
     *
     * @return array{items: array<int, array{key: string, label: string, passed: bool, soft?: bool}>, passed: bool}
     */
    public function __invoke(Post $post): array
    {
        $post->loadMissing('targets.socialAccount', 'assets', 'campaign');

        $hasCaption = filled($post->master_caption);
        $targets = $post->targets;
        $hasTargets = $targets->isNotEmpty();
        $allTargetsScheduled = $hasTargets && $targets->every(
            fn ($target) => filled($target->scheduled_local_date) && filled($target->scheduled_local_time)
        );

        $platforms = $post->targetPlatforms();
        $allPlatformsTextOnly = $platforms !== [] && collect($platforms)->every(fn ($platform) => $platform->isTextOnlyCapable());
        $hasMedia = $post->assets->isNotEmpty();
        $mediaSatisfied = $hasMedia || $allPlatformsTextOnly;

        $items = [
            [
                'key' => 'caption',
                'label' => 'Master caption is filled in',
                'passed' => $hasCaption,
            ],
            [
                'key' => 'targets',
                'label' => 'At least one target account is selected',
                'passed' => $hasTargets,
            ],
            [
                'key' => 'schedule',
                'label' => 'Every target has a local date and time',
                'passed' => $allTargetsScheduled,
            ],
            [
                'key' => 'media',
                'label' => 'Media is attached (unless every target platform is text-only)',
                'passed' => $mediaSatisfied,
            ],
        ];

        // Campaigns enhancement C-4.2: a soft check — a warning in the editor, never a blocker.
        $campaignTags = $post->campaign->hashtags ?? [];

        if ($campaignTags !== []) {
            $postTags = array_map(fn ($tag) => mb_strtolower(ltrim((string) $tag, '#')), $post->hashtags ?? []);

            $items[] = [
                'key' => 'campaign_hashtags',
                'label' => 'Campaign hashtags are included',
                'passed' => collect($campaignTags)->every(fn ($tag) => in_array(mb_strtolower(ltrim((string) $tag, '#')), $postTags, true)),
                'soft' => true,
            ];
        }

        return [
            'items' => $items,
            'passed' => collect($items)->every(fn (array $item) => $item['passed'] || ($item['soft'] ?? false)),
        ];
    }
}
