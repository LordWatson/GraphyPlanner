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
 * is never changed silently. Until I-3.1's per-target captions exist this fills the master caption
 * only (seam recorded in `posts.md`); `utm_source` is the post's first platform, or `social` when
 * it has none.
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

    public function link(Campaign $campaign, ?string $platform): ?string
    {
        if (blank($campaign->default_link_url)) {
            return null;
        }

        $utm = $campaign->utm_campaign ?: Str::slug($campaign->code ?: $campaign->name);

        return UtmLink::build((string) $campaign->default_link_url, $utm, $platform ?: 'social');
    }
}
