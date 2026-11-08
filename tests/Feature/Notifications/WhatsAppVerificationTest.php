<?php

namespace Tests\Feature\Notifications;

use App\Contracts\WhatsAppClient;
use App\Enums\NotificationCategory;
use App\Models\User;
use App\Models\WhatsAppSuppression;
use App\Support\Notifications\NotificationPreferences;
use App\Support\WhatsApp\MessageTemplate;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * Step W-1.1 — member opt-in and number verification.
 *
 * The thing worth testing here is not the happy path but everything around it: that a number is
 * useless until verified, that changing it un-verifies it, that a wrong code cannot be ground down
 * by guessing, and that removing a number takes the opt-ins with it.
 */
class WhatsAppVerificationTest extends TestCase
{
    use RefreshDatabase;

    private RecordingWhatsAppClient $client;

    protected function setUp(): void
    {
        parent::setUp();

        $this->client = new RecordingWhatsAppClient;
        $this->app->instance(WhatsAppClient::class, $this->client);
    }

    private function codeSentTo(User $user): string
    {
        // The code is hashed at rest, so the test reads it from the message that was sent — which
        // is also the only place the person themselves ever sees it.
        [, , $message] = $this->client->sent[0];

        $this->assertSame(MessageTemplate::VerifyCode, $message->template);

        return $message->values['code'];
    }

    public function test_requesting_a_code_sends_one_and_leaves_the_number_unverified(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->post(route('whatsapp-number.store'), ['phone' => '+44 7700 900123'])
            ->assertRedirect();

        $user->refresh();
        $this->assertSame('+447700900123', $user->phone);
        $this->assertNull($user->phone_verified_at);
        $this->assertNotNull($user->phone_verification_code);
        // Hashed, not stored in the clear.
        $this->assertTrue(Hash::check($this->codeSentTo($user), $user->phone_verification_code));
        $this->assertNull($user->routeNotificationForWhatsApp());
    }

    public function test_confirming_the_code_verifies_the_number(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->post(route('whatsapp-number.store'), ['phone' => '+447700900123']);
        $code = $this->codeSentTo($user);

        $this->actingAs($user)
            ->put(route('whatsapp-number.update'), ['code' => $code])
            ->assertRedirect();

        $user->refresh();
        $this->assertNotNull($user->phone_verified_at);
        $this->assertNull($user->phone_verification_code);
        $this->assertSame('+447700900123', $user->routeNotificationForWhatsApp());
    }

    public function test_a_number_without_a_country_code_is_refused(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->post(route('whatsapp-number.store'), ['phone' => '07700 900123'])
            ->assertSessionHasErrors('phone');

        $this->assertSame([], $this->client->sent);
        $this->assertNull($user->refresh()->phone);
    }

    public function test_a_wrong_code_is_rejected_and_counted(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user)->post(route('whatsapp-number.store'), ['phone' => '+447700900123']);

        $this->actingAs($user)
            ->put(route('whatsapp-number.update'), ['code' => '000000'])
            ->assertSessionHasErrors('code');

        $this->assertNull($user->refresh()->phone_verified_at);
        $this->assertSame(1, $user->phone_verification_attempts);
    }

    public function test_guessing_is_capped_and_the_code_is_discarded(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user)->post(route('whatsapp-number.store'), ['phone' => '+447700900123']);
        $code = $this->codeSentTo($user);

        for ($i = 0; $i < 5; $i++) {
            $this->actingAs($user)->put(route('whatsapp-number.update'), ['code' => '000000']);
        }

        // Even the right code is no good now: the sixth attempt throws it away.
        $this->actingAs($user)
            ->put(route('whatsapp-number.update'), ['code' => $code])
            ->assertSessionHasErrors('code');

        $user->refresh();
        $this->assertNull($user->phone_verified_at);
        $this->assertNull($user->phone_verification_code);
    }

    public function test_an_expired_code_is_refused(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user)->post(route('whatsapp-number.store'), ['phone' => '+447700900123']);
        $code = $this->codeSentTo($user);

        $this->travel(11)->minutes();

        $this->actingAs($user)
            ->put(route('whatsapp-number.update'), ['code' => $code])
            ->assertSessionHasErrors('code');

        $this->assertNull($user->refresh()->phone_verified_at);
    }

    public function test_a_code_cannot_be_requested_twice_in_a_row(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->post(route('whatsapp-number.store'), ['phone' => '+447700900123']);
        $this->actingAs($user)
            ->post(route('whatsapp-number.store'), ['phone' => '+447700900123'])
            ->assertSessionHasErrors('phone');

        $this->assertCount(1, $this->client->sent);
    }

    public function test_changing_the_number_un_verifies_it(): void
    {
        $user = User::factory()->whatsAppVerified()->create();
        $this->travel(2)->minutes();

        $this->actingAs($user)->post(route('whatsapp-number.store'), ['phone' => '+447700900999']);

        $user->refresh();
        $this->assertSame('+447700900999', $user->phone);
        $this->assertNull($user->phone_verified_at);
        $this->assertNull($user->routeNotificationForWhatsApp());
    }

    public function test_a_suppressed_number_cannot_be_verified(): void
    {
        $user = User::factory()->create();
        WhatsAppSuppression::suppress('+447700900123', WhatsAppSuppression::REASON_STOP);

        $this->actingAs($user)
            ->post(route('whatsapp-number.store'), ['phone' => '+447700900123'])
            ->assertSessionHasErrors('phone');

        $this->assertSame([], $this->client->sent);
    }

    public function test_removing_the_number_takes_the_opt_ins_with_it(): void
    {
        $user = User::factory()->whatsAppVerified()->create();
        $preferences = $user->notification_preferences ?? [];
        $preferences[NotificationCategory::Approvals->value]['whatsapp'] = true;
        $user->update(['notification_preferences' => $preferences]);

        $this->actingAs($user)->delete(route('whatsapp-number.destroy'))->assertRedirect();

        $user->refresh();
        $this->assertNull($user->phone);
        $this->assertNull($user->phone_verified_at);
        // Otherwise verifying a new number later would quietly resume messaging them.
        $this->assertFalse(NotificationPreferences::wants($user, NotificationCategory::Approvals, 'whatsapp'));
    }

    public function test_nobody_can_set_somebody_elses_number(): void
    {
        // There is no route that takes a user: the only number a member can touch is their own.
        $this->post(route('whatsapp-number.store'), ['phone' => '+447700900123'])->assertRedirect(route('login'));
    }

    public function test_the_settings_page_shows_the_state_without_the_number(): void
    {
        $user = User::factory()->whatsAppVerified()->create();

        $this->actingAs($user)
            ->get(route('notifications.edit'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('whatsapp.verified', true)
                ->where('whatsapp.masked_phone', '***0123')
                ->where('preferences.0.whatsapp', false))
            // The full number must not reach the page.
            ->assertDontSee('447700900123');
    }
}
