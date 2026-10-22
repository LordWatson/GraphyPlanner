<?php

namespace App\Http\Requests\Business;

use App\Policies\BrandBrainPolicy;
use App\Services\Workspace\CurrentWorkspace;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Step B-2.3 / B-2.4 — persists the owner's accepted (or edited) brand voice from the wizard's
 * "Your voice" step onto the primary brand's Brand Brain via `UpdateBrandBrainAction`. Only the
 * quick-start slices are editable here (voice, audience, offer, content pillars); the rest of the
 * Brand Brain is filled later in `/brand`.
 */
class SaveBrandVoiceRequest extends FormRequest
{
    public function authorize(): bool
    {
        $brand = app(CurrentWorkspace::class)->brand();

        return $brand !== null && app(BrandBrainPolicy::class)->update($this->user(), $brand);
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
            'audience.description' => ['nullable', 'string', 'max:2000'],
            'audience.demographics' => ['nullable', 'string', 'max:255'],
            'audience.pain_points' => ['nullable', 'array'],
            'audience.pain_points.*' => ['string', 'max:255'],

            'offer' => ['nullable', 'array'],
            'offer.value_proposition' => ['nullable', 'string', 'max:2000'],
            'offer.key_products' => ['nullable', 'array'],
            'offer.key_products.*' => ['string', 'max:255'],
            'offer.pricing_notes' => ['nullable', 'string', 'max:2000'],

            'content_pillars' => ['nullable', 'array'],
            'content_pillars.*' => ['string', 'max:255'],
        ];
    }

    /**
     * Only the keys present are written, so an empty step doesn't wipe a field the user filled in
     * `/brand` later. Returns the Brand Brain slice to hand to `UpdateBrandBrainAction`.
     *
     * @return array<string, mixed>
     */
    public function brandBrainData(): array
    {
        return array_intersect_key(
            $this->validated(),
            array_flip(['voice', 'audience', 'offer', 'content_pillars']),
        );
    }
}
