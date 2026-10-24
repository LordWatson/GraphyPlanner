<?php

namespace App\Services\Inbox;

use App\Contracts\CommentSource;
use App\Contracts\PublishCredentials;
use App\Exceptions\Inbox\CommentSourceException;
use App\Models\PostTarget;
use App\Models\SocialAccount;
use App\Support\Inbox\CommentReplyResult;
use App\Support\Inbox\CommentThreadReading;
use App\Support\Inbox\InboundComment;
use App\Support\Publishing\VendorHttp;
use Carbon\CarbonInterface;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

/**
 * Step M-9.1 — comments from Upload-Post's comments API
 * (docs.upload-post.com `comments`, M-0.2 audit), authenticated with the account's organization key
 * like every adapter call.
 *
 * - Read: `GET /uploadposts/comments?platform=&platform_post_id=&user=`. The response shape varies
 *   per platform, so the mapping takes the first key present for each field and tolerates the list
 *   living either at the top level or nested under `platforms.{vendor}`.
 * - Reply: `POST /uploadposts/comments/create` with the parent comment id and the reply text. The
 *   caller (`SendInboxReplyAction`) is the only path here, and only on an explicit human request.
 *
 * Reads use `VendorHttp::forReads()` (retries 429/5xx); the reply is a single, non-retried POST so a
 * network hiccup can never double-post. The API key is never logged, and comment bodies are never
 * logged verbatim.
 */
class UploadPostCommentSource implements CommentSource
{
    public function __construct(private readonly ?string $baseUrl = null) {}

    public function key(): string
    {
        return 'upload_post';
    }

    public function fetchComments(PostTarget $target, ?CarbonInterface $since = null): CommentThreadReading
    {
        $account = $target->socialAccount;

        if (! $account instanceof SocialAccount || ! $account->platform->supportsComments()) {
            return CommentThreadReading::empty();
        }

        $platformPostId = $target->platform_post_id;

        if ($platformPostId === null || $platformPostId === '') {
            // We never learned the network's own post id (e.g. an older target), so there is nothing
            // to query comments against. Not an error — just nothing to read.
            return CommentThreadReading::empty();
        }

        $query = [
            'platform' => $account->platform->spec()->vendor,
            'platform_post_id' => $platformPostId,
            'user' => $this->username($account),
        ];

        if ($since !== null) {
            $query['since'] = $since->toIso8601String();
        }

        $response = $this->get($account, '/uploadposts/comments', $query, $target);

        if ($response->status() === 404) {
            return CommentThreadReading::empty();
        }

        $this->ensureSuccessful($response, 'read comments', $target->id);

        return $this->mapComments($response, $account->platform->spec()->vendor);
    }

    public function reply(SocialAccount $account, string $parentCommentId, string $body): CommentReplyResult
    {
        $text = trim($body);

        if ($text === '') {
            throw new CommentSourceException('A reply cannot be empty.');
        }

        if (! $account->platform->supportsComments()) {
            throw new CommentSourceException("Replies aren't available for {$account->platform->label()}.");
        }

        try {
            $response = $this->writeClient($account)->post('/uploadposts/comments/create', [
                'platform' => $account->platform->spec()->vendor,
                'comment_id' => $parentCommentId,
                'message' => $text,
                'user' => $this->username($account),
            ]);
        } catch (Throwable $e) {
            Log::warning('Upload-Post reply request failed', [
                'social_account_id' => $account->id,
                'error' => $e->getMessage(),
            ]);

            throw new CommentSourceException('Could not reach the network to send your reply. Try again in a moment.', previous: $e);
        }

        $this->ensureSuccessful($response, 'send reply', null, $account->id);

        $data = is_array($response->json('comment')) ? $response->json('comment') : $response->json();
        $data = is_array($data) ? $data : [];

        return new CommentReplyResult(
            externalId: $this->stringField($data, ['id', 'comment_id']),
            vendorCommentId: $this->stringField($data, ['comment_id', 'id']),
            deliveredAt: now(),
        );
    }

