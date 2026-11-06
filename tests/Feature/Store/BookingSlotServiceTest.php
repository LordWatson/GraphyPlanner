<?php

namespace Tests\Feature\Store;

use App\Enums\OrganizationType;
use App\Enums\ProductStatus;
use App\Enums\ProductType;
use App\Models\Booking;
use App\Models\Client;
use App\Models\Order;
use App\Models\Organization;
use App\Models\Product;
use App\Services\Store\BookingSlotService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Step CG-4.5 — slot generation. The interesting cases are all about time: the brand's local wall
 * clock across a DST change, how much notice a slot needs, and a slot that is already taken.
 *
 * It touches the database (bookings), so it is a Feature test rather than a Unit one.
 */
class BookingSlotServiceTest extends TestCase
{
    use RefreshDatabase;

    private function bookingProduct(array $availability = [], string $timezone = 'Europe/London'): Product
    {
        $org = Organization::factory()->create([
            'type' => OrganizationType::Business,
            'plan' => 'creator_pro',
            'default_timezone' => $timezone,
        ]);
        $brand = Client::factory()->for($org, 'organization')->primary()->create();

        return Product::create([
            'org_id' => $org->id,
            'client_id' => $brand->id,
            'type' => ProductType::Booking,
            'title' => 'A chat',
            'price_minor' => 5000,
            'currency' => 'GBP',
            'status' => ProductStatus::Live,
            'settings' => ['availability' => [
                'weekdays' => [1, 2, 3, 4, 5, 6, 7],
                'start' => '09:00',
                'end' => '11:00',
                'slot_minutes' => 60,
                'buffer_minutes' => 0,
                'lead_hours' => 0,
                'days_ahead' => 7,
                ...$availability,
            ]],
        ]);
    }

    public function test_a_slot_keeps_its_local_time_across_a_clocks_change(): void
    {
        $product = $this->bookingProduct();

        // The UK moves to BST at 01:00 on Sunday 28 March 2027.
        $slots = app(BookingSlotService::class)->slots(
            $product,
            CarbonImmutable::parse('2027-03-26 00:00:00', 'UTC'),
        );

        $before = collect($slots)->firstWhere('label', 'Fri 26 Mar · 09:00');
        $after = collect($slots)->firstWhere('label', 'Mon 29 Mar · 09:00');

        $this->assertNotNull($before, 'A slot before the change is offered.');
        $this->assertNotNull($after, 'A slot after the change is offered.');

        // Same wall clock for the creator, one hour apart in UTC — which is the whole point.
        $this->assertSame('2027-03-26T09:00:00+00:00', $before['starts_at']);
        $this->assertSame('2027-03-29T08:00:00+00:00', $after['starts_at']);
    }

    public function test_a_local_time_that_does_not_exist_on_a_spring_forward_day_is_dropped(): void
    {
        // 01:00–02:00 local does not happen on 28 March 2027 in London.
        $product = $this->bookingProduct(['start' => '01:00', 'end' => '03:00']);

        $slots = app(BookingSlotService::class)->slots(
            $product,
            CarbonImmutable::parse('2027-03-28 00:00:00', 'UTC'),
        );

        $labels = array_column($slots, 'label');

        $this->assertNotContains('Sun 28 Mar · 01:00', $labels);
        $this->assertContains('Sun 28 Mar · 02:00', $labels);
    }

    public function test_notice_and_the_window_bound_what_is_offered(): void
    {
        $product = $this->bookingProduct(['lead_hours' => 48, 'days_ahead' => 3]);
        $now = CarbonImmutable::parse('2026-06-01 08:00:00', 'UTC');

        $slots = app(BookingSlotService::class)->slots($product, $now);
        $starts = array_column($slots, 'starts_at');

        $this->assertNotEmpty($starts);

        foreach ($starts as $start) {
            $this->assertTrue(CarbonImmutable::parse($start)->greaterThanOrEqualTo($now->addHours(48)));
            $this->assertTrue(CarbonImmutable::parse($start)->lessThanOrEqualTo($now->addDays(4)));
        }
    }

    public function test_a_taken_slot_is_not_offered_again(): void
    {
        $product = $this->bookingProduct();
        $service = app(BookingSlotService::class);
        $now = CarbonImmutable::parse('2026-06-01 06:00:00', 'UTC');

        $first = $service->slots($product, $now)[0];

        $order = Order::create([
            'org_id' => $product->org_id,
            'product_id' => $product->id,
            'buyer_email' => 'buyer@example.test',
            'amount_minor' => 5000,
            'currency' => 'GBP',
            'status' => 'pending',
        ]);
        Booking::create([
            'order_id' => $order->id,
            'product_id' => $product->id,
            'starts_at' => $first['starts_at'],
            'ends_at' => CarbonImmutable::parse($first['starts_at'])->addHour(),
        ]);

        $remaining = array_column($service->slots($product, $now), 'starts_at');

        $this->assertNotContains($first['starts_at'], $remaining);
        $this->assertFalse($service->isBookable($product, CarbonImmutable::parse($first['starts_at']), $now));
    }
}
