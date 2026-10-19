<?php

namespace App\Http\Requests;

use App\Enums\ImageAspectRatio;
use App\Models\AiImageGeneration;
use App\Models\AiPostIdea;
use App\Models\Client;
use App\Models\Post;
use App\Support\AI\Images\GenerateImageSetData;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Step 16.7: one "Generate images" click from the AI Image Studio. Authorization lives in the
 * controller (feature flag + AiImageGenerationPolicy::generate on the client).
 */
class GenerateImagesRequest extends FormRequest
{
    /**
     * Step I-1.9: authorize before validating, so another organization's request is refused
     * (403) instead of being told which fields are missing. The controller checks again.
     */
    public function authorize(): bool
    {
        $idea = $this->route('aiPostIdea');
        $client = $this->route('client') ?? ($idea instanceof AiPostIdea ? $idea->client : null);

        return $client instanceof Client && $this->user()->can('generate', [AiImageGeneration::class, $client]);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'request_id' => ['required', 'uuid'],
            'aspect_ratio' => ['required', Rule::enum(ImageAspectRatio::class)],
            'variant_count' => ['required', 'integer', 'min:1', 'max:'.(int) config('ai.images.max_variants', 4)],
            'post_id' => ['nullable', 'integer'],
            'art_direction' => ['nullable', 'array'],
            'art_direction.subject' => ['nullable', 'string'],
            'art_direction.mood' => ['nullable', 'string', 'max:200'],
            'art_direction.style' => ['nullable', 'string', 'max:300'],
            'art_direction.composition' => ['nullable', 'string', 'max:300'],
            'art_direction.excluded_colours' => ['nullable', 'array', 'max:20'],
            'art_direction.excluded_colours.*' => ['string', 'max:60'],
            'with_headline' => ['boolean'],
            'headline' => ['nullable', 'string', 'max:80', 'required_if_accepted:with_headline'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'headline.required_if_accepted' => 'Add the headline, or switch headline text off.',
            'headline.max' => 'Keep the headline under 80 characters.',
        ];
    }

    public function toData(?AiPostIdea $idea = null, ?Post $post = null): GenerateImageSetData
    {
        $art = (array) $this->validated('art_direction', []);

        return new GenerateImageSetData(
            requestId: (string) $this->validated('request_id'),
            aspectRatio: ImageAspectRatio::from((string) $this->validated('aspect_ratio')),
            variantCount: (int) $this->validated('variant_count'),
            idea: $idea,
            post: $post,
            overrides: array_filter([
                'subject' => $art['subject'] ?? null,
                'mood' => $art['mood'] ?? null,
                'style' => $art['style'] ?? null,
                'composition' => $art['composition'] ?? null,
                'excludedColours' => array_values($art['excluded_colours'] ?? []),
            ], fn ($value) => $value !== null && $value !== '' && $value !== []),
            withHeadline: $this->boolean('with_headline'),
            headline: $this->validated('headline'),
        );
    }
}
