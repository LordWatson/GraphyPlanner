<?php

namespace App\Http\Requests;

use App\Enums\AssetType;
use App\Http\Requests\Concerns\ResolvesWorkspaceBrand;
use App\Models\Asset;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rules\Enum;

class SearchAssetsRequest extends FormRequest
{
    use ResolvesWorkspaceBrand;

    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return $this->user()->can('viewAny', [Asset::class, $this->brand()]);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'search' => ['nullable', 'string', 'max:100'],
            'type' => ['nullable', new Enum(AssetType::class)],
            'page' => ['nullable', 'integer', 'min:1'],
            'campaign' => ['nullable', 'integer'],
        ];
    }
}
