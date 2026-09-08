<?php

namespace App\Http\Requests;

use App\Enums\AssetSource;
use App\Enums\AssetType;
use App\Models\Asset;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rules\Enum;

class StoreAssetRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return $this->user()->can('create', [Asset::class, $this->route('client')]);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'source' => ['required', new Enum(AssetSource::class)],
            'campaign_id' => ['nullable', 'integer', 'exists:campaigns,id'],
            'type' => ['nullable', new Enum(AssetType::class)],
            'file' => ['required_if:source,upload', 'file', 'max:'.config('assets.max_upload_size_kb')],
            'url' => ['required_if:source,figma,url', 'nullable', 'url', 'max:2048'],
            'rights' => ['nullable', 'string'],
            'variant_group_id' => ['nullable', 'uuid'],
        ];
    }
}
