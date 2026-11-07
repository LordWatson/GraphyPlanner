<?php

namespace Tests\Feature\Store;

use App\Actions\Store\TransitionOrderStatusAction;
use App\Enums\AssetSource;
use App\Enums\OrderStatus;
use App\Enums\OrganizationType;
use App\Enums\PostStatus;
use App\Enums\ProductStatus;
use App\Enums\ProductType;
use App\Enums\Role;
use App\Mail\OrderReceiptMail;
use App\Models\Asset;
use App\Models\Campaign;
use App\Models\Client;
use App\Models\Coupon;
use App\Models\Order;
use App\Models\Organization;
use App\Models\PaymentAccount;
use App\Models\Post;
use App\Models\Product;
use App\Models\User;
use DomainException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * Phase CG-4 — the creator's side of the store: products behind the plan gate, who may move money,
 * refunds (and what they revoke), answering a paid question, and the AI launch campaign.
 */
class CreatorStoreTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @return array{0: Organization, 1: Client, 2: User}
     */
    private function creatorBrand(string $plan = 'creator_pro'): array
    {
        $org = Organization::factory()->create(['type' => OrganizationType::Business, 'plan' => $plan]);
        $brand = Client::factory()->for($org, 'organization')->primary()->create(['name' => 'Jess Bakery']);
        $owner = User::factory()->for($org, 'organization')->role(Role::Owner)->create();

        return [$org, $brand, $owner];
    }

    private function connect(Organization $org, bool $enabled = true): PaymentAccount
    {
        return PaymentAccount::create([
            'org_id' => $org->id,
            'provider' => 'stripe',
            'external_account_id' => 'acct_'.$org->id,
            'charges_enabled' => $enabled,
            'payouts_enabled' => $enabled,
        ]);
    }

    private function product(Client $brand, array $overrides = []): Product
    {
        return Product::create([
            'org_id' => $brand->org_id,
            'client_id' => $brand->id,
            'type' => ProductType::Download,
            'title' => 'Sourdough guide',
            'price_minor' => 1200,
            'currency' => 'GBP',
            'status' => ProductStatus::Live,
            ...$overrides,
        ]);
    }

    private function order(Product $product, array $overrides = []): Order
    {
        return Order::create([
            'org_id' => $product->org_id,
            'product_id' => $product->id,
            'buyer_email' => 'buyer@example.test',
            'amount_minor' => $product->price_minor,
            'currency' => $product->currency,
            'status' => OrderStatus::Paid,
            'paid_at' => now(),
            'external_payment_id' => 'pi_test',
            ...$overrides,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function payload(array $overrides = []): array
    {
        return [
            'type' => 'tip',
            'title' => 'Buy me a coffee',
            'description' => 'Keeps the oven on.',
            'price_minor' => 300,
            'currency' => 'GBP',
            'status' => 'live',
            'settings' => ['min_minor' => 200],
            ...$overrides,
        ];
    }

    public function test_the_owner_creates_and_archives_a_product(): void
    {
        [, $brand, $owner] = $this->creatorBrand();

        $this->actingAs($owner)
            ->post(route('business.store.products.store'), $this->payload())
            ->assertRedirect();

        $product = Product::query()->where('client_id', $brand->id)->firstOrFail();
        $this->assertSame(ProductType::Tip, $product->type);
        $this->assertSame(300, $product->price_minor);
        $this->assertSame(['min_minor' => 200], $product->settings);

        $this->actingAs($owner)
            ->delete(route('business.store.products.destroy', $product))
            ->assertRedirect();

        $this->assertSame(ProductStatus::Archived, $product->refresh()->status);
    }

    public function test_a_free_workspace_cannot_create_a_product(): void
    {
        [, $brand, $owner] = $this->creatorBrand('business_free');

        $this->actingAs($owner)->post(route('business.store.products.store'), $this->payload());

        $this->assertSame(0, Product::query()->where('client_id', $brand->id)->count());
    }

    public function test_a_designer_cannot_touch_products_orders_or_refunds(): void
    {
        [$org, $brand] = $this->creatorBrand();
        $designer = User::factory()->for($org, 'organization')->role(Role::Designer)->create();
        $product = $this->product($brand);
        $order = $this->order($product);

        $this->actingAs($designer)->post(route('business.store.products.store'), $this->payload())->assertForbidden();
        $this->actingAs($designer)->post(route('business.store.orders.refund', $order))->assertForbidden();
        $this->actingAs($designer)->post(route('business.store.orders.answer', $order), ['answer' => 'hi'])->assertForbidden();
    }

    public function test_a_product_from_another_brand_404s(): void
    {
        [, , $owner] = $this->creatorBrand();
        [, $otherBrand] = $this->creatorBrand();
        $theirs = $this->product($otherBrand);

        $this->actingAs($owner)
            ->put(route('business.store.products.update', $theirs), $this->payload())
            ->assertNotFound();
    }

    public function test_a_live_download_needs_a_file(): void
    {
        [, , $owner] = $this->creatorBrand();

        $this->actingAs($owner)
            ->post(route('business.store.products.store'), $this->payload(['type' => 'download', 'settings' => []]))
            ->assertSessionHasErrors('download_asset_id');

        // A draft may be half-finished.
        $this->actingAs($owner)
            ->post(route('business.store.products.store'), $this->payload(['type' => 'download', 'status' => 'draft', 'settings' => []]))
            ->assertSessionHasNoErrors();
    }

    public function test_a_live_download_refuses_a_public_or_unscanned_file(): void
    {
        [, $brand, $owner] = $this->creatorBrand();

        $file = fn (AssetSource $source, string $disk): Asset => Asset::factory()->for($brand)->create([
            'org_id' => $brand->org_id,
            'source' => $source,
            'disk' => $disk,
            'path' => 'downloads/'.fake()->uuid().'.pdf',
        ]);

        // Step GTM-1.1 — the creator is told before a buyer finds out: a file on the public disk is
        // readable without paying, and an imported file was never virus-scanned.
        foreach ([$file(AssetSource::Upload, 'public'), $file(AssetSource::Dropbox, 'local')] as $refused) {
            $this->actingAs($owner)
                ->post(route('business.store.products.store'), $this->payload([
                    'type' => 'download', 'download_asset_id' => $refused->id, 'settings' => [],
                ]))
                ->assertSessionHasErrors('download_asset_id');
        }

        $this->actingAs($owner)
            ->post(route('business.store.products.store'), $this->payload([
                'type' => 'download', 'download_asset_id' => $file(AssetSource::Upload, 'local')->id, 'settings' => [],
            ]))
            ->assertSessionHasNoErrors();
    }

    public function test_a_file_belonging_to_another_brand_is_dropped(): void
    {
        [, $brand, $owner] = $this->creatorBrand();
        [, $otherBrand] = $this->creatorBrand();

        $theirAsset = Asset::factory()->for($otherBrand)->create(['org_id' => $otherBrand->org_id]);

        $this->actingAs($owner)->post(route('business.store.products.store'), $this->payload([
            'type' => 'download',
            'status' => 'draft',
            'download_asset_id' => $theirAsset->id,
            'settings' => [],
        ]));

        $this->assertNull(Product::query()->where('client_id', $brand->id)->value('download_asset_id'));
    }

    public function test_a_refund_revokes_the_download_and_frees_the_slot(): void
    {
        [$org, $brand, $owner] = $this->creatorBrand();
        $this->connect($org);
        $product = $this->product($brand);
        $order = $this->order($product, ['status' => OrderStatus::Fulfilled, 'fulfilled_at' => now()]);

        $this->assertTrue($order->canDownload());

        $this->actingAs($owner)->post(route('business.store.orders.refund', $order))->assertRedirect();

        $order->refresh();
        $this->assertSame(OrderStatus::Refunded, $order->status);
        $this->assertFalse($order->canDownload());
        $this->assertNotNull($order->refunded_at);

        // And it cannot be refunded twice.
        $this->actingAs($owner)->post(route('business.store.orders.refund', $order));
        $this->assertSame(1, $order->events()->where('type', 'status.refunded')->count());
    }

    public function test_answering_a_paid_question_captures_and_emails(): void
    {
        Mail::fake();

        [$org, $brand, $owner] = $this->creatorBrand();
        $this->connect($org);
        $product = $this->product($brand, ['type' => ProductType::Qa, 'settings' => ['answer_window_hours' => 48]]);
        $order = $this->order($product, ['message' => 'Why does my loaf sink?', 'authorised_until' => now()->addDay()]);

        $this->actingAs($owner)
            ->post(route('business.store.orders.answer', $order), ['answer' => 'Too much water.'])
            ->assertRedirect();

        $order->refresh();
        $this->assertSame(OrderStatus::Fulfilled, $order->status);
        $this->assertSame('Too much water.', $order->answer);
        Mail::assertQueued(OrderReceiptMail::class);
    }

    public function test_an_expired_question_cannot_be_answered(): void
    {
        [$org, $brand, $owner] = $this->creatorBrand();
        $this->connect($org);
        $product = $this->product($brand, ['type' => ProductType::Qa]);
        $order = $this->order($product, ['authorised_until' => now()->subHour()]);

        $this->actingAs($owner)
            ->post(route('business.store.orders.answer', $order), ['answer' => 'Late.'])
            ->assertSessionHas('toast.type', 'error');

        $this->assertSame(OrderStatus::Paid, $order->refresh()->status);
        $this->assertNull($order->answer);
    }

    public function test_only_a_paid_question_can_be_answered(): void
    {
        [$org, $brand, $owner] = $this->creatorBrand();
        $this->connect($org);
        $order = $this->order($this->product($brand));

        $this->actingAs($owner)
            ->post(route('business.store.orders.answer', $order), ['answer' => 'Nope.'])
            ->assertSessionHas('toast.type', 'error');
    }

    public function test_the_expiry_command_cancels_abandoned_checkouts_and_releases_held_questions(): void
    {
        [$org, $brand] = $this->creatorBrand();
        $this->connect($org);

        $abandoned = $this->order($this->product($brand), ['status' => OrderStatus::Pending, 'paid_at' => null]);
        // Older than the one-hour window a pending checkout may hold its slot for.
        $abandoned->forceFill(['created_at' => now()->subHours(3)])->save();
        $unanswered = $this->order($this->product($brand, ['type' => ProductType::Qa]), [
            'authorised_until' => now()->subMinute(),
        ]);
        $fresh = $this->order($this->product($brand), ['status' => OrderStatus::Pending, 'paid_at' => null]);

        $this->artisan('store:expire-orders')->assertSuccessful();

        $this->assertSame(OrderStatus::Cancelled, $abandoned->refresh()->status);
        $this->assertSame(OrderStatus::Cancelled, $unanswered->refresh()->status);
        $this->assertSame(OrderStatus::Pending, $fresh->refresh()->status);
    }

    /**
     * A redemption is claimed when checkout *starts*, so an abandoned checkout has to hand it
     * back. Without this a `max_redemptions = 1` code was killed by one buyer who changed their
     * mind, and nobody could use it again — the sweep released the booking slot but not the code.
     */
    public function test_the_expiry_command_hands_back_the_coupon_an_abandoned_checkout_claimed(): void
    {
        [$org, $brand] = $this->creatorBrand();
        $this->connect($org);

        $coupon = Coupon::create([
            'org_id' => $org->id,
            'code' => 'LAUNCH50',
            'percent_off' => 50,
            'max_redemptions' => 1,
            'redemptions' => 1,
        ]);

        $abandoned = $this->order($this->product($brand), [
            'status' => OrderStatus::Pending,
            'paid_at' => null,
            'coupon_id' => $coupon->id,
        ]);
        $abandoned->forceFill(['created_at' => now()->subHours(3)])->save();

        $this->assertTrue($coupon->isExhausted());

        $this->artisan('store:expire-orders')->assertSuccessful();

        $this->assertSame(OrderStatus::Cancelled, $abandoned->refresh()->status);
        $this->assertSame(0, $coupon->refresh()->redemptions);
        $this->assertFalse($coupon->isExhausted());
    }

    /**
     * Same claim, released the other way. The release is floored at zero, so a refund arriving
     * from both `/store` and the Stripe webhook cannot hand out a free use.
     */
    public function test_a_refund_hands_back_the_coupon_and_never_below_zero(): void
    {
        [$org, $brand, $owner] = $this->creatorBrand();
        $this->connect($org);

        $coupon = Coupon::create([
            'org_id' => $org->id,
            'code' => 'LAUNCH50',
            'percent_off' => 50,
            'max_redemptions' => 1,
            'redemptions' => 1,
        ]);

        $order = $this->order($this->product($brand), [
            'status' => OrderStatus::Fulfilled,
            'fulfilled_at' => now(),
            'coupon_id' => $coupon->id,
        ]);

        $this->actingAs($owner)->post(route('business.store.orders.refund', $order))->assertRedirect();
        $this->assertSame(0, $coupon->refresh()->redemptions);

        // A second release (the webhook mirroring the same refund) must not go negative.
        $order->refresh()->coupon?->releaseRedemption();
        $this->assertSame(0, $coupon->refresh()->redemptions);
    }

    public function test_an_illegal_status_change_is_refused(): void
    {
        [, $brand] = $this->creatorBrand();
        $order = $this->order($this->product($brand), ['status' => OrderStatus::Refunded, 'refunded_at' => now()]);

        $this->expectException(DomainException::class);

        app(TransitionOrderStatusAction::class)($order, OrderStatus::Paid);
    }

    public function test_a_launch_campaign_is_drafted_and_never_scheduled(): void
    {
        [, $brand, $owner] = $this->creatorBrand();
        $product = $this->product($brand, ['title' => 'Sourdough guide']);

        $this->actingAs($owner)
            ->post(route('business.store.launch', $product))
            ->assertRedirect(route('business.collections.index'));

        $campaign = Campaign::query()->where('client_id', $brand->id)->firstOrFail();
        $this->assertSame('Launch: Sourdough guide', $campaign->name);

        $posts = Post::query()->where('campaign_id', $campaign->id)->get();
        $this->assertCount(4, $posts);
        $this->assertTrue($posts->every(fn (Post $post) => $post->status === PostStatus::Draft));
        $this->assertTrue($posts->every(fn (Post $post) => $post->master_caption !== null && $post->master_caption !== ''));
    }

    public function test_the_store_page_renders_for_a_free_workspace_without_the_gate(): void
    {
        [, , $owner] = $this->creatorBrand('business_free');

        $this->actingAs($owner)
            ->get(route('business.store.index'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('business/store')
                ->where('entitled', false)
                ->where('account', null));
    }
}
