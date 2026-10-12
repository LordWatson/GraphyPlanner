<?php

namespace Tests\Unit\Models;

use App\Models\SocialAccount;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class SocialAccountTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Step 1.9.2: `meta_access_token` must round-trip through Laravel's `encrypted` cast and
     * never be stored in plaintext, mirroring how every other secret in this app is handled.
     */
    public function test_meta_access_token_round_trips_through_the_encrypted_cast(): void
    {
        $account = SocialAccount::factory()->metaConnected()->create();

        $raw = DB::table('social_accounts')->where('id', $account->id)->value('meta_access_token');

        $this->assertNotSame($account->meta_access_token, $raw);
        $this->assertSame($account->meta_access_token, $account->fresh()->meta_access_token);
    }
}
