<?php

namespace App\Http\Requests;

use App\Enums\ApprovalMode;
use App\Enums\MediaRatio;
use App\Enums\Platform;
use App\Models\Post;
use App\Models\SocialAccount;
use App\Support\Media\CropBox;
use Closure;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Enum;
use Illuminate\Validation\Validator;

class UpdatePostRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return $this->user()->can('update', $this->route('post'));
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        // Step I-1.9: referenced records must belong to this client — a bare `exists:` rule would
        // accept another organization's ids.
        $post = $this->route('post');
        $clientId = $post instanceof Post ? $post->client_id : null;

        $platforms = array_map(fn (Platform $platform) => $platform->value, Platform::cases());

        return [
            // Step B-1.4: client approval isn't a concept in a business workspace.
            'approval_mode' => ['nullable', new Enum(ApprovalMode::class), $this->rejectClientApprovalForBusiness()],
            'campaign_id' => ['nullable', 'integer', Rule::exists('campaigns', 'id')->where('client_id', $clientId)],
            'master_caption' => ['nullable', 'string', 'max:65000'],
            'review_message' => ['nullable', 'string'],
            'hashtags' => ['nullable', 'array'],
            'hashtags.*' => ['string', 'max:150'],
            // Step I-3.1 / M-2.2: master link and first comment (each channel can override them).
            'link_url' => ['nullable', 'url:http,https', 'max:2048'],
            'first_comment' => ['nullable', 'string', 'max:3000'],
            // Step I-3.2 / M-2.3: the cover image a video publishes with, and its frame offset.
            'cover_asset_id' => ['nullable', 'integer', Rule::exists('assets', 'id')->where('client_id', $clientId)->whereNull('deleted_at')],
            'thumb_offset_ms' => ['nullable', 'integer', 'min:0', 'max:86400000'],
            'music' => ['nullable', 'array'],
            'location' => ['nullable', 'array'],

            // Image format per channel — only the formats that channel offers.
            'media_format' => ['nullable', 'array:'.implode(',', $platforms)],
            ...collect(Platform::cases())->mapWithKeys(fn (Platform $platform) => [
                "media_format.{$platform->value}" => ['nullable', Rule::in(array_map(
                    fn (MediaRatio $ratio) => $ratio->value,
                    MediaRatio::optionsFor($platform),
                ))],
            ])->all(),

            'asset_ids' => ['nullable', 'array'],
            'asset_ids.*' => ['integer', Rule::exists('assets', 'id')->where('client_id', $clientId)->whereNull('deleted_at')],

            // Framing per attached image, keyed by asset id: focus point (0–1) and zoom.
            'asset_crops' => ['nullable', 'array'],
            'asset_crops.*' => ['array:x,y,zoom'],
            'asset_crops.*.x' => ['required', 'numeric', 'between:0,1'],
            'asset_crops.*.y' => ['required', 'numeric', 'between:0,1'],
            'asset_crops.*.zoom' => ['required', 'numeric', 'between:'.CropBox::MIN_ZOOM.','.CropBox::MAX_ZOOM],

            // Alt text per attached image, keyed by asset id.
            'asset_alt_texts' => ['nullable', 'array'],
            'asset_alt_texts.*' => ['nullable', 'string', 'max:1000'],

            'targets' => ['nullable', 'array'],
            'targets.*.social_account_id' => ['required', 'integer', 'distinct', Rule::exists('social_accounts', 'id')->where('client_id', $clientId)->whereNull('deleted_at')],
            'targets.*.scheduled_local_date' => ['nullable', 'date'],
            'targets.*.scheduled_local_time' => ['nullable', 'date_format:H:i,H:i:s'],
            // Step I-3.1 / M-2.2: per-channel overrides (absent/null = use the master).
            'targets.*.caption_override' => ['nullable', 'string', 'max:65000'],
            // A JSON list of tags (or a plain list); "[]" means "no hashtags on this channel".
            'targets.*.hashtags_override' => ['nullable', function (string $attribute, mixed $value, Closure $fail) {
                $tags = is_string($value) ? json_decode($value, true) : $value;

                if (! is_array($tags) || ! array_is_list($tags) || collect($tags)->contains(fn ($tag) => ! is_string($tag) || mb_strlen($tag) > 150)) {
                    $fail(__('Hashtags for a channel must be a list of tags.'));
                }
            }],
            'targets.*.first_comment' => ['nullable', 'string', 'max:3000'],
            'targets.*.link_url' => ['nullable', 'string', 'max:2048'],
            // Step I-3.2 / M-2.3: checked against the account's platform in `after()`.
            'targets.*.format' => ['nullable', 'string', 'max:32'],
        ];
    }

    /**
     * Step B-1.4 — a business workspace has no client to approve posts, so `ApprovalMode::
     * ClientRequired` is rejected for it (agencies are unaffected).
     */
    private function rejectClientApprovalForBusiness(): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail): void {
            if ($value === ApprovalMode::ClientRequired->value && $this->user()?->organization?->isBusiness() === true) {
                $fail(__('Client approval isn’t available in this workspace.'));
            }
        };
    }

    /**
     * Empty form fields arrive as null after `ConvertEmptyStringsToNull`; an override sent as
     * the literal empty-override marker keeps meaning "none on this channel".
     */
    protected function prepareForValidation(): void
    {
        $targets = $this->input('targets');

        if (! is_array($targets)) {
            return;
        }

        foreach ($targets as $i => $target) {
            foreach (['caption_override', 'first_comment', 'link_url'] as $field) {
                if (is_array($target) && ($target[$field] ?? null) === self::EMPTY_OVERRIDE) {
                    $targets[$i][$field] = '';
                }
            }
        }

        $this->merge(['targets' => $targets]);
    }

    /**
     * What the editor sends for "this channel deliberately has none" (an empty input would be
     * turned into null = "use the master").
     */
    public const EMPTY_OVERRIDE = '__none__';

    /**
     * Framing can only be saved for images that end up attached to the post.
     *
     * @return array<int, callable(Validator): void>
     */
    public function after(): array
    {
        return [
            function (Validator $validator) {
                $crops = $this->input('asset_crops');

                if (! is_array($crops) || $crops === []) {
                    return;
                }

                $post = $this->route('post');
                $attached = $this->has('asset_ids')
                    ? array_map('intval', (array) $this->input('asset_ids', []))
                    : ($post instanceof Post ? $post->assets()->pluck('assets.id')->all() : []);

                foreach (array_keys($crops) as $assetId) {
                    if (! in_array((int) $assetId, $attached, true)) {
                        $validator->errors()->add("asset_crops.{$assetId}", __('Framing can only be set for media attached to this post.'));
                    }
                }
            },
            function (Validator $validator) {
                $targets = $this->input('targets');

                if (! is_array($targets) || $validator->errors()->isNotEmpty()) {
                    return;
                }

                $accounts = SocialAccount::query()
                    ->whereIn('id', collect($targets)->pluck('social_account_id')->filter()->all())
                    ->get()
                    ->keyBy('id');

                foreach ($targets as $i => $target) {
                    $link = is_array($target) ? ($target['link_url'] ?? null) : null;

                    if (filled($link) && filter_var($link, FILTER_VALIDATE_URL) === false) {
                        $validator->errors()->add("targets.{$i}.link_url", __('Enter a full link, starting with https://.'));
                    }

                    $format = is_array($target) ? ($target['format'] ?? null) : null;
                    $account = $accounts->get((int) ($target['social_account_id'] ?? 0));

                    if (filled($format) && $account !== null && ! $account->platform->spec()->hasFormat((string) $format)) {
                        $validator->errors()->add("targets.{$i}.format", __(':platform doesn’t offer that format.', ['platform' => $account->platform->label()]));
                    }
                }
            },
        ];
    }
}
