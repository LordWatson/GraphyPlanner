<?php

namespace Tests\Feature\Clients;

use App\Actions\ClientTemplates\CreateClientFromTemplateAction;
use App\Actions\ClientTemplates\SaveClientAsTemplateAction;
use App\Actions\ClientTemplates\SeedBuiltInTemplatesAction;
use App\Actions\Organizations\RegisterOrganizationAction;
use App\Enums\ApprovalMode;
use App\Enums\ApprovalPolicy;
use App\Enums\ClientStatus;
use App\Enums\Role;
use App\Models\BrandBrain;
use App\Models\Client;
use App\Models\ClientTemplate;
use App\Models\Organization;
use App\Models\User;
use App\Support\Clients\ClientTemplateSnapshot;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/**
 * Client onboarding templates: capture the shape of an engagement once, apply it to every new
 * client of that kind.
 *
 * The tests that matter most are the ones about what a template must **not** carry. Copying a
 * client's approval email onto another client would send one client's posts to another's inbox —
 * the worst thing this feature could do — so that is pinned rather than trusted to a code review.
 */
class ClientTemplateTest extends TestCase
{
    use RefreshDatabase;

    private Organization $org;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();

        $this->org = Organization::factory()->create();
        $this->owner = User::factory()->for($this->org, 'organization')->role(Role::Owner)->create();

