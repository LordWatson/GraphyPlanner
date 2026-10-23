<?php

namespace Database\Factories;

use App\Models\PushSubscription;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<PushSubscription>
 */
class PushSubscriptionFactory extends Factory
{
    protected $model = PushSubscription::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $endpoint = 'https://fcm.googleapis.com/fcm/send/'.$this->faker->unique()->sha256();

        return [
            'user_id' => User::factory(),
            'endpoint' => $endpoint,
            'endpoint_hash' => PushSubscription::hashEndpoint($endpoint),
            'public_key' => base64_encode($this->faker->sha256()),
            'auth_token' => base64_encode($this->faker->sha1()),
            'content_encoding' => 'aes128gcm',
        ];
    }
}
