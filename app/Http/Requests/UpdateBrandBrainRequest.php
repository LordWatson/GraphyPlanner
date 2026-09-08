<?php

namespace App\Http\Requests;

use App\Models\BrandBrain;
use App\Models\Client;
use Illuminate\Foundation\Http\FormRequest;

class UpdateBrandBrainRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        /** @var Client $client */
        $client = $this->route('client');

        return app(\App\Policies\BrandBrainPolicy::class)->update($this->user(), $client);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'voice' => ['nullable', 'array'],
            'voice.tone' => ['nullable', 'string', 'max:255'],
            'voice.personality' => ['nullable', 'string', 'max:255'],
            'voice.do_nots' => ['nullable', 'array'],
            'voice.do_nots.*' => ['string', 'max:255'],

            'audience' => ['nullable', 'array'],
            'audience.description' => ['nullable', 'string'],
            'audience.demographics' => ['nullable', 'string', 'max:255'],
            'audience.pain_points' => ['nullable', 'array'],
            'audience.pain_points.*' => ['string', 'max:255'],

            'offer' => ['nullable', 'array'],
            'offer.value_proposition' => ['nullable', 'string'],
            'offer.key_products' => ['nullable', 'array'],
            'offer.key_products.*' => ['string', 'max:255'],
            'offer.pricing_notes' => ['nullable', 'string'],

            'visual' => ['nullable', 'array'],
            'visual.color_palette' => ['nullable', 'array'],
            'visual.color_palette.*' => ['string', 'max:50'],
            'visual.typography' => ['nullable', 'string', 'max:255'],
            'visual.imagery_style' => ['nullable', 'string'],
            'visual.logo_usage_notes' => ['nullable', 'string'],

            'music_policy' => ['nullable', 'array'],
            'music_policy.allowed_genres' => ['nullable', 'array'],
            'music_policy.allowed_genres.*' => ['string', 'max:100'],
            'music_policy.disallowed_genres' => ['nullable', 'array'],
            'music_policy.disallowed_genres.*' => ['string', 'max:100'],
            'music_policy.notes' => ['nullable', 'string'],

            'hashtag_policy' => ['nullable', 'array'],
            'hashtag_policy.always_use' => ['nullable', 'array'],
            'hashtag_policy.always_use.*' => ['string', 'max:100'],
            'hashtag_policy.never_use' => ['nullable', 'array'],
            'hashtag_policy.never_use.*' => ['string', 'max:100'],
            'hashtag_policy.rotation_notes' => ['nullable', 'string'],

            'content_pillars' => ['nullable', 'array'],
            'content_pillars.*' => ['string', 'max:255'],
        ];
    }

    /**
     * Split newline-delimited textarea input into arrays for the list-like fields before validation.
     */
    protected function prepareForValidation(): void
    {
        $listPaths = [
            'voice.do_nots',
            'audience.pain_points',
            'offer.key_products',
            'visual.color_palette',
            'music_policy.allowed_genres',
            'music_policy.disallowed_genres',
            'hashtag_policy.always_use',
            'hashtag_policy.never_use',
            'content_pillars',
        ];

        $input = $this->all();

        foreach ($listPaths as $path) {
            $value = data_get($input, $path);

            if (is_string($value)) {
                $items = array_values(array_filter(array_map('trim', preg_split('/\r?\n/', $value)), fn ($item) => $item !== ''));
                data_set($input, $path, $items);
            }
        }

        $this->replace($input);
    }
}
