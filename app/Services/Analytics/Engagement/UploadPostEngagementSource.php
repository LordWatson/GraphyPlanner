<?php

namespace App\Services\Analytics\Engagement;

use App\Contracts\EngagementSource;
use App\Contracts\PublishCredentials;
use App\Enums\Platform;
use App\Exceptions\Analytics\EngagementSourceException;
use App\Models\PostTarget;
use App\Models\SocialAccount;
use App\Support\Analytics\Engagement\AccountMetricsReading;
use App\Support\Analytics\Engagement\PostMetricsReading;
use App\Support\Publishing\VendorHttp;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

/**
 * Step M-3.1 — engagement from Upload-Post's analytics API (docs.upload-post.com/api/get-analytics,
 * read 21 Oct 2026), authenticated with the account's organization key like every adapter call.
 *
 * - Post: `GET /uploadposts/post-analytics/{request_id}?platform=` when we know the upload request
 *   id (the webhook carries it; async uploads return it as the external id), else the live
 *   `GET /uploadposts/post-analytics?platform_post_id=&platform=&user=` (100 requests / 5 min).
 *   The response nests per platform under `platforms.{vendor}`.
 * - Account: `GET /analytics/{profile}?platforms=` (+ `page_id` for Facebook, `page_urn` for a
 *   LinkedIn company page from the account's platform settings). Platforms the vendor can't
 *   report come back as a message rather than an error — treated as "nothing to report".
 *
 * Reads only, so `VendorHttp::forReads()` retries 429/5xx. The API key is never logged.
 */
class UploadPostEngagementSource implements EngagementSource
{
    public function __construct(private readonly ?string $baseUrl = null) {}

    public function key(): string
    {
        return 'upload_post';
    }

    public function postMetrics(PostTarget $target): ?PostMetricsReading
    {
        $account = $target->socialAccount;

        if (! $account instanceof SocialAccount || ! $account->platform->spec()->postMetrics) {
            return null;
        }

        $vendor = $account->platform->spec()->vendor;
        $requestId = $target->vendor_request_id ?? ($target->platform_post_id === null ? $target->external_post_id : null);

        if ($requestId !== null && $requestId !== '') {
            $response = $this->get($account, '/uploadposts/post-analytics/'.rawurlencode($requestId), ['platform' => $vendor], $target);
        } elseif ($target->platform_post_id !== null) {
            $response = $this->get($account, '/uploadposts/post-analytics', [
                'platform_post_id' => $target->platform_post_id,
                'platform' => $vendor,
                'user' => $this->username($account),
            ], $target);
        } else {
            return null;
        }

        if ($response->status() === 404) {
            return null;
        }

        $this->ensureSuccessful($response, 'post analytics', $target->id);

        $entry = $response->json("platforms.{$vendor}");

        if (! is_array($entry)) {
            return null;
        }

        $metrics = is_array($entry['post_metrics'] ?? null) ? $entry['post_metrics'] : [];
        $platformPostId = is_scalar($entry['platform_post_id'] ?? null) ? (string) $entry['platform_post_id'] : null;
        $postUrl = is_string($entry['post_url'] ?? null) && Str::startsWith($entry['post_url'], ['https://', 'http://']) ? $entry['post_url'] : null;

        if ($metrics === [] && (($entry['success'] ?? true) === false || filled($entry['post_metrics_error'] ?? null))) {
            throw new EngagementSourceException(Str::limit((string) ($entry['post_metrics_error'] ?? 'The network did not return metrics for this post.'), 240));
        }

        $reading = PostMetricsReading::fromVendor($metrics, $platformPostId, $postUrl);

        return $reading->isEmpty() && $platformPostId === null && $postUrl === null ? null : $reading;
    }

    public function accountMetrics(SocialAccount $account): ?AccountMetricsReading
    {
        if (! $account->platform->spec()->accountMetrics) {
            return null;
        }

        $vendor = $account->platform->spec()->vendor;
        $settings = $account->platform_settings ?? [];
        $query = ['platforms' => $vendor];

        if ($account->platform === Platform::Facebook && filled($settings['facebook_page_id'] ?? null)) {
            $query['page_id'] = (string) $settings['facebook_page_id'];
        }

        if ($account->platform === Platform::LinkedIn && filled($settings['target_linkedin_page_id'] ?? null)) {
            $page = (string) $settings['target_linkedin_page_id'];
            $query['page_urn'] = str_starts_with($page, 'urn:') ? $page : "urn:li:organization:{$page}";
        }

        $response = $this->get($account, '/analytics/'.rawurlencode($this->username($account)), $query, null);

        if ($response->status() === 404) {
            return null;
        }

        $this->ensureSuccessful($response, 'account analytics', null, $account->id);

        $data = $response->json($vendor);

        if (! is_array($data)) {
            return null;
        }

        if (isset($data['error']) && is_string($data['error'])) {
            throw new EngagementSourceException(Str::limit($data['error'], 240));
        }

        $reading = AccountMetricsReading::fromVendor($data);

        return $reading->isEmpty() ? null : $reading;
    }

    /**
     * @param  array<string, string>  $query
     */
    private function get(SocialAccount $account, string $path, array $query, ?PostTarget $target): Response
    {
        try {
            return $this->client($account)->get($path, $query);
        } catch (Throwable $e) {
            Log::warning('Upload-Post analytics request failed', [
                'social_account_id' => $account->id,
                'post_target_id' => $target?->id,
                'path' => $path,
                'error' => $e->getMessage(),
            ]);

            throw new EngagementSourceException('Could not reach Upload-Post for analytics. We will try again on the next sync.', previous: $e);
        }
    }

    private function ensureSuccessful(Response $response, string $what, ?int $targetId = null, ?int $accountId = null): void
    {
        if ($response->successful() && $response->json('success') !== false) {
            return;
        }

        $message = $response->json('message') ?? $response->json('error');

        Log::warning("Upload-Post {$what} rejected", [
            'post_target_id' => $targetId,
            'social_account_id' => $accountId,
            'status' => $response->status(),
            'message' => is_string($message) ? Str::limit($message, 240) : null,
        ]);

        throw new EngagementSourceException(match (true) {
            $response->status() === 401 || $response->status() === 403 => 'Upload-Post refused the analytics request. Check the organization API key.',
            $response->status() === 429 => 'Upload-Post is rate-limiting analytics. We will try again on the next sync.',
            is_string($message) && $message !== '' => Str::limit($message, 240),
            default => "Upload-Post returned an error ({$response->status()}) for {$what}.",
        });
    }

    private function username(SocialAccount $account): string
    {
        return (string) ($account->external_account_id ?? $account->external_profile_id ?? $account->id);
    }

    private function client(SocialAccount $account): PendingRequest
    {
        // Step B-5.1: byo (the org's own key) or managed (Alima's platform key), via the contract.
        $apiKey = app(PublishCredentials::class)->apiKeyFor($account);

        return VendorHttp::forReads(
            Http::baseUrl($this->baseUrl ?? (string) config('services.upload_post.base_url', 'https://api.upload-post.com/api'))
                ->withHeaders(['Authorization' => 'Apikey '.$apiKey])
                ->acceptJson(),
        );
    }
}
