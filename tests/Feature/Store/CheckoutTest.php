<?php

namespace Tests\Feature\Store;

use App\Actions\Store\FulfilOrderAction;
use App\Actions\Store\MarkOrderPaidAction;
use App\Enums\AssetSource;
use App\Enums\OrderStatus;
use App\Enums\OrganizationType;
use App\Enums\ProductStatus;
use App\Enums\ProductType;
use App\Mail\OrderReceiptMail;
use App\Models\Asset;
use App\Models\BioPage;
use App\Models\Booking;
use App\Models\Client;
use App\Models\Coupon;
use App\Models\Order;
use App\Models\Organization;
use App\Models\PaymentAccount;
use App\Models\Product;
use App\Services\Store\BookingSlotService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Phase CG-4 — the buyer's side: the public product page, the pending order a checkout creates, the
 * coupon rules, how a payment event is applied (once), and what a download link will and will not do.
 */
class CheckoutTest extends TestCase
{
    use RefreshDatabase;

    private Organization $org;

    private Client $brand;

    protected function setUp(): void
    {
        parent::setUp();

        Mail::fake();

        $this->org = Organization::factory()->create([
            'type' => OrganizationType::Business,
            'plan' => 'creator_pro',
            'default_timezone' => 'Europe/London',
        ]);
        $this->brand = Client::factory()->for($this->org, 'organization')->primary()->create(['name' => 'Jess Bakery']);

        BioPage::create([
            'org_id' => $this->org->id,
            'client_id' => $this->brand->id,
            'handle' => 'jessbakery',
            'theme' => 'cream',
            'published_at' => now(),
        ]);

        PaymentAccount::create([
            'org_id' => $this->org->id,
            'provider' => 'stripe',
            'external_account_id' => 'acct_test',
            'charges_enabled' => true,
            'payouts_enabled' => true,
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

    /**
     * @return array<string, mixed>
     */
    private function buyer(array $overrides = []): array
    {
        return ['buyer_email' => 'Buyer@Example.test', 'buyer_name' => 'Sam', ...$overrides];
    }

    public function test_the_product_page_renders_for_a_live_product(): void
    {
        $product = $this->product();

        $this->get("/@jessbakery/p/{$product->id}")
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('public/product')
                ->where('product.title', 'Sourdough guide')
                ->where('product.price_minor', 1200));
    }

    public function test_a_draft_product_an_unpublished_page_and_another_brand_all_404(): void
    {
        $draft = $this->product(['status' => ProductStatus::Draft]);
        $live = $this->product();

        $this->get("/@jessbakery/p/{$draft->id}")->assertNotFound();

        $otherOrg = Organization::factory()->create(['type' => OrganizationType::Business, 'plan' => 'creator_pro']);
        $otherBrand = Client::factory()->for($otherOrg, 'organization')->primary()->create();
        $theirs = Product::create([
            'org_id' => $otherOrg->id,
            'client_id' => $otherBrand->id,
            'type' => ProductType::Download,
            'title' => 'Theirs',
            'price_minor' => 500,
            'currency' => 'GBP',
            'status' => ProductStatus::Live,
        ]);

        $this->get("/@jessbakery/p/{$theirs->id}")->assertNotFound();

        BioPage::query()->where('client_id', $this->brand->id)->update(['published_at' => null]);
        $this->get("/@jessbakery/p/{$live->id}")->assertNotFound();
    }

    public function test_a_checkout_creates_one_pending_order_with_a_normalised_buyer(): void
    {
        $product = $this->product();

        $this->post("/@jessbakery/p/{$product->id}/checkout", $this->buyer())->assertRedirect();

        $order = Order::query()->withoutOrgScope()->firstOrFail();
        $this->assertSame(OrderStatus::Pending, $order->status);
        $this->assertSame('buyer@example.test', $order->buyer_email);
        $this->assertSame(1200, $order->amount_minor);
        $this->assertSame('GBP', $order->currency);
        $this->assertNotNull($order->external_session_id);
    }

    public function test_a_tip_takes_the_buyers_amount_but_never_below_the_minimum(): void
    {
        $product = $this->product([
            'type' => ProductType::Tip,
            'price_minor' => 300,
            'settings' => ['min_minor' => 300],
        ]);

        $this->post("/@jessbakery/p/{$product->id}/checkout", $this->buyer(['amount_minor' => 1000]));
        $this->assertSame(1000, Order::query()->withoutOrgScope()->latest('id')->value('amount_minor'));

        $this->post("/@jessbakery/p/{$product->id}/checkout", $this->buyer(['amount_minor' => 100]));
        $this->assertSame(300, Order::query()->withoutOrgScope()->latest('id')->value('amount_minor'));
    }

    public function test_a_shop_that_cannot_take_charges_refuses_the_checkout(): void
    {
        PaymentAccount::query()->withoutOrgScope()->update(['charges_enabled' => false]);
        $product = $this->product();

        $this->post("/@jessbakery/p/{$product->id}/checkout", $this->buyer())
            ->assertSessionHas('toast.type', 'error');

        $this->assertSame(0, Order::query()->withoutOrgScope()->count());
    }

    public function test_a_coupon_discounts_once_and_respects_expiry_scope_and_use_limit(): void
    {
        $product = $this->product();
        $other = $this->product(['title' => 'Something else']);

        Coupon::create(['org_id' => $this->org->id, 'code' => 'HALF', 'percent_off' => 50, 'max_redemptions' => 1]);
        Coupon::create(['org_id' => $this->org->id, 'code' => 'GONE', 'percent_off' => 50, 'expires_at' => now()->subDay()]);
        Coupon::create(['org_id' => $this->org->id, 'code' => 'OTHERONLY', 'percent_off' => 50, 'product_id' => $other->id]);

        $this->post("/@jessbakery/p/{$product->id}/checkout", $this->buyer(['coupon_code' => 'half']));
        $this->assertSame(600, Order::query()->withoutOrgScope()->latest('id')->value('amount_minor'));

        // The one use is spent.
        $this->post("/@jessbakery/p/{$product->id}/checkout", $this->buyer(['coupon_code' => 'HALF']))
            ->assertSessionHas('toast.type', 'error');

        $this->post("/@jessbakery/p/{$product->id}/checkout", $this->buyer(['coupon_code' => 'GONE']))
            ->assertSessionHas('toast.type', 'error');

        $this->post("/@jessbakery/p/{$product->id}/checkout", $this->buyer(['coupon_code' => 'OTHERONLY']))
            ->assertSessionHas('toast.type', 'error');

        $this->assertSame(1, Order::query()->withoutOrgScope()->count());
    }

    public function test_a_payment_event_marks_the_order_paid_once_and_only_when_the_amount_matches(): void
    {
        $product = $this->product(['type' => ProductType::Tip, 'price_minor' => 500, 'settings' => ['min_minor' => 500]]);
        $this->post("/@jessbakery/p/{$product->id}/checkout", $this->buyer());
        $order = Order::query()->withoutOrgScope()->firstOrFail();

        $markPaid = app(MarkOrderPaidAction::class);

        // Wrong currency: the order stays pending and the mismatch is recorded.
        $markPaid($order, ['amount_total' => 500, 'currency' => 'usd', 'payment_intent' => 'pi_1']);
        $this->assertSame(OrderStatus::Pending, $order->refresh()->status);
        $this->assertSame(1, $order->events()->where('type', 'payment.mismatch')->count());

        // Wrong amount: same.
        $markPaid($order, ['amount_total' => 100, 'currency' => 'gbp', 'payment_intent' => 'pi_1']);
        $this->assertSame(OrderStatus::Pending, $order->refresh()->status);

        $session = ['amount_total' => 500, 'currency' => 'gbp', 'payment_intent' => 'pi_1'];
        $markPaid($order, $session);
        $this->assertSame(OrderStatus::Fulfilled, $order->refresh()->status);
        $this->assertSame('pi_1', $order->external_payment_id);

        // A replayed delivery changes nothing and sends nothing twice.
        $markPaid($order->refresh(), $session);
        $this->assertSame(1, $order->events()->where('type', 'status.paid')->count());
    }

    public function test_a_download_link_expires_is_limited_and_dies_with_a_refund(): void
    {
        Storage::fake(config('assets.disk', 'public'));

        $asset = Asset::factory()->for($this->brand)->create([
            'org_id' => $this->org->id,
            // Step GTM-1.1: a sellable file is an upload (so it was virus-scanned) on a private disk.
            'source' => AssetSource::Upload,
            'disk' => config('assets.disk', 'public'),
            'path' => 'downloads/guide.pdf',
            'original_filename' => 'guide.pdf',
            'mime_type' => 'application/pdf',
        ]);
        Storage::disk(config('assets.disk', 'public'))->put('downloads/guide.pdf', 'PDF BYTES');

        $product = $this->product(['download_asset_id' => $asset->id, 'settings' => ['download_limit' => 2]]);
        $order = Order::create([
            'org_id' => $this->org->id,
            'product_id' => $product->id,
            'buyer_email' => 'buyer@example.test',
            'amount_minor' => 1200,
            'currency' => 'GBP',
            'status' => OrderStatus::Fulfilled,
            'paid_at' => now(),
            'fulfilled_at' => now(),
        ]);

        $url = app(FulfilOrderAction::class)->downloadUrl($order);

        $this->get($url)->assertOk()->assertHeader('content-type', 'application/pdf');
        $this->get($url)->assertOk();
        // The third hit is past the product's limit.
        $this->get($url)->assertStatus(410);

        // An unsigned URL never works at all.
        $this->get(route('store.download', $order))->assertForbidden();

        // And a refund kills a link that is still within its expiry and limit.
        $fresh = Order::create([
            'org_id' => $this->org->id,
            'product_id' => $product->id,
            'buyer_email' => 'buyer@example.test',
            'amount_minor' => 1200,
            'currency' => 'GBP',
            'status' => OrderStatus::Refunded,
            'paid_at' => now(),
            'refunded_at' => now(),
        ]);

        $this->get(app(FulfilOrderAction::class)->downloadUrl($fresh))->assertStatus(410);
    }

    public function test_a_download_is_refused_when_its_file_is_public_or_was_never_scanned(): void
    {
        Storage::fake('local');
        Storage::fake('public');

        // Step GTM-1.1 — two files we will not charge for: one anyone could fetch without paying,
        // one imported from another service that never went through the virus scanner.
        foreach ([
            ['disk' => 'public', 'source' => AssetSource::Upload],
            ['disk' => 'local', 'source' => AssetSource::Dropbox],
        ] as $attributes) {
            $asset = Asset::factory()->for($this->brand)->create([
                'org_id' => $this->org->id,
                'path' => 'downloads/guide.pdf',
                'original_filename' => 'guide.pdf',
                'mime_type' => 'application/pdf',
                ...$attributes,
            ]);
            Storage::disk($attributes['disk'])->put('downloads/guide.pdf', 'PDF BYTES');

            $product = $this->product(['download_asset_id' => $asset->id]);
            $order = Order::create([
                'org_id' => $this->org->id,
                'product_id' => $product->id,
                'buyer_email' => 'buyer@example.test',
                'amount_minor' => 1200,
                'currency' => 'GBP',
                'status' => OrderStatus::Fulfilled,
                'paid_at' => now(),
                'fulfilled_at' => now(),
            ]);

            $this->get(app(FulfilOrderAction::class)->downloadUrl($order))->assertStatus(410);
            $this->assertSame(0, $order->refresh()->download_count);
        }
    }

    public function test_a_booking_checkout_claims_the_slot_and_the_next_buyer_cannot_take_it(): void
    {
        $product = $this->product([
            'type' => ProductType::Booking,
            'settings' => ['availability' => ['start' => '09:00', 'end' => '12:00', 'slot_minutes' => 60, 'lead_hours' => 0]],
        ]);

        $slots = app(BookingSlotService::class)->slots($product);
        $this->assertNotEmpty($slots);
        $slot = $slots[0]['starts_at'];

        $this->post("/@jessbakery/p/{$product->id}/checkout", $this->buyer(['slot' => $slot]))->assertRedirect();
        $this->assertSame(1, Booking::query()->where('product_id', $product->id)->count());

        $this->post("/@jessbakery/p/{$product->id}/checkout", $this->buyer(['slot' => $slot]))
            ->assertSessionHas('toast.type', 'error');

        $this->assertSame(1, Booking::query()->where('product_id', $product->id)->count());
    }

    public function test_a_product_block_on_the_bio_page_links_to_the_product_and_only_while_it_is_live(): void
    {
        $product = $this->product();
        $page = BioPage::query()->where('client_id', $this->brand->id)->firstOrFail();
        $block = $page->blocks()->create([
            'type' => 'product',
            'position' => 0,
            'settings' => ['product_id' => $product->id],
            'is_visible' => true,
        ]);

        $this->get('/@jessbakery')
            ->assertOk()
            ->assertInertia(fn ($bio) => $bio->where('page.blocks.0.label', 'Sourdough guide'));

        // The counted click-through lands on the product page.
        $this->get("/@jessbakery/go/{$block->id}")
            ->assertRedirect("http://alima.test/@jessbakery/p/{$product->id}");

        $product->update(['status' => ProductStatus::Archived]);

        $this->get('/@jessbakery')
            ->assertOk()
            ->assertInertia(fn ($bio) => $bio->where('page.blocks', []));
        $this->get("/@jessbakery/go/{$block->id}")->assertNotFound();
    }

    public function test_the_resend_page_only_emails_the_address_on_the_order(): void
    {
        $asset = Asset::factory()->for($this->brand)->create(['org_id' => $this->org->id]);
        $product = $this->product(['download_asset_id' => $asset->id]);
        $order = Order::create([
            'org_id' => $this->org->id,
            'product_id' => $product->id,
            'buyer_email' => 'buyer@example.test',
            'amount_minor' => 1200,
            'currency' => 'GBP',
            'status' => OrderStatus::Fulfilled,
            'paid_at' => now(),
            'fulfilled_at' => now(),
        ]);

        $this->post("/@jessbakery/thanks/{$order->id}/resend", ['buyer_email' => 'someone@else.test'])
            ->assertRedirect();
        Mail::assertNothingQueued();

        $this->post("/@jessbakery/thanks/{$order->id}/resend", ['buyer_email' => 'BUYER@example.test'])
            ->assertRedirect();
        Mail::assertQueued(OrderReceiptMail::class);
    }
}
