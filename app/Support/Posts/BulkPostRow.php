<?php

namespace App\Support\Posts;

/**
 * Step M-5.8 (Phase 10) — one validated row of a bulk CSV import: a single post for the client,
 * going to one or more of its accounts, all sharing the row's schedule. Content lives at the master
 * level (every channel inherits it); `format` applies to each channel. Built only once a row has
 * passed validation, so a `BulkPostRow` always resolves to real account/campaign ids.
 */
final readonly class BulkPostRow
{
    /**
     * @param  list<string>  $hashtags
     * @param  list<int>  $accountIds  resolved `social_accounts.id` for this client
     */
    public function __construct(
        public int $rowNumber,
        public string $caption,
        public array $hashtags,
        public array $accountIds,
        public ?int $campaignId,
        public ?string $scheduledLocalDate,
        public ?string $scheduledLocalTime,
        public ?string $firstComment,
        public ?string $linkUrl,
        public ?string $format,
    ) {}

    /**
     * The master-level `$data` for `CreatePostAction` (the per-channel `format` is applied to the
     * created targets afterwards, since `CreatePostAction` only sets each target's schedule).
     *
     * @return array<string, mixed>
     */
    public function toCreateData(string $status): array
    {
        return [
            'status' => $status,
            'master_caption' => $this->caption !== '' ? $this->caption : null,
            'hashtags' => $this->hashtags,
            'campaign_id' => $this->campaignId,
            'first_comment' => $this->firstComment,
            'link_url' => $this->linkUrl,
            'targets' => array_map(fn (int $id) => [
                'social_account_id' => $id,
                'scheduled_local_date' => $this->scheduledLocalDate,
                'scheduled_local_time' => $this->scheduledLocalTime,
            ], $this->accountIds),
        ];
    }
}
