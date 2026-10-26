<?php

namespace Database\Factories;

use App\Models\Client;
use App\Models\McpUploadSession;
use App\Models\Organization;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<McpUploadSession>
 */
class McpUploadSessionFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'org_id' => Organization::factory(),
            'client_id' => Client::factory(),
            'token_hash' => McpUploadSession::hashToken(Str::random(64)),
            'post_ids' => [],
            'uploaded_asset_ids' => [],
            'expires_at' => now()->addMinutes(McpUploadSession::DEFAULT_TTL_MINUTES),
            'created_by' => null,
        ];
    }

    public function expired(): static
    {
        return $this->state(['expires_at' => now()->subMinute()]);
    }
}
