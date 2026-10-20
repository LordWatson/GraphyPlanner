<?php

namespace App\Actions\Posts;

use App\Models\Post;
use Illuminate\Support\Collection;

/**
 * Flatten every post's targets into one calendar "occurrence" per target, with the local
 * date/time pre-computed both in the target's own social account timezone and in the org's
 * default timezone, so the frontend toggle never needs to do its own timezone math (spec §4.6).
 *
 * Shared by the internal `CalendarController` and the client portal's `PortalCalendarController`
 * so both calendars render identical occurrence shapes. Callers are responsible for scoping and
 * eager-loading `client`, `campaign` and `targets.socialAccount` on the posts they pass in.
 */
class BuildCalendarOccurrencesAction
{
    /**
     * @param  Collection<int, Post>  $posts
     * @return list<array<string, mixed>>
     */
    public function __invoke(Collection $posts, string $orgTimezone): array
    {
        $occurrences = [];

        foreach ($posts as $post) {
            foreach ($post->targets as $target) {
                $utc = $target->scheduled_at_utc;
                $account = $target->socialAccount;

                $occurrences[] = [
                    'post_id' => $post->id,
                    'target_id' => $target->id,
                    'client_id' => $post->client_id,
                    'client_name' => $post->client?->name,
                    'campaign_id' => $post->campaign_id,
                    'campaign_name' => $post->campaign?->name,
                    // Campaigns enhancement C-1.3: the chip (colour + code).
                    'campaign_code' => $post->campaign?->code,
                    'campaign_colour' => $post->campaign?->colour?->value,
                    'status' => $post->status->value,
                    'status_label' => $post->status->label(),
                    // Step 18.11: the grid chip, when the post was made from a grid tile.
                    'grid_plan_id' => $post->relationLoaded('gridTile') ? $post->gridTile?->grid_plan_id : null,
                    'grid_tile_position' => $post->relationLoaded('gridTile') ? $post->gridTile?->position : null,
                    'master_caption' => $post->master_caption,
                    'platform' => $account?->platform?->value,
                    'handle' => $account?->handle,
                    'account_local' => [
                        'date' => $target->scheduled_local_date?->toDateString(),
                        'time' => $target->scheduled_local_time,
                        'timezone' => $account?->timezone,
                    ],
                    'org_timezone' => [
                        'date' => $utc?->clone()->setTimezone($orgTimezone)->toDateString(),
                        'time' => $utc?->clone()->setTimezone($orgTimezone)->format('H:i'),
                        'timezone' => $orgTimezone,
                    ],
                    'scheduled_at_utc' => $utc?->toIso8601String(),
                ];
            }
        }

        return $occurrences;
    }
}
