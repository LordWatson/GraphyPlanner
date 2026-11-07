<?php

namespace Tests\Feature\Business;

use App\Actions\Business\BuildMediaKitAction;
use App\Enums\DealStatus;
use App\Enums\InvoiceStatus;
use App\Enums\OrganizationType;
use App\Enums\Platform;
use App\Enums\PostStatus;
use App\Enums\Role;
use App\Mail\InvoiceSentMail;
use App\Models\BrandDeal;
use App\Models\Client;
use App\Models\Invoice;
use App\Models\MediaKit;
use App\Models\Organization;
use App\Models\Post;
use App\Models\SocialAccount;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * Phase CG-5 (CG-5.2 / CG-5.3) — the brand-deals tracker: deliverable progress counted from
 * published posts, org scoping, the plan gate, the media kit's opted-in past partners, and the
 * public enquiry form that drops a draft deal on the right brand.
 */
class BrandDealTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @return array{0: Organization, 1: Client, 2: User}
     */
    private function creatorBrand(string $plan = 'creator_pro'): array
    {
        $org = Organization::factory()->create([
            'type' => OrganizationType::Business,
            'plan' => $plan,
            'default_currency' => 'GBP',
        ]);
        $brand = Client::factory()->for($org, 'organization')->primary()->create(['name' => 'Jess Bakery']);
        $owner = User::factory()->for($org, 'organization')->role(Role::Owner)->create();

        return [$org, $brand, $owner];
    }

    /**
     * @return array<string, mixed>
     */
    private function payload(array $overrides = []): array
    {
        return [
            'partner_name' => 'Yorkshire Tea',
            'partner_handle' => '@yorkshiretea',
            'status' => DealStatus::Agreed->value,
            'fee_minor' => 120000,
            'currency' => 'GBP',
            'due_on' => now()->addWeek()->toDateString(),
            'notes' => 'Two reels, one story set.',
            'show_on_media_kit' => false,
            'deliverables' => [
                ['platform' => Platform::Instagram->value, 'quantity' => 2],
            ],
            ...$overrides,
        ];
    }

    private function deal(Client $brand, array $attributes = []): BrandDeal
    {
        return BrandDeal::create([
            'org_id' => $brand->org_id,
            'client_id' => $brand->id,
            'partner_name' => 'Yorkshire Tea',
            'status' => DealStatus::Agreed,
            'currency' => 'GBP',
            'deliverables' => [['platform' => Platform::Instagram->value, 'quantity' => 2]],
            ...$attributes,
        ]);
    }

    /**
     * A post on the given platform, linked to the deal, in the given status.
     */
    private function postFor(BrandDeal $deal, Client $brand, Platform $platform, PostStatus $status): Post
    {
        $account = SocialAccount::factory()->for($brand)->create([
            'org_id' => $brand->org_id,
            'platform' => $platform,
        ]);

        $post = Post::factory()->for($brand)->create([
            'org_id' => $brand->org_id,
            'status' => $status,
            'brand_deal_id' => $deal->id,
            'is_paid_partnership' => true,
            'partner_name' => $deal->partner_name,
        ]);

        $post->targets()->create(['social_account_id' => $account->id]);

        return $post;
    }

    public function test_the_owner_creates_updates_and_removes_a_deal(): void
    {
        [, $brand, $owner] = $this->creatorBrand();

        $this->actingAs($owner)->post(route('business.deals.store'), $this->payload())->assertRedirect();

        $deal = BrandDeal::query()->where('client_id', $brand->id)->firstOrFail();
        $this->assertSame('Yorkshire Tea', $deal->partner_name);
        $this->assertSame(DealStatus::Agreed, $deal->status);
        $this->assertSame(120000, $deal->fee_minor);
        $this->assertSame([['platform' => 'instagram', 'quantity' => 2]], $deal->deliverables);

        $this->actingAs($owner)
            ->put(route('business.deals.update', $deal), $this->payload([
                'status' => DealStatus::Delivered->value,
                'show_on_media_kit' => true,
            ]))
            ->assertRedirect();

        $deal->refresh();
        $this->assertSame(DealStatus::Delivered, $deal->status);
        $this->assertTrue($deal->show_on_media_kit);

        $this->actingAs($owner)->delete(route('business.deals.destroy', $deal))->assertRedirect();
        $this->assertSame(0, BrandDeal::query()->where('client_id', $brand->id)->count());
    }

    public function test_a_free_workspace_cannot_track_deals(): void
    {
        [, $brand, $owner] = $this->creatorBrand('business_free');

        $this->actingAs($owner)->post(route('business.deals.store'), $this->payload());

        $this->assertSame(0, BrandDeal::query()->where('client_id', $brand->id)->count());
    }

    public function test_a_designer_cannot_write_deals(): void
    {
        [$org, $brand] = $this->creatorBrand();
        $designer = User::factory()->for($org, 'organization')->role(Role::Designer)->create();
        $deal = $this->deal($brand);

        $this->actingAs($designer)->post(route('business.deals.store'), $this->payload())->assertForbidden();
        $this->actingAs($designer)->put(route('business.deals.update', $deal), $this->payload())->assertForbidden();
        $this->actingAs($designer)->delete(route('business.deals.destroy', $deal))->assertForbidden();
    }

    public function test_another_brands_deal_404s(): void
    {
        [, , $owner] = $this->creatorBrand();
        [, $otherBrand] = $this->creatorBrand();
        $theirs = $this->deal($otherBrand);

        $this->actingAs($owner)->put(route('business.deals.update', $theirs), $this->payload())->assertNotFound();
        $this->actingAs($owner)->delete(route('business.deals.destroy', $theirs))->assertNotFound();
    }

    public function test_the_page_only_lists_this_brands_deals(): void
    {
        [, $brand, $owner] = $this->creatorBrand();
        [, $otherBrand] = $this->creatorBrand();
        $this->deal($brand, ['partner_name' => 'Ours']);
        $this->deal($otherBrand, ['partner_name' => 'Theirs']);

        $this->actingAs($owner)
            ->get(route('business.deals.index'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('business/deals')
                ->where('entitled', true)
                ->has('deals', 1)
                ->where('deals.0.partner_name', 'Ours'));
    }

    public function test_deliverable_progress_counts_only_published_linked_posts(): void
    {
        [, $brand, $owner] = $this->creatorBrand();
        $deal = $this->deal($brand);

        // One published, one still a draft, one published on a platform the deal didn't ask for.
        $this->postFor($deal, $brand, Platform::Instagram, PostStatus::Published);
        $this->postFor($deal, $brand, Platform::Instagram, PostStatus::Draft);
        $this->postFor($deal, $brand, Platform::Facebook, PostStatus::Published);

        $this->actingAs($owner)
            ->get(route('business.deals.index'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('deals.0.deliverables.0.platform', 'instagram')
                ->where('deals.0.deliverables.0.quantity', 2)
                ->where('deals.0.deliverables.0.published', 1)
                ->where('deals.0.posts_count', 3));
    }

    public function test_progress_never_reads_past_what_was_agreed(): void
    {
        [, $brand, $owner] = $this->creatorBrand();
        $deal = $this->deal($brand, ['deliverables' => [['platform' => 'instagram', 'quantity' => 1]]]);

        $this->postFor($deal, $brand, Platform::Instagram, PostStatus::Published);
        $this->postFor($deal, $brand, Platform::Instagram, PostStatus::PartiallyPublished);

        $this->actingAs($owner)
            ->get(route('business.deals.index'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page->where('deals.0.deliverables.0.published', 1));
    }

    public function test_a_deal_with_a_fee_and_an_address_is_invoiced_once(): void
    {
        Mail::fake();

        [$org, $brand, $owner] = $this->creatorBrand();
        $deal = $this->deal($brand, ['fee_minor' => 120000, 'contact_email' => 'ads@yorkshiretea.test']);

        $this->actingAs($owner)->post(route('business.deals.invoice', $deal))->assertRedirect();

        $invoice = Invoice::query()->withoutOrgScope()->where('org_id', $org->id)->sole();
        $this->assertSame($invoice->id, $deal->refresh()->invoice_id);
        $this->assertSame('1200.00', $invoice->amount);
        $this->assertSame('GBP', $invoice->currency);
        $this->assertSame(InvoiceStatus::Sent, $invoice->status);
        $this->assertSame('ads@yorkshiretea.test', $invoice->sent_to_email);
        Mail::assertQueued(InvoiceSentMail::class, fn (InvoiceSentMail $mail) => $mail->hasTo('ads@yorkshiretea.test'));

        // Billing is not delivery: the deal's own status is the owner's to set.
        $this->assertSame(DealStatus::Agreed, $deal->status);

        // Step GTM-1.2: a second attempt is refused rather than billing the sponsor twice.
        $this->actingAs($owner)
            ->post(route('business.deals.invoice', $deal))
            ->assertSessionHasErrors('deal');
        $this->assertSame(1, Invoice::query()->withoutOrgScope()->where('org_id', $org->id)->count());
    }

    public function test_a_deal_without_a_fee_or_an_address_cannot_be_invoiced(): void
    {
        Mail::fake();

        [$org, $brand, $owner] = $this->creatorBrand();

        $noFee = $this->deal($brand, ['fee_minor' => null, 'contact_email' => 'ads@yorkshiretea.test']);
        $this->actingAs($owner)
            ->post(route('business.deals.invoice', $noFee))
            ->assertSessionHasErrors('fee_minor');

        $noEmail = $this->deal($brand, ['fee_minor' => 50000]);
        $this->actingAs($owner)
            ->post(route('business.deals.invoice', $noEmail))
            ->assertSessionHasErrors('contact_email');

        // A refused issue leaves nothing behind — no invoice, no link on the deal.
        $this->assertSame(0, Invoice::query()->withoutOrgScope()->where('org_id', $org->id)->count());
        $this->assertNull($noFee->refresh()->invoice_id);
        $this->assertNull($noEmail->refresh()->invoice_id);
        Mail::assertNothingQueued();
    }

    public function test_invoicing_a_deal_needs_the_plan_and_another_brands_deal_404s(): void
    {
        Mail::fake();

        [, $freeBrand, $freeOwner] = $this->creatorBrand('business_free');
        $theirs = $this->deal($freeBrand, ['fee_minor' => 50000, 'contact_email' => 'ads@yorkshiretea.test']);

        $this->actingAs($freeOwner)->post(route('business.deals.invoice', $theirs));
        $this->assertNull($theirs->refresh()->invoice_id);

        // Another brand's deal 404s before the plan gate or the Action is ever reached.
        [, $otherBrand] = $this->creatorBrand();
        $mine = $this->deal($otherBrand, ['fee_minor' => 50000, 'contact_email' => 'ads@yorkshiretea.test']);

        $this->actingAs($freeOwner)
            ->post(route('business.deals.invoice', $mine))
            ->assertNotFound();

        $this->assertSame(0, Invoice::query()->withoutOrgScope()->count());
        $this->assertNull($mine->refresh()->invoice_id);
    }

    public function test_the_media_kit_names_only_opted_in_deals_that_happened(): void
    {
        [, $brand] = $this->creatorBrand();

        $this->deal($brand, ['partner_name' => 'Shown', 'status' => DealStatus::Paid, 'show_on_media_kit' => true, 'starts_on' => '2026-03-01']);
        $this->deal($brand, ['partner_name' => 'NotOptedIn', 'status' => DealStatus::Paid, 'show_on_media_kit' => false]);
        $this->deal($brand, ['partner_name' => 'StillTalking', 'status' => DealStatus::Agreed, 'show_on_media_kit' => true]);

        $build = app(BuildMediaKitAction::class);
        $kit = $build($build->kitFor($brand), $brand);

        $this->assertSame(['Shown'], array_column($kit['partners'], 'name'));
        $this->assertSame(2026, $kit['partners'][0]['year']);
    }

    public function test_the_owner_saves_a_rate_card_and_the_enquiry_switch(): void
    {
        [, $brand, $owner] = $this->creatorBrand();

        $this->actingAs($owner)->put(route('business.media-kit.update'), [
            'bio' => 'Sourdough in Leeds.',
            'enquiries_enabled' => true,
            'rates' => [['label' => 'Instagram Reel', 'amount_minor' => 45000]],
        ])->assertSessionHasNoErrors();

        $kit = MediaKit::query()->where('client_id', $brand->id)->firstOrFail();
        $this->assertTrue($kit->enquiries_enabled);
        $this->assertSame([['label' => 'Instagram Reel', 'amount_minor' => 45000]], $kit->rates);

        // A rate with no label (or no money) is a mistake, not something to publish as "— ".
        $this->actingAs($owner)->put(route('business.media-kit.update'), [
            'rates' => [['label' => '', 'amount_minor' => 1000]],
        ])->assertSessionHasErrors('rates.0.label');

        $this->assertSame([['label' => 'Instagram Reel', 'amount_minor' => 45000]], $kit->refresh()->rates);
    }

    public function test_an_enquiry_creates_a_draft_deal_on_the_right_brand(): void
    {
        [, $brand] = $this->creatorBrand();
        [, $otherBrand] = $this->creatorBrand();
        $this->deal($otherBrand);

        $kit = MediaKit::create([
            'org_id' => $brand->org_id,
            'client_id' => $brand->id,
            'enquiries_enabled' => true,
            'share_token' => 'kit-token-1',
            'shared_at' => now(),
        ]);

        $this->get(route('media-kit.show', $kit->share_token))
            ->assertOk()
            ->assertInertia(fn ($page) => $page->where('enquiryUrl', route('media-kit.enquire', $kit->share_token)));

        $this->post(route('media-kit.enquire', $kit->share_token), [
            'name' => 'Sam Buyer',
            'email' => 'Sam@Sponsor.test',
            'company' => 'Yorkshire Tea',
            'message' => 'Two reels in May?',
        ])->assertRedirect();

        $deal = BrandDeal::query()->where('client_id', $brand->id)->firstOrFail();
        $this->assertSame(DealStatus::Enquiry, $deal->status);
        $this->assertSame('Yorkshire Tea', $deal->partner_name);
        $this->assertSame('sam@sponsor.test', $deal->contact_email);
        $this->assertStringContainsString('Sam Buyer', (string) $deal->notes);
        $this->assertStringContainsString('Two reels in May?', (string) $deal->notes);
    }

    public function test_an_enquiry_is_ignored_when_the_form_is_off_or_the_honeypot_is_filled(): void
    {
        [, $brand] = $this->creatorBrand();

        $kit = MediaKit::create([
            'org_id' => $brand->org_id,
            'client_id' => $brand->id,
            'enquiries_enabled' => false,
            'share_token' => 'kit-token-2',
            'shared_at' => now(),
        ]);

        $enquiry = ['name' => 'Bot', 'email' => 'bot@spam.test'];

        // The form is off: same answer, no deal.
        $this->post(route('media-kit.enquire', $kit->share_token), $enquiry)
            ->assertRedirect()
            ->assertSessionHas('toast.type', 'success');
        $this->assertSame(0, BrandDeal::query()->where('client_id', $brand->id)->count());

        // On, but the honeypot is filled: still nothing.
        $kit->update(['enquiries_enabled' => true]);
        $this->post(route('media-kit.enquire', $kit->share_token), [...$enquiry, 'website' => 'http://spam.test'])
            ->assertRedirect();
        $this->assertSame(0, BrandDeal::query()->where('client_id', $brand->id)->count());

        // A revoked link takes the form with it.
        $kit->update(['share_token' => null]);
        $this->post(route('media-kit.enquire', 'kit-token-2'), $enquiry)->assertNotFound();
    }

    public function test_the_enquiry_form_is_throttled(): void
    {
        [, $brand] = $this->creatorBrand();

        $kit = MediaKit::create([
            'org_id' => $brand->org_id,
            'client_id' => $brand->id,
            'enquiries_enabled' => true,
            'share_token' => 'kit-token-3',
            'shared_at' => now(),
        ]);

        $enquiry = ['name' => 'Sam', 'email' => 'sam@sponsor.test'];

        for ($attempt = 0; $attempt < 6; $attempt++) {
            $this->post(route('media-kit.enquire', $kit->share_token), $enquiry)->assertRedirect();
        }

        $this->post(route('media-kit.enquire', $kit->share_token), $enquiry)->assertStatus(429);
    }

    public function test_a_deal_removed_leaves_its_posts_alone(): void
    {
        [, $brand, $owner] = $this->creatorBrand();
        $deal = $this->deal($brand);
        $post = $this->postFor($deal, $brand, Platform::Instagram, PostStatus::Published);

        $this->actingAs($owner)->delete(route('business.deals.destroy', $deal))->assertRedirect();

        $post->refresh();
        $this->assertNull($post->brand_deal_id);
        $this->assertTrue($post->is_paid_partnership, 'The disclosure is the post\'s own.');
        $this->assertSame('Yorkshire Tea', $post->partner_name);
    }
}
