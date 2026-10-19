<?php

namespace App\Http\Requests;

use App\Enums\ApprovalMode;
use App\Enums\MediaRatio;
use App\Enums\Platform;
use App\Models\Post;
use App\Support\Media\CropBox;
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
            'approval_mode' => ['nullable', new Enum(ApprovalMode::class)],
            'campaign_id' => ['nullable', 'integer', Rule::exists('campaigns', 'id')->where('client_id', $clientId)],
            'master_caption' => ['nullable', 'string'],
            'review_message' => ['nullable', 'string'],
            'hashtags' => ['nullable', 'array'],
            'hashtags.*' => ['string'],
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

            'targets' => ['nullable', 'array'],
            'targets.*.social_account_id' => ['required', 'integer', Rule::exists('social_accounts', 'id')->where('client_id', $clientId)->whereNull('deleted_at')],
            'targets.*.scheduled_local_date' => ['nullable', 'date'],
            'targets.*.scheduled_local_time' => ['nullable', 'date_format:H:i,H:i:s'],
        ];
    }

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
        ];
    }
}
