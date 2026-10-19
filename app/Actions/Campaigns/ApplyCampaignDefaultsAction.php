<?php

namespace App\Actions\Campaigns;

use App\Models\Campaign;
use App\Support\Campaigns\UtmLink;
use Illuminate\Support\Str;

/**
 * Step C-4.2 — what joining a campaign offers a post: the campaign's hashtag set and its default
 * link with UTM parameters (`utm_campaign`, `utm_source` = platform, `utm_medium` = social).
 *
 * Pure: it computes the offer and the result of accepting it, and never writes. The editor shows
 * it as a dismissible prompt and applies it to unsaved state only on a click, so existing content
 * is never changed silently. Since I-3.1 / M-2.2 it fills each channel's own link (tagged with
 * that platform) and hashtag list too (`applyToChannels()`, `linksFor()`); the master caption's
 * link uses the post's first platform, or `social` when it has none.
 */
class ApplyCampaignDefaultsAction
{
    /**
     * @param  list<string>  $hashtags  the post's current hashtags
     * @return array{missing_hashtags: list<string>, link: string|null, link_missing: bool}
     */
    public function offer(Campaign $campaign, string $caption, array $hashtags, ?string $platform): array
    {
        $have = array_map(fn (string $tag) => Str::lower(ltrim($tag, '#')), $hashtags);
        $missing = array_values(array_filter(
            $campaign->hashtags ?? [],
            fn (string $tag) => ! in_array(Str::lower(ltrim($tag, '#')), $have, true),
        ));

        $link = $this->link($campaign, $platform);

        return [
            'missing_hashtags' => $missing,
            'link' => $link,
            'link_missing' => $link !== null && ! str_contains($caption, (string) strtok($link, '?')),
        ];
    }

    /**
     * The post's caption and hashtags with the offer accepted.
     *
     * @param  list<string>  $hashtags
     * @return array{caption: string, hashtags: list<string>}
     */
    public function apply(Campaign $campaign, string $caption, array $hashtags, ?string $platform): array
    {
        $offer = $this->offer($campaign, $caption, $hashtags, $platform);

        return [
            'caption' => $offer['link_missing'] && $offer['link'] !== null
                ? rtrim($caption).($caption === '' ? '' : "\n\n").$offer['link']
                : $caption,
            'hashtags' => [...$hashtags, ...$offer['missing_hashtags']],
        ];
    }

    /**
     * Step M-2.2 (dependency table, campaigns-enhancement.md §1.6): the offer applied to each
     * channel's own fields as well. Every channel gets the campaign link tagged with *its*
     * platform as `utm_source` (unless it already has a link of its own), and a channel with its
     * own hashtag list gets the missing campaign tags added to it (channels without one inherit
     * the master hashtags, which `apply()` fills).
     *
     * @param  list<array{platform: string, link_url?: string|null, hashtags_override?: list<string>|null}>  $channels
     * @return list<array{platform: string, link_url: string|null, hashtags_override: list<string>|null}>
     */
    public function applyToChannels(Campaign $campaign, array $channels): array
    {
        return array_map(function (array $channel) use ($campaign) {
            $own = $channel['hashtags_override'] ?? null;

            return [
                'platform' => $channel['platform'],
                'link_url' => filled($channel['link_url'] ?? null) ? $channel['link_url'] : $this->link($campaign, $channel['platform']),
                'hashtags_override' => $own === null ? null : $this->apply($campaign, '', $own, null)['hashtags'],
            ];
        }, $channels);
    }

    /**
     * The campaign link per platform (`utm_source` = that platform), for the editor to drop into
     * each channel's link field.
     *
     * @param  list<string>  $platforms
     * @return array<string, string>
     */
    public function linksFor(Campaign $campaign, array $platforms): array
    {
        $links = [];

        foreach (array_unique($platforms) as $platform) {
            if (($link = $this->link($campaign, $platform)) !== null) {
                $links[$platform] = $link;
            }
        }

        return $links;
    }

    public function link(Campaign $campaign, ?string $platform): ?string
    {
        if (blank($campaign->default_link_url)) {
            return null;
        }

        $utm = $campaign->utm_campaign ?: Str::slug($campaign->code ?: $campaign->name);

        return UtmLink::build((string) $campaign->default_link_url, $utm, $platform ?: 'social');
    }
}
