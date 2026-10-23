<?php

namespace Tests\Feature\Clients;

use App\Actions\Posts\SendClientReviewRequestedEmailAction;
use App\Enums\ClientContactRole;
use App\Enums\OrganizationType;
use App\Enums\PostStatus;
use App\Enums\Role;
use App\Mail\ClientReviewRequestedMail;
use App\Models\Client;
use App\Models\ClientContact;
use App\Models\Organization;
use App\Models\Post;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * Step M-6.1 — client contacts: the backfill from the legacy single-address columns, CRUD and
 * authorization, and review emails reaching every approver contact.
 */
class ClientContactTest extends TestCase
{
    use RefreshDatabase;

    private Organization $org;

    private Client $client;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();

        Mail::fake();

        $this->org = Organization::factory()->create();
        $this->owner = User::factory()->for($this->org, 'organization')->role(Role::Owner)->create();
        $this->client = Client::factory()->for($this->org, 'organization')->create([
            'approval_email' => null,
            'billing_email' => null,
        ]);
    }

    public function test_backfill_migrates_legacy_addresses_into_contacts(): void
    {
        $client = Client::factory()->for($this->org, 'organization')->create([
            'approval_email' => 'approver@acme.test',
            'billing_email' => 'finance@acme.test',
        ]);
        $shared = Client::factory()->for($this->org, 'organization')->create([
            'approval_email' => 'both@shared.test',
            'billing_email' => 'both@shared.test',
        ]);

        // The create-table migration already ran; run the backfill explicitly over these rows.
        $migration = require database_path('migrations/2026_11_04_100200_backfill_client_contacts_from_clients.php');
        $migration->up();

        $approver = ClientContact::where('client_id', $client->id)->where('email', 'approver@acme.test')->sole();
        $this->assertSame(ClientContactRole::Approver, $approver->role);
        $this->assertTrue($approver->receives_review_emails);
        $this->assertFalse($approver->receives_invoices);

        $billing = ClientContact::where('client_id', $client->id)->where('email', 'finance@acme.test')->sole();
        $this->assertSame(ClientContactRole::Billing, $billing->role);
        $this->assertTrue($billing->receives_invoices);
        $this->assertFalse($billing->receives_review_emails);

        // One address used for both becomes a single contact with both channels on.
        $merged = ClientContact::where('client_id', $shared->id)->sole();
        $this->assertSame('both@shared.test', $merged->email);
        $this->assertTrue($merged->receives_review_emails);
        $this->assertTrue($merged->receives_invoices);
    }

    public function test_backfill_is_idempotent(): void
    {
        $client = Client::factory()->for($this->org, 'organization')->create(['approval_email' => 'a@b.test', 'billing_email' => null]);
        $migration = require database_path('migrations/2026_11_04_100200_backfill_client_contacts_from_clients.php');

        $migration->up();
        $migration->up();

        $this->assertSame(1, ClientContact::where('client_id', $client->id)->count());
    }

    public function test_owner_can_add_a_contact_with_role_defaults(): void
    {
        $this->actingAs($this->owner)
            ->post(route('clients.contacts.store', $this->client), [
                'name' => 'Sarah Khan',
                'email' => 'sarah@acme.test',
                'role' => ClientContactRole::Approver->value,
            ])
            ->assertRedirect(route('clients.portal-access', $this->client));

        $contact = ClientContact::where('client_id', $this->client->id)->sole();
        $this->assertSame('sarah@acme.test', $contact->email);
        $this->assertTrue($contact->receives_review_emails);
        $this->assertFalse($contact->receives_invoices);
        $this->assertSame($this->org->id, $contact->org_id);
    }

    public function test_duplicate_live_email_for_a_client_is_rejected(): void
    {
        ClientContact::factory()->forClient($this->client)->create(['email' => 'dupe@acme.test']);

        $this->actingAs($this->owner)
            ->from(route('clients.portal-access', $this->client))
            ->post(route('clients.contacts.store', $this->client), [
                'email' => 'dupe@acme.test',
                'role' => ClientContactRole::Other->value,
            ])
            ->assertSessionHasErrors('email');

        $this->assertSame(1, ClientContact::where('client_id', $this->client->id)->count());
    }

    public function test_owner_can_update_and_remove_a_contact(): void
    {
        $contact = ClientContact::factory()->forClient($this->client)->create();

        $this->actingAs($this->owner)
            ->put(route('contacts.update', $contact), [
                'name' => 'Renamed',
                'email' => $contact->email,
                'role' => ClientContactRole::MarketingLead->value,
                'receives_review_emails' => '0',
                'receives_invoices' => '1',
            ])
            ->assertRedirect(route('clients.portal-access', $this->client));

        $contact->refresh();
        $this->assertSame('Renamed', $contact->name);
        $this->assertSame(ClientContactRole::MarketingLead, $contact->role);
        $this->assertFalse($contact->receives_review_emails);
        $this->assertTrue($contact->receives_invoices);

        $this->actingAs($this->owner)
            ->delete(route('contacts.destroy', $contact))
            ->assertRedirect(route('clients.portal-access', $this->client));

        $this->assertSoftDeleted($contact);
    }

    public function test_designer_and_viewer_cannot_manage_contacts(): void
    {
        $contact = ClientContact::factory()->forClient($this->client)->create();

        foreach ([Role::Designer, Role::Viewer] as $role) {
            $user = User::factory()->for($this->org, 'organization')->role($role)->create();

            $this->actingAs($user)
                ->post(route('clients.contacts.store', $this->client), [
                    'email' => 'x@acme.test',
                    'role' => ClientContactRole::Other->value,
                ])
                ->assertForbidden();

            $this->actingAs($user)
                ->delete(route('contacts.destroy', $contact))
                ->assertForbidden();
        }
    }

    public function test_business_workspace_cannot_manage_contacts(): void
    {
        $businessOrg = Organization::factory()->create(['type' => OrganizationType::Business]);
        $businessOwner = User::factory()->for($businessOrg, 'organization')->role(Role::Owner)->create();
        $brand = Client::factory()->for($businessOrg, 'organization')->create();

        // The `workspace:agency` middleware 404s a business workspace before the policy is reached;
        // the ClientContactPolicy::before guard is the second line of defence.
        $this->actingAs($businessOwner)
            ->post(route('clients.contacts.store', $brand), [
                'email' => 'x@acme.test',
                'role' => ClientContactRole::Other->value,
            ])
            ->assertNotFound();
    }

    public function test_review_email_reaches_every_approver_contact(): void
    {
        ClientContact::factory()->forClient($this->client)->create(['email' => 'a@acme.test', 'receives_review_emails' => true]);
        ClientContact::factory()->forClient($this->client)->create(['email' => 'b@acme.test', 'receives_review_emails' => true]);
        // A billing contact who did not opt into review emails is not included.
        ClientContact::factory()->forClient($this->client)->billing()->create(['email' => 'finance@acme.test']);

        $post = Post::factory()->for($this->client)->create(['org_id' => $this->org->id, 'status' => PostStatus::WaitingClient]);

        app(SendClientReviewRequestedEmailAction::class)($post);

        Mail::assertQueued(ClientReviewRequestedMail::class, fn ($mail) => $mail->hasTo('a@acme.test'));
        Mail::assertQueued(ClientReviewRequestedMail::class, fn ($mail) => $mail->hasTo('b@acme.test'));
        Mail::assertQueued(ClientReviewRequestedMail::class, 2);
    }

    public function test_review_email_falls_back_to_legacy_approval_email(): void
    {
        $client = Client::factory()->for($this->org, 'organization')->create(['approval_email' => 'legacy@acme.test']);
        $post = Post::factory()->for($client)->create(['org_id' => $this->org->id, 'status' => PostStatus::WaitingClient]);

        app(SendClientReviewRequestedEmailAction::class)($post);

        Mail::assertQueued(ClientReviewRequestedMail::class, 1);
        Mail::assertQueued(ClientReviewRequestedMail::class, fn ($mail) => $mail->hasTo('legacy@acme.test'));
    }

    public function test_review_email_prefers_contacts_over_legacy_column(): void
    {
        $client = Client::factory()->for($this->org, 'organization')->create(['approval_email' => 'legacy@acme.test']);
        ClientContact::factory()->forClient($client)->create(['email' => 'contact@acme.test', 'receives_review_emails' => true]);
        $post = Post::factory()->for($client)->create(['org_id' => $this->org->id, 'status' => PostStatus::WaitingClient]);

        app(SendClientReviewRequestedEmailAction::class)($post);

        Mail::assertQueued(ClientReviewRequestedMail::class, 1);
        Mail::assertQueued(ClientReviewRequestedMail::class, fn ($mail) => $mail->hasTo('contact@acme.test'));
        Mail::assertNotQueued(ClientReviewRequestedMail::class, fn ($mail) => $mail->hasTo('legacy@acme.test'));
    }

    public function test_cross_tenant_contact_queries_are_scoped(): void
    {
        $otherOrg = Organization::factory()->create();
        $otherClient = Client::factory()->for($otherOrg, 'organization')->create();
        ClientContact::factory()->forClient($otherClient)->create();
        ClientContact::factory()->forClient($this->client)->create();

        $this->actingAs($this->owner);

        // The global org scope hides the other org's contacts.
        $this->assertSame(1, ClientContact::count());
    }

    public function test_mail_queued_once_regardless_of_transaction(): void
    {
        // Guards the DB::afterCommit path: a successful transition queues the mail.
        $contact = ClientContact::factory()->forClient($this->client)->create(['receives_review_emails' => true]);
        $post = Post::factory()->for($this->client)->create(['org_id' => $this->org->id, 'status' => PostStatus::WaitingClient]);

        DB::transaction(fn () => app(SendClientReviewRequestedEmailAction::class)($post));

        Mail::assertQueued(ClientReviewRequestedMail::class, 1);
        Mail::assertQueued(ClientReviewRequestedMail::class, fn ($mail) => $mail->hasTo($contact->email));
    }
}
