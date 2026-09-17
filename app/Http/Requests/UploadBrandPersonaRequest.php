<?php

namespace App\Http\Requests;

use App\Models\Client;
use App\Policies\BrandBrainPolicy;
use Illuminate\Foundation\Http\FormRequest;

class UploadBrandPersonaRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        /** @var Client $client */
        $client = $this->route('client');

        return app(BrandBrainPolicy::class)->update($this->user(), $client);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'persona' => ['required', 'file', 'mimes:pdf', 'max:'.config('assets.max_upload_size_kb')],
        ];
    }
}
