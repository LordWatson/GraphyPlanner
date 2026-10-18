<?php

namespace App\Support\Campaigns;

/**
 * Step C-4.2 — append campaign UTM parameters to a link. Existing query parameters are kept (an
 * existing `utm_*` value is replaced, never duplicated), values are URL-encoded, and a fragment
 * stays at the end.
 */
final class UtmLink
{
    public static function build(string $url, string $campaign, string $source, string $medium = 'social'): string
    {
        $fragment = '';

        if (($hash = strpos($url, '#')) !== false) {
            $fragment = substr($url, $hash);
            $url = substr($url, 0, $hash);
        }

        [$base, $query] = array_pad(explode('?', $url, 2), 2, '');
        parse_str($query, $params);

        $params = [
            ...array_diff_key($params, array_flip(['utm_source', 'utm_medium', 'utm_campaign'])),
            'utm_source' => $source,
            'utm_medium' => $medium,
            'utm_campaign' => $campaign,
        ];

        return $base.'?'.http_build_query($params, '', '&', PHP_QUERY_RFC3986).$fragment;
    }
}