        // The factory is not registration, so the built-ins are seeded here. That registration
        // itself seeds them is proved separately below, against the real action.
        app(SeedBuiltInTemplatesAction::class)($this->org);
    }

    private function configuredClient(): Client
    {
        $client = Client::factory()->for($this->org, 'organization')->create([
            'name' => 'Acme Plumbing',
            'approval_email' => 'approvals@acme.test',
            'billing_email' => 'billing@acme.test',
            'website' => 'https://acme.test',
            'default_approval_mode' => ApprovalMode::ClientRequired,
            'approval_policy' => ApprovalPolicy::Sequential,
            'approval_lead_days' => 4,
            'target_posts_per_week' => 5,
            'status' => ClientStatus::Active,
        ]);

        $client->brandBrain()->create([
            'voice' => 'Plain and warm.',
            'audience' => 'People nearby.',
        ]);

        return $client;
    }

    // --- capture -----------------------------------------------------------------------------

    public function test_saving_a_client_as_a_template_captures_how_we_work_with_them(): void
    {
        $client = $this->configuredClient();

        $template = app(SaveClientAsTemplateAction::class)($client, $this->owner, [
            'name' => 'Local service business',
            'description' => 'Our usual plumber setup',
        ]);

        $this->assertSame('Local service business', $template->name);
        $this->assertSame(ApprovalPolicy::Sequential->value, $template->snapshot['client']['approval_policy']);
        $this->assertSame(4, $template->snapshot['client']['approval_lead_days']);
        $this->assertSame(5, $template->snapshot['client']['target_posts_per_week']);
        $this->assertSame('Plain and warm.', $template->snapshot['brand_brain']['voice']);
    }

    /**
     * The one that would really hurt. An approval email copied between clients sends one client's
     * posts to another client's inbox.
     */
    public function test_a_template_never_carries_identity_or_contact_details(): void
    {
        $template = app(SaveClientAsTemplateAction::class)(
            $this->configuredClient(),
            $this->owner,
            ['name' => 'Shape only'],
        );

        $snapshot = $template->snapshot['client'];

        foreach (array_keys(ClientTemplateSnapshot::NEVER) as $field) {
            $this->assertArrayNotHasKey($field, $snapshot, "A template must not carry {$field}.");
        }
    }

    public function test_saving_again_under_the_same_name_updates_rather_than_duplicates(): void
    {
        $client = $this->configuredClient();

        app(SaveClientAsTemplateAction::class)($client, $this->owner, ['name' => 'Standard']);
        $client->update(['target_posts_per_week' => 9]);
        app(SaveClientAsTemplateAction::class)($client->fresh(), $this->owner, ['name' => 'Standard']);

        // Pressing "save as template" twice is somebody refining one, not making two.
        $this->assertSame(1, ClientTemplate::query()->where('name', 'Standard')->count());
        $this->assertSame(9, ClientTemplate::query()->where('name', 'Standard')->firstOrFail()->snapshot['client']['target_posts_per_week']);
    }

    // --- apply -------------------------------------------------------------------------------

    public function test_a_new_client_takes_the_templates_settings(): void
    {
        $template = app(SaveClientAsTemplateAction::class)(
            $this->configuredClient(),
            $this->owner,
            ['name' => 'Standard'],
        );

        $client = app(CreateClientFromTemplateAction::class)($this->owner, $template, [
            'name' => 'Beta Dental',
            'status' => ClientStatus::Active->value,
        ]);

        $this->assertSame('Beta Dental', $client->name);
        $this->assertSame(ApprovalPolicy::Sequential, $client->approval_policy);
        $this->assertSame(4, $client->approval_lead_days);
        $this->assertSame(5, $client->target_posts_per_week);
        $this->assertSame('Plain and warm.', $client->brandBrain?->voice);

        // Nothing of the source client's identity came with it.
        $this->assertNull($client->approval_email);
        $this->assertNull($client->website);
    }

    public function test_what_the_person_typed_always_wins_over_the_template(): void
    {
        $template = app(SaveClientAsTemplateAction::class)(
            $this->configuredClient(),
            $this->owner,
            ['name' => 'Standard'],
        );

        $client = app(CreateClientFromTemplateAction::class)($this->owner, $template, [
            'name' => 'Gamma Ltd',
            'status' => ClientStatus::Active->value,
            // A template fills blanks; it does not decide anything a person has answered.
            'target_posts_per_week' => 1,
        ]);

        $this->assertSame(1, $client->target_posts_per_week);
    }

    public function test_a_template_from_another_organization_cannot_be_applied(): void
    {
        $otherOrg = Organization::factory()->create();
        $theirs = ClientTemplate::query()->create([
            'org_id' => $otherOrg->id,
            'name' => 'Theirs',
            'snapshot' => ['client' => ['target_posts_per_week' => 9], 'brand_brain' => []],
        ]);

        $this->expectException(ValidationException::class);

        app(CreateClientFromTemplateAction::class)($this->owner, $theirs, ['name' => 'Nope']);
    }

    // --- built-ins ---------------------------------------------------------------------------

    public function test_three_recognisable_templates_are_seeded(): void
    {
        // An empty template list teaches nobody what templates are for.
        $this->assertSame(3, ClientTemplate::query()->where('is_built_in', true)->count());

        $names = ClientTemplate::query()->orderBy('name')->pluck('name')->all();
        $this->assertContains('Local service business', $names);
        $this->assertContains('E-commerce brand', $names);
        $this->assertContains('B2B thought leadership', $names);
    }

    public function test_seeding_twice_creates_nothing_the_second_time(): void
    {
        $this->assertSame(0, app(SeedBuiltInTemplatesAction::class)($this->org));
        $this->assertSame(3, ClientTemplate::query()->count());
    }

    /**
     * A newly registered agency has them without anybody running anything.
     */
    public function test_registering_an_agency_seeds_the_built_in_templates(): void
    {
        $owner = app(RegisterOrganizationAction::class)([
            'organization_name' => 'Fresh Agency',
            'name' => 'Owner',
            'email' => 'owner@fresh.test',
            'password' => 'password-is-long-enough',
            'default_timezone' => 'UTC',
            'default_currency' => 'GBP',
        ]);

        $this->assertSame(
            3,
            ClientTemplate::query()->withoutOrgScope()->where('org_id', $owner->org_id)->count(),
        );
    }

    public function test_a_deleted_built_in_stays_deleted(): void
    {
        $builtIn = ClientTemplate::query()->where('is_built_in', true)->firstOrFail();
        $builtIn->delete();

        // Rows, not config: re-running the seeder must not resurrect what an Owner removed... but
        // it also must not double up what is still there.
        $created = app(SeedBuiltInTemplatesAction::class)($this->org);

        $this->assertSame(1, $created, 'Only the removed one should be recreated by an explicit re-seed.');
        $this->assertSame(3, ClientTemplate::query()->where('is_built_in', true)->count());
    }

    public function test_the_built_in_shapes_differ_in_the_ways_that_matter(): void
    {
        $byName = ClientTemplate::query()->get()->keyBy('name');

        // Who signs off, and how often you post — not three variations on the same thing.
        $this->assertSame(
            ApprovalMode::InternalOnly->value,
            $byName['E-commerce brand']->snapshot['client']['default_approval_mode'],
        );
        $this->assertSame(
            ApprovalPolicy::Sequential->value,
            $byName['B2B thought leadership']->snapshot['client']['approval_policy'],
        );
        $this->assertGreaterThan(
            $byName['B2B thought leadership']->snapshot['client']['target_posts_per_week'],
            $byName['E-commerce brand']->snapshot['client']['target_posts_per_week'],
        );
    }

    // --- the pages ---------------------------------------------------------------------------

    public function test_the_create_form_offers_the_templates(): void
    {
        $this->actingAs($this->owner)->get(route('clients.create'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page->has('templates', 3));
    }

    public function test_an_owner_saves_a_client_as_a_template_from_its_page(): void
    {
        $client = $this->configuredClient();

        $this->actingAs($this->owner)
            ->post(route('clients.save-as-template', $client), ['name' => 'From the page'])
            ->assertRedirect();

        $this->assertTrue(ClientTemplate::query()->where('name', 'From the page')->exists());
    }

    public function test_creating_a_client_through_the_form_applies_the_chosen_template(): void
    {
        $template = ClientTemplate::query()->where('name', 'B2B thought leadership')->firstOrFail();

        $this->actingAs($this->owner)->post(route('clients.store'), [
            'name' => 'Delta Industries',
            'status' => ClientStatus::Active->value,
            'client_template_id' => $template->id,
        ])->assertRedirect();

        $client = Client::query()->where('name', 'Delta Industries')->firstOrFail();
        $this->assertSame(ApprovalPolicy::Sequential, $client->approval_policy);
    }

    public function test_a_template_id_from_another_organization_is_a_validation_error(): void
    {
        $otherOrg = Organization::factory()->create();
        $theirs = ClientTemplate::query()->create([
            'org_id' => $otherOrg->id,
            'name' => 'Theirs',
            'snapshot' => ['client' => [], 'brand_brain' => []],
        ]);

        $this->actingAs($this->owner)->post(route('clients.store'), [
            'name' => 'Nope',
            'status' => ClientStatus::Active->value,
            'client_template_id' => $theirs->id,
        ])->assertSessionHasErrors('client_template_id');
    }

    public function test_a_non_owner_cannot_manage_templates(): void
    {
        $viewer = User::factory()->for($this->org, 'organization')->role(Role::Viewer)->create();
        $template = ClientTemplate::query()->where('is_built_in', true)->firstOrFail();

        $this->actingAs($viewer)->get(route('client-templates.index'))->assertForbidden();
        $this->actingAs($viewer)->delete(route('client-templates.destroy', $template))->assertForbidden();
    }

    // --- the drift guard ---------------------------------------------------------------------

    /**
     * Every templated field must be a real `Client` fillable, and nothing on the never-list may
     * have crept into the copied list. Both directions, because the failure mode of the second is
     * silent and serious.
     */
    public function test_the_templated_fields_are_real_and_the_excluded_ones_stay_excluded(): void
    {
        $fillable = (new Client)->getFillable();

        foreach (ClientTemplateSnapshot::CLIENT_FIELDS as $field) {
            $this->assertContains($field, $fillable, "{$field} is templated but is not a Client fillable.");
            $this->assertArrayNotHasKey(
                $field,
                ClientTemplateSnapshot::NEVER,
                "{$field} is both templated and on the never-list.",
            );
        }

        foreach (ClientTemplateSnapshot::BRAND_BRAIN_FIELDS as $field) {
            $this->assertContains($field, (new BrandBrain)->getFillable());
        }
    }
}
