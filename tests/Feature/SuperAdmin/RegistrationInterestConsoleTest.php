<?php

namespace Tests\Feature\SuperAdmin;

use App\Enums\RegistrationInterestStatus;
use App\Enums\Role;
use App\Models\Organization;
use App\Models\RegistrationInterest;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * The super-admin registrations console: list + stats, detail, and working a lead.
 */
class RegistrationInterestConsoleTest extends TestCase
{
    use RefreshDatabase;

    private User $superAdmin;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow('2026-11-11 12:00:00');

        $this->superAdmin = User::factory()->superAdmin()->create();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_only_super_admins_can_reach_the_console(): void
    {
        $interest = RegistrationInterest::factory()->create();
        $owner = User::factory()->for(Organization::factory()->create(), 'organization')->role(Role::Owner)->create();

        $this->get(route('super-admin.registrations.index'))->assertRedirect(route('login'));
        $this->actingAs($owner)->get(route('super-admin.registrations.index'))->assertForbidden();
        $this->actingAs($owner)->get(route('super-admin.registrations.show', $interest))->assertForbidden();
        $this->actingAs($owner)
            ->put(route('super-admin.registrations.update', $interest), ['status' => 'onboarded'])
            ->assertForbidden();

        $this->assertSame(RegistrationInterestStatus::New, $interest->fresh()->status);
    }

    public function test_the_index_lists_filters_and_computes_stats(): void
    {
        RegistrationInterest::factory()->create([
            'company_name' => 'Northlight', 'country' => 'GB', 'utm_source' => 'instagram',
            'created_at' => now()->subDays(2),
            'contacted_at' => now()->subDays(2)->addHours(3),
            'status' => RegistrationInterestStatus::DemoBooked,
        ]);
        RegistrationInterest::factory()->create([
            'company_name' => 'Southwind', 'country' => 'GB', 'referrer' => 'https://www.google.com/search',
            'created_at' => now()->subDays(10),
        ]);
        RegistrationInterest::factory()->create([
            'company_name' => 'Eastbay', 'created_at' => now()->subDays(45),
            'contacted_at' => now()->subDays(45)->addHours(2),
            'status' => RegistrationInterestStatus::Onboarded,
        ]);

        $this->actingAs($this->superAdmin)
            ->get(route('super-admin.registrations.index'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('super-admin/registrations/index')
                ->has('registrations.data', 3)
                ->where('stats.total', 3)
                ->where('stats.last7Days', 1)
                ->where('stats.last30Days', 2)
                ->where('stats.previous30Days', 1)
                ->where('stats.awaitingContact', 1)
                ->where('stats.demoRate', 67)
                ->where('stats.onboardedRate', 33)
                ->where('stats.avgHoursToContact', 2.5)
                ->where('stats.topCountries.0', ['label' => 'GB', 'count' => 2])
                ->where('stats.topSources', fn ($sources) => collect($sources)->pluck('label')->sort()->values()->all() === ['Direct', 'google.com', 'instagram'])
                ->has('stats.daily', 30));

        $this->actingAs($this->superAdmin)
            ->get(route('super-admin.registrations.index', ['status' => 'new']))
            ->assertInertia(fn ($page) => $page
                ->has('registrations.data', 1)
                ->where('registrations.data.0.company_name', 'Southwind'));

        $this->actingAs($this->superAdmin)
            ->get(route('super-admin.registrations.index', ['q' => 'east']))
            ->assertInertia(fn ($page) => $page
                ->has('registrations.data', 1)
                ->where('registrations.data.0.company_name', 'Eastbay'));
    }

    public function test_the_detail_page_shows_the_visit_details(): void
    {
        $interest = RegistrationInterest::factory()->create(['ip_address' => '203.0.113.7', 'timezone' => 'Europe/London']);

        $this->actingAs($this->superAdmin)
            ->get(route('super-admin.registrations.show', $interest))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('super-admin/registrations/show')
                ->where('registration.ip_address', '203.0.113.7')
                ->where('registration.timezone', 'Europe/London')
                ->has('statuses', count(RegistrationInterestStatus::cases())));
    }

    public function test_working_a_lead_updates_it_and_stamps_first_contact_once(): void
    {
        $interest = RegistrationInterest::factory()->create();

        $this->actingAs($this->superAdmin)
            ->put(route('super-admin.registrations.update', $interest), [
                'status' => 'demo_booked',
                'demo_at' => '2026-11-14T10:00:00.000Z',
                'notes' => 'Six clients, mostly Instagram.',
            ])
            ->assertSessionHasNoErrors()
            ->assertRedirect();

        $interest->refresh();
        $this->assertSame(RegistrationInterestStatus::DemoBooked, $interest->status);
        $this->assertSame('2026-11-14 10:00:00', $interest->demo_at->toDateTimeString());
        $this->assertSame('Six clients, mostly Instagram.', $interest->notes);
        $this->assertTrue($interest->contacted_at->equalTo(now()));

        Carbon::setTestNow(now()->addDay());

        $this->actingAs($this->superAdmin)
            ->put(route('super-admin.registrations.update', $interest), ['status' => 'onboarded'])
            ->assertSessionHasNoErrors();

        $interest->refresh();
        $this->assertSame(RegistrationInterestStatus::Onboarded, $interest->status);
        $this->assertTrue($interest->contacted_at->equalTo(now()->subDay()));
    }

    public function test_an_unknown_status_is_rejected(): void
    {
        $interest = RegistrationInterest::factory()->create();

        $this->actingAs($this->superAdmin)
            ->put(route('super-admin.registrations.update', $interest), ['status' => 'married'])
            ->assertSessionHasErrors('status');

        $this->assertSame(RegistrationInterestStatus::New, $interest->fresh()->status);
    }
}
