<?php

namespace App\Http\Requests;

use App\Enums\AssetSource;
use App\Enums\AssetType;
use App\Models\Asset;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\UploadedFile;
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
            'file' => [
                'required_if:source,upload',
                'file',
                'mimes:'.implode(',', config('assets.allowed_upload_extensions')),
                $this->maxFileSizeRule(),
            ],
            'url' => ['required_if:source,figma,url', 'nullable', 'url', 'max:2048'],
            'rights' => ['nullable', 'string'],
            'variant_group_id' => ['nullable', 'uuid'],
        ];
    }

    /**
     * Video files get a much higher size ceiling than images/documents (see
     * `config('assets.max_video_upload_size_kb')`), so the "max" rule can't be a static
     * string — it depends on the uploaded file's mime type.
     */
    private function maxFileSizeRule(): \Closure
    {
        return function (string $attribute, mixed $value, \Closure $fail): void {
            if (! $value instanceof UploadedFile) {
                return;
            }

            $isVideo = str_starts_with((string) $value->getMimeType(), 'video/');
            $maxKb = $isVideo
                ? config('assets.max_video_upload_size_kb')
                : config('assets.max_upload_size_kb');

            if ($value->getSize() > $maxKb * 1024) {
                $fail('The '.$attribute.' may not be greater than '.$maxKb.' kilobytes.');
            }
        };
    }
}
