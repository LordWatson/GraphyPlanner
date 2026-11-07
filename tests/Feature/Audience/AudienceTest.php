<?php

namespace Tests\Feature\Audience;

use App\Actions\Audience\ImportAudienceContactsAction;
use App\Actions\Audience\SendAudienceTriggerAction;
use App\Actions\Store\ExpireStaleOrdersAction;
use App\Actions\Store\FulfilOrderAction;
use App\Enums\AudienceSource;
use App\Enums\AudienceTrigger;
use App\Enums\BioBlockType;
use App\Enums\OrderStatus;
use App\Enums\OrganizationType;
use App\Enums\ProductStatus;
use App\Enums\ProductType;
use App\Enums\Role;
use App\Jobs\SendAudienceTriggerJob;
use App\Mail\AudienceConfirmMail;
use App\Mail\AudienceMessageMail;
use App\Models\AiUsageLog;
use App\Models\AudienceContact;
use App\Models\BioPage;
use App\Models\Client;
use App\Models\Order;
use App\Models\Organization;
use App\Models\PaymentAccount;
use App\Models\Product;
use App\Models\User;
use App\Support\Audience\AudienceLinks;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * Phase GTM-2 (closes CG-6.1–6.3) — the creator's email list: double opt-in, what an import may
 * and may not assume, the three automated triggers firing exactly once, and a broadcast that only
 * a person can send.
 */
class AudienceTest extends TestCase
{
    use RefreshDatabase;

    private Organization $org;

    private Client $brand;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();

        Mail::fake();

