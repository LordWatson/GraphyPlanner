<?php

namespace App\Http\Requests\Business;

use App\Enums\BioBlockType;
use App\Enums\BioTheme;
use App\Http\Requests\Concerns\ResolvesWorkspaceBrand;
use App\Models\BioPage;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Step CG-3.2 — the whole bio page in one submit: its details plus the full ordered block list
 * (array order is the page order). The handle is the public namespace, so it's globally unique,
 * lowercase, and can't be one of {@see BioPage::RESERVED_HANDLES}. Only block types that render
 * today ({@see BioBlockType::live()}) are accepted.
 */
class SaveBioPageRequest extends FormRequest
{
    use ResolvesWorkspaceBrand;

    /** A page people can actually read, and a cheap ceiling on the sync write. */
    public const MAX_BLOCKS = 20;

    public function authorize(): bool
    {
        $brand = $this->brand();

        return $brand !== null && $this->user()->can('update', $brand);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $pageId = BioPage::query()->where('client_id', $this->brandId())->value('id');

        return [
            'handle' => [
                'required', 'string', 'min:3', 'max:32', 'regex:/^[a-z0-9][a-z0-9._-]*[a-z0-9]$/',
                Rule::notIn(BioPage::RESERVED_HANDLES),
                Rule::unique('bio_pages', 'handle')->ignore($pageId),
            ],
            'title' => ['nullable', 'string', 'max:120'],
            'bio' => ['nullable', 'string', 'max:500'],
            'theme' => ['required', Rule::in(BioTheme::values())],
            'is_published' => ['required', 'boolean'],

            'blocks' => ['present', 'array', 'max:'.self::MAX_BLOCKS],
            'blocks.*.id' => ['nullable', 'integer'],
            'blocks.*.type' => ['required', Rule::in(BioBlockType::liveValues())],
            'blocks.*.is_visible' => ['required', 'boolean'],
            'blocks.*.settings' => ['nullable', 'array'],
            'blocks.*.settings.label' => ['nullable', 'string', 'max:60'],
            'blocks.*.settings.url' => [
                'nullable', 'required_if:blocks.*.type,'.BioBlockType::Link->value,
                'url:http,https', 'max:2048',
            ],
            'blocks.*.settings.limit' => ['nullable', 'integer', 'min:1', 'max:6'],
        ];
    }

    public function prepareForValidation(): void
    {
        if ($this->has('handle')) {
            $this->merge(['handle' => strtolower(trim((string) $this->input('handle')))]);
        }
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'handle.regex' => __('Use letters, numbers, dots, dashes and underscores only.'),
            'handle.not_in' => __('That handle is reserved — pick another.'),
            'handle.unique' => __('That handle is taken — pick another.'),
            'blocks.*.settings.url.required_if' => __('A link block needs a web address.'),
        ];
    }
}
