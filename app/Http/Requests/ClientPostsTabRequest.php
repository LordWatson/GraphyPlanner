<?php

namespace App\Http\Requests;

use App\Enums\Platform;
use App\Enums\PostStatus;
use App\Models\Client;
use App\Models\Post;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * The client page's Posts tab query string: caption search, status, campaign and channel filters.
 */
class ClientPostsTabRequest extends FormRequest
{
    public function authorize(): bool
    {
        /** @var Client $client */
        $client = $this->route('client');

        return $this->user()->can('viewAny', [Post::class, $client]);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'search' => ['nullable', 'string', 'max:100'],
            'status' => ['nullable', Rule::enum(PostStatus::class)],
            'campaign' => ['nullable', 'integer'],
            'platform' => ['nullable', Rule::enum(Platform::class)],
            'page' => ['nullable', 'integer', 'min:1'],
        ];
    }

    /**
     * @return array{search: string|null, status: string|null, campaign: int|null, platform: string|null}
     */
    public function filters(): array
    {
        return [
            'search' => $this->string('search')->trim()->toString() ?: null,
            'status' => $this->string('status')->toString() ?: null,
            'campaign' => $this->integer('campaign') ?: null,
            'platform' => $this->string('platform')->toString() ?: null,
        ];
    }
}
