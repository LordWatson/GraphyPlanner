<?php

namespace Tests\Feature;

use App\Enums\RegistrationInterestStatus;
use App\Mail\RegistrationInterestReceivedMail;
use App\Mail\RegistrationInterestThanksMail;
use App\Models\RegistrationInterest;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * The public "register interest" form at `/register`.
 */
class RegistrationInterestTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @return array<string, string>
     */
    private function payload(array $overrides = []): array
    {
        return [
            'name' => 'Rae Morgan',
            'email' => ' Rae@Northlight.test ',
            'phone' => '+44 7700 900123',
            'company_name' => 'Northlight Studio',
            'location' => 'Manchester, UK',
            'timezone' => 'Europe/London',
            'referrer' => 'https://www.instagram.com/some/post',
            'utm_source' => 'instagram',
            ...$overrides,
        ];
    }

    public function test_the_form_renders(): void
    {
        $this->get(route('register'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page->component('auth/register-interest'));
    }

    public function test_submitting_stores_the_lead_with_request_details_and_sends_both_emails(): void
    {
        Mail::fake();

        $this->from(route('register'))
            ->withHeaders(['CF-IPCountry' => 'gb', 'CF-IPCity' => 'Manchester', 'Accept-Language' => 'en-GB,en;q=0.9'])
            ->post(route('register.store'), $this->payload())
            ->assertSessionHasNoErrors()
            ->assertRedirect(route('register'));

        $interest = RegistrationInterest::sole();
        $this->assertSame('rae@northlight.test', $interest->email);
        $this->assertSame(RegistrationInterestStatus::New, $interest->status);
        $this->assertSame('GB', $interest->country);
        $this->assertSame('Manchester', $interest->city);
        $this->assertSame('en_GB', $interest->locale);
        $this->assertSame('Europe/London', $interest->timezone);
        $this->assertSame('instagram', $interest->utm_source);
        $this->assertSame('127.0.0.1', $interest->ip_address);

        Mail::assertQueued(RegistrationInterestThanksMail::class, fn ($mail) => $mail->hasTo('rae@northlight.test')
            && $mail->hasFrom('register@alima.digital'));
        Mail::assertQueued(RegistrationInterestReceivedMail::class, fn ($mail) => $mail->hasTo('alexander.watson.dev@gmail.com')
            && $mail->hasCc('rseklani@gmail.com')
            && $mail->hasFrom('register@alima.digital')
            && $mail->hasReplyTo('rae@northlight.test'));
    }

    public function test_optional_fields_can_be_left_blank_and_own_site_referrers_are_dropped(): void
    {
        Mail::fake();

        $this->post(route('register.store'), $this->payload([
            'phone' => '',
            'location' => '',
            'utm_source' => '',
            'referrer' => url('/pricing'),
        ]))->assertSessionHasNoErrors();

        $interest = RegistrationInterest::sole();
        $this->assertNull($interest->phone);
        $this->assertNull($interest->location);
        $this->assertNull($interest->referrer);
        $this->assertNull($interest->country);
    }

    public function test_required_fields_and_formats_are_validated(): void
    {
        Mail::fake();

        $this->post(route('register.store'), $this->payload([
            'name' => '',
            'email' => 'not-an-email',
            'company_name' => '',
            'phone' => 'call me maybe',
        ]))->assertSessionHasErrors(['name', 'email', 'company_name', 'phone']);

        $this->assertDatabaseCount('registration_interests', 0);
        Mail::assertNothingQueued();
    }

    public function test_a_filled_honeypot_pretends_to_succeed_but_stores_and_sends_nothing(): void
    {
        Mail::fake();

        $this->post(route('register.store'), $this->payload(['website' => 'https://spam.test']))
            ->assertSessionHasNoErrors()
            ->assertRedirect();

        $this->assertDatabaseCount('registration_interests', 0);
        Mail::assertNothingQueued();
    }

    public function test_the_thanks_email_renders_with_the_promised_reply_time(): void
    {
        $interest = RegistrationInterest::factory()->create(['name' => 'Rae Morgan']);

        (new RegistrationInterestThanksMail($interest))
            ->assertSeeInHtml('Thanks, Rae.')
            ->assertSeeInHtml('within 24 hours');

        (new RegistrationInterestReceivedMail($interest))
            ->assertSeeInHtml($interest->company_name)
            ->assertSeeInHtml('mailto:'.$interest->email)
            ->assertSeeInHtml(route('super-admin.registrations.show', $interest))
            ->assertSeeInText($interest->email)
            ->assertSeeInText(route('super-admin.registrations.show', $interest));
    }
}