    private function mapComments(Response $response, string $vendor): CommentThreadReading
    {
        $raw = $response->json("platforms.{$vendor}.comments");

        if (! is_array($raw)) {
            $raw = $response->json('comments');
        }

        if (! is_array($raw)) {
            return CommentThreadReading::empty();
        }

        $comments = [];

        foreach ($raw as $entry) {
            if (! is_array($entry)) {
                continue;
            }

            $externalId = $this->stringField($entry, ['id', 'comment_id']);
            $text = $this->stringField($entry, ['text', 'message', 'comment', 'body']);

            if ($externalId === null || $text === null) {
                continue;
            }

            $comments[] = new InboundComment(
                externalId: $externalId,
                body: $text,
                parentExternalId: $this->stringField($entry, ['parent_id', 'parent_comment_id', 'replied_to_id']),
                authorName: $this->stringField($entry, ['author', 'username', 'from_name', 'name']),
                authorHandle: $this->stringField($entry, ['handle', 'username', 'from_username']),
                authorAvatarUrl: $this->urlField($entry, ['avatar', 'profile_picture_url', 'author_avatar']),
                permalink: $this->urlField($entry, ['permalink', 'url', 'link']),
                postedAt: $this->dateField($entry, ['created_at', 'timestamp', 'created_time', 'posted_at']),
            );
        }

        return new CommentThreadReading($comments);
    }

    /**
     * @param  array<string, string>  $query
     */
    private function get(SocialAccount $account, string $path, array $query, ?PostTarget $target): Response
    {
        try {
            return $this->client($account)->get($path, $query);
        } catch (Throwable $e) {
            Log::warning('Upload-Post comments request failed', [
                'social_account_id' => $account->id,
                'post_target_id' => $target?->id,
                'path' => $path,
                'error' => $e->getMessage(),
            ]);

            throw new CommentSourceException('Could not reach Upload-Post for comments. We will try again on the next sync.', previous: $e);
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

        throw new CommentSourceException(match (true) {
            $response->status() === 401 || $response->status() === 403 => 'Upload-Post refused the comments request. Check the organization API key.',
            $response->status() === 429 => 'Upload-Post is rate-limiting comments. We will try again shortly.',
            is_string($message) && $message !== '' => Str::limit($message, 240),
            default => "Upload-Post returned an error ({$response->status()}) for {$what}.",
        });
    }

    /**
     * @param  array<string, mixed>  $data
     * @param  list<string>  $keys
     */
    private function stringField(array $data, array $keys): ?string
    {
        foreach ($keys as $key) {
            $value = $data[$key] ?? null;

            if (is_scalar($value) && trim((string) $value) !== '') {
                return trim((string) $value);
            }
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $data
     * @param  list<string>  $keys
     */
    private function urlField(array $data, array $keys): ?string
    {
        $value = $this->stringField($data, $keys);

        return $value !== null && Str::startsWith($value, ['https://', 'http://']) ? $value : null;
    }

    /**
     * @param  array<string, mixed>  $data
     * @param  list<string>  $keys
     */
    private function dateField(array $data, array $keys): ?Carbon
    {
        $value = $this->stringField($data, $keys);

        if ($value === null) {
            return null;
        }

        try {
            return is_numeric($value) ? Carbon::createFromTimestamp((int) $value) : Carbon::parse($value);
        } catch (Throwable) {
            return null;
        }
    }

    private function username(SocialAccount $account): string
    {
        return (string) ($account->external_account_id ?? $account->external_profile_id ?? $account->id);
    }

    private function client(SocialAccount $account): PendingRequest
    {
        return VendorHttp::forReads($this->baseRequest($account));
    }

    /**
     * The reply is a write: it retries only on an explicit 429 (never on a 5xx/timeout that may have
     * already posted), so a human's reply can never be sent twice.
     */
    private function writeClient(SocialAccount $account): PendingRequest
    {
        return VendorHttp::forPublish($this->baseRequest($account));
    }

    private function baseRequest(SocialAccount $account): PendingRequest
    {
        // Step B-5.1: byo (the org's own key) or managed (Alima's platform key), via the contract.
        $apiKey = app(PublishCredentials::class)->apiKeyFor($account);

        return Http::baseUrl($this->baseUrl ?? (string) config('services.upload_post.base_url', 'https://api.upload-post.com/api'))
            ->withHeaders(['Authorization' => 'Apikey '.$apiKey])
            ->acceptJson();
    }
}