        $this->org = Organization::factory()->create([
            'type' => OrganizationType::Business,
            'plan' => 'creator_pro',
            'default_currency' => 'GBP',
        ]);
        $this->brand = Client::factory()->for($this->org, 'organization')->primary()->create(['name' => 'Jess Bakery']);
        $this->owner = User::factory()->for($this->org, 'organization')->role(Role::Owner)->create();
    }

    private function page(bool $withCaptureBlock = true): BioPage
    {
        $page = BioPage::create([
            'org_id' => $this->org->id,
            'client_id' => $this->brand->id,
            'handle' => 'jessbakery',
            'theme' => 'cream',
            'published_at' => now(),
        ]);

        if ($withCaptureBlock) {
            $page->blocks()->create([
                'type' => BioBlockType::EmailCapture,
                'position' => 0,
                'settings' => ['label' => 'Get my emails'],
                'is_visible' => true,
            ]);
        }

        return $page;
    }

    private function contact(array $attributes = []): AudienceContact
    {
        return AudienceContact::create([
            'org_id' => $this->org->id,
            'client_id' => $this->brand->id,
            'email' => 'fan@example.test',
            'source' => AudienceSource::BioCapture,
            ...$attributes,
        ]);
    }

    private function product(array $overrides = []): Product
    {
        return Product::create([
            'org_id' => $this->org->id,
            'client_id' => $this->brand->id,
            'type' => ProductType::Download,
            'title' => 'Sourdough guide',
            'price_minor' => 1200,
            'currency' => 'GBP',
            'status' => ProductStatus::Live,
            ...$overrides,
        ]);
    }

    private function order(Product $product, array $overrides = [], ?string $createdAt = null): Order
    {
        $order = Order::create([
            'org_id' => $this->org->id,
            'product_id' => $product->id,
            'buyer_email' => 'buyer@example.test',
            'buyer_name' => 'Sam Buyer',
            'amount_minor' => 1200,
            'currency' => 'GBP',
            'status' => OrderStatus::Pending,
            ...$overrides,
        ]);

        if ($createdAt !== null) {
            $order->forceFill(['created_at' => $createdAt])->save();
        }

        return $order;
    }

    public function test_signing_up_on_the_bio_page_is_double_opt_in(): void
    {
        $this->page();

        $this->post(route('bio.subscribe', 'jessbakery'), [
            'email' => 'Fan@Example.test',
            'name' => 'Fan',
        ])->assertRedirect();

        $contact = AudienceContact::query()->withoutOrgScope()->sole();
        $this->assertSame('fan@example.test', $contact->email);
        $this->assertSame(AudienceSource::BioCapture, $contact->source);
        $this->assertNull($contact->consent_at, 'Signing up is not consent on its own.');
        $this->assertFalse($contact->isMarketable());
        Mail::assertQueued(AudienceConfirmMail::class);

        // Following the emailed link is what grants consent, and sends the welcome once.
        $this->get(AudienceLinks::confirm($contact))->assertOk();
        $this->assertNotNull($contact->refresh()->consent_at);
        $this->assertTrue($contact->isMarketable());
        Mail::assertQueued(AudienceMessageMail::class, fn (AudienceMessageMail $mail) => $mail->trigger === AudienceTrigger::Welcome);

        // A second visit confirms nothing again and sends nothing again.
        $this->get(AudienceLinks::confirm($contact))->assertOk();
        Mail::assertQueuedCount(2);
    }

    public function test_a_signup_without_a_visible_block_or_with_the_honeypot_filled_records_nothing(): void
    {
        $page = $this->page(withCaptureBlock: false);

        // Identical response either way: the form must not reveal who is on a list.
        $this->post(route('bio.subscribe', 'jessbakery'), ['email' => 'fan@example.test'])->assertRedirect();

        $page->blocks()->create([
            'type' => BioBlockType::EmailCapture,
            'position' => 0,
            'settings' => [],
            'is_visible' => true,
        ]);
        $this->post(route('bio.subscribe', 'jessbakery'), [
            'email' => 'bot@example.test',
            'website' => 'https://spam.example',
        ])->assertRedirect();

        $this->assertSame(0, AudienceContact::query()->withoutOrgScope()->count());
        Mail::assertNothingQueued();
    }

    public function test_unsubscribing_is_one_click_and_outlives_every_later_capture(): void
    {
        $contact = $this->contact(['consent_at' => now()]);

        $this->get(AudienceLinks::unsubscribe($contact))->assertOk();
        $this->assertNotNull($contact->refresh()->unsubscribed_at);
        $this->assertFalse($contact->isMailable());

        // An import, a purchase or a fresh sign-up must not bring them back.
        app(ImportAudienceContactsAction::class)($this->brand, 'fan@example.test,Fan');
        $this->page();
        $this->post(route('bio.subscribe', 'jessbakery'), ['email' => 'fan@example.test']);

        $contact->refresh();
        $this->assertNotNull($contact->unsubscribed_at);
        $this->assertFalse($contact->isMailable());
        Mail::assertNothingQueued();
    }

    public function test_an_import_never_marks_consent_and_asks_everyone_to_confirm(): void
    {
        $result = app(ImportAudienceContactsAction::class)($this->brand, implode("\n", [
            'email,name',
            'one@example.test,One',
            'two@example.test',
            'not-an-email',
            '',
        ]));

        $this->assertSame(['imported' => 2, 'already_known' => 0, 'skipped' => 3], $result);
        $this->assertSame(2, AudienceContact::query()->withoutOrgScope()->whereNull('consent_at')->count());
        $this->assertSame(0, AudienceContact::query()->withoutOrgScope()->whereNotNull('consent_at')->count());
        Mail::assertQueuedCount(2);

        // Re-importing the same list neither duplicates nor re-mails.
        $again = app(ImportAudienceContactsAction::class)($this->brand, 'one@example.test');
        $this->assertSame(1, $again['already_known']);
        $this->assertSame(2, AudienceContact::query()->withoutOrgScope()->count());
        Mail::assertQueuedCount(2);
    }

    public function test_a_checkout_captures_the_buyer_and_only_the_ticked_box_is_consent(): void
    {
        $this->page();
        PaymentAccount::create([
            'org_id' => $this->org->id,
            'provider' => 'stripe',
            'external_account_id' => 'acct_test',
            'charges_enabled' => true,
            'payouts_enabled' => true,
        ]);
        $product = $this->product();

        $this->post(route('store.checkout', ['handle' => 'jessbakery', 'product' => $product->id]), [
            'buyer_email' => 'buyer@example.test',
            'buyer_name' => 'Sam Buyer',
        ]);

        $contact = AudienceContact::query()->withoutOrgScope()->where('email', 'buyer@example.test')->sole();
        $this->assertSame(AudienceSource::Order, $contact->source);
        $this->assertNull($contact->consent_at, 'Buying something is not consent to marketing.');

        // The same buyer, this time ticking the box.
        $this->post(route('store.checkout', ['handle' => 'jessbakery', 'product' => $product->id]), [
            'buyer_email' => 'buyer@example.test',
            'buyer_name' => 'Sam Buyer',
            'marketing_opt_in' => true,
        ]);

        $this->assertNotNull($contact->refresh()->consent_at);
        $this->assertSame(1, AudienceContact::query()->withoutOrgScope()->count());
    }

    public function test_the_purchase_follow_up_is_queued_for_later_and_sends_once(): void
    {
        $product = $this->product();
        $order = $this->order($product, ['status' => OrderStatus::Paid, 'paid_at' => now()]);

        Bus::fake();
        app(FulfilOrderAction::class)($order);
        Bus::assertDispatched(
            SendAudienceTriggerJob::class,
            fn (SendAudienceTriggerJob $job) => $job->trigger === AudienceTrigger::Purchase
                && $job->reference === (string) $order->id
                && $job->delay !== null,
        );

        // The job's own checks run at delivery, and the send record is what makes it once-only.
        $contact = AudienceContact::query()->withoutOrgScope()->sole();
        $job = new SendAudienceTriggerJob($contact->id, AudienceTrigger::Purchase, (string) $order->id);
        $job->handle(app(SendAudienceTriggerAction::class));
        $job->handle(app(SendAudienceTriggerAction::class));

        // One follow-up, however many times the job runs (the order receipt is the other mailable).
        Mail::assertQueued(AudienceMessageMail::class, 1);
        $this->assertSame(1, DB::table('audience_sends')->count());
    }

    public function test_an_abandoned_checkout_is_nudged_and_a_completed_one_never_is(): void
    {
        $product = $this->product();
        $this->contact(['email' => 'buyer@example.test', 'source' => AudienceSource::Order]);

        $past = now()->subHours(2)->toDateTimeString();
        $abandoned = $this->order($product, [], $past);
        $completed = $this->order($product, [
            'status' => OrderStatus::Fulfilled,
            'paid_at' => now(),
            'fulfilled_at' => now(),
        ], $past);

        Bus::fake();
        app(ExpireStaleOrdersAction::class)();

        Bus::assertDispatched(
            SendAudienceTriggerJob::class,
            fn (SendAudienceTriggerJob $job) => $job->reference === (string) $abandoned->id,
        );
        Bus::assertNotDispatched(
            SendAudienceTriggerJob::class,
            fn (SendAudienceTriggerJob $job) => $job->reference === (string) $completed->id,
        );
    }

    public function test_the_transactional_triggers_need_no_consent_but_an_unsubscribe_stops_them(): void
    {
        $send = app(SendAudienceTriggerAction::class);

        // No consent: the follow-up about their own order still goes, the welcome does not.
        $unconfirmed = $this->contact(['email' => 'one@example.test']);
        $this->assertTrue($send($unconfirmed, AudienceTrigger::Purchase, '1'));
        $this->assertFalse($send($unconfirmed, AudienceTrigger::Welcome));

        // Unsubscribed: nothing at all, transactional included.
        $gone = $this->contact(['email' => 'two@example.test', 'consent_at' => now(), 'unsubscribed_at' => now()]);
        $this->assertFalse($send($gone, AudienceTrigger::Purchase, '2'));
        $this->assertFalse($send($gone, AudienceTrigger::Abandoned, '2'));

        Mail::assertQueuedCount(1);
    }

    public function test_a_plan_without_email_automation_sends_nothing(): void
    {
        $this->org->forceFill(['plan' => 'business_free'])->save();
        $contact = $this->contact(['consent_at' => now()]);

        $this->assertFalse(app(SendAudienceTriggerAction::class)($contact, AudienceTrigger::Welcome));
        Mail::assertNothingQueued();

        // And the human path refuses with the friendly upgrade prompt (a redirect) rather than
        // silently doing nothing, which is why it uses `ensure()` and not `allows()`.
        $this->actingAs($this->owner)->post(route('business.audience.broadcasts.store'), [
            'subject' => 'Hello',
            'body' => 'Something new is out.',
            'segment' => 'all',
        ])->assertRedirect();
        Mail::assertNothingQueued();
    }

    public function test_a_broadcast_goes_to_the_segment_and_never_to_the_unconfirmed(): void
    {
        $product = $this->product();
        $buyer = $this->contact(['email' => 'buyer@example.test', 'consent_at' => now(), 'source' => AudienceSource::Order]);
        $fan = $this->contact(['email' => 'fan@example.test', 'consent_at' => now()]);
        $this->contact(['email' => 'pending@example.test']);
        $this->contact(['email' => 'gone@example.test', 'consent_at' => now(), 'unsubscribed_at' => now()]);

        $this->order($product, ['status' => OrderStatus::Fulfilled, 'paid_at' => now(), 'fulfilled_at' => now()]);

        $this->actingAs($this->owner)->post(route('business.audience.broadcasts.store'), [
            'subject' => 'Everyone',
            'body' => 'Hello all.',
            'segment' => 'all',
        ])->assertRedirect();

        // The two confirmed contacts only: not the unconfirmed one, not the unsubscribed one.
        Mail::assertQueuedCount(2);
        foreach ([$buyer, $fan] as $recipient) {
            Mail::assertQueued(AudienceMessageMail::class, fn (AudienceMessageMail $mail) => $mail->hasTo($recipient->email));
        }

        $this->actingAs($this->owner)->post(route('business.audience.broadcasts.store'), [
            'subject' => 'Buyers',
            'body' => 'Thanks for buying.',
            'segment' => 'product',
            'product_id' => $product->id,
        ])->assertRedirect();

        // One more: the buyer, and only because they also confirmed.
        Mail::assertQueuedCount(3);
        Mail::assertQueued(
            AudienceMessageMail::class,
            fn (AudienceMessageMail $mail) => $mail->broadcast?->subject === 'Buyers' && $mail->hasTo($buyer->email),
        );
    }

    public function test_only_a_person_with_the_brand_update_ability_can_send_or_import(): void
    {
        $designer = User::factory()->for($this->org, 'organization')->role(Role::Designer)->create();
        $this->contact(['consent_at' => now()]);

        $this->actingAs($designer)->post(route('business.audience.broadcasts.store'), [
            'subject' => 'Hello',
            'body' => 'Something new is out.',
            'segment' => 'all',
        ])->assertForbidden();

        $this->actingAs($designer)
            ->post(route('business.audience.import'), ['csv' => 'someone@example.test'])
            ->assertForbidden();

        // A guest cannot reach the send path at all — nothing but an authenticated request sends.
        $this->post(route('business.audience.broadcasts.store'), [
            'subject' => 'Hello',
            'body' => 'Something new is out.',
            'segment' => 'all',
        ])->assertForbidden();

        Mail::assertNothingQueued();
    }

    public function test_the_ai_draft_returns_text_meters_a_credit_and_sends_nothing(): void
    {
        $this->actingAs($this->owner)
            ->postJson(route('business.audience.draft'), ['notes' => 'New preset pack out on Friday.'])
            ->assertOk()
            ->assertJsonStructure(['text']);

        $this->assertSame(1, AiUsageLog::query()->withoutOrgScope()->where('method', 'rewrite')->count());
        Mail::assertNothingQueued();
    }

    public function test_the_page_renders_without_the_plan_and_still_shows_the_list(): void
    {
        $this->org->forceFill(['plan' => 'business_free'])->save();
        $this->contact();

        $this->actingAs($this->owner)
            ->get(route('business.audience.index'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('business/audience')
                ->where('entitled', false)
                ->has('contacts', 1)
                ->where('totals.total', 1)
                ->where('totals.confirmed', 0));
    }

    public function test_another_brands_contact_cannot_be_removed_and_deleting_a_brand_takes_its_list(): void
    {
        $otherOrg = Organization::factory()->create(['type' => OrganizationType::Business, 'plan' => 'creator_pro']);
        $otherBrand = Client::factory()->for($otherOrg, 'organization')->primary()->create();
        $theirs = AudienceContact::create([
            'org_id' => $otherOrg->id,
            'client_id' => $otherBrand->id,
            'email' => 'theirs@example.test',
            'source' => AudienceSource::Import,
        ]);

        $this->actingAs($this->owner)
            ->delete(route('business.audience.contacts.destroy', $theirs))
            ->assertNotFound();
        $this->assertDatabaseHas('audience_contacts', ['id' => $theirs->id]);

        // B-13.2 (export/deletion) doesn't exist yet, so the cascade is what covers a deletion.
        $mine = $this->contact();
        $this->brand->forceDelete();
        $this->assertDatabaseMissing('audience_contacts', ['id' => $mine->id]);
    }
}
