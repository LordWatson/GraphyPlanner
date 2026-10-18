<?php

namespace Tests\Feature\Clients;

use App\Actions\ReviewTokens\CreateReviewTokenAction;
use App\Actions\SuperAdmin\DeleteOrganizationAction;
use App\Contracts\PublishAdapter;
use App\Enums\ClientStatus;
use App\Enums\PostStatus;
use App\Enums\Role;
use App\Jobs\PublishPostJob;
use App\Models\Asset;
use App\Models\Client;
use App\Models\Invoice;
use App\Models\Organization;
use App\Models\Post;
use App\Models\SocialAccount;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Mockery;
use Tests\TestCase;

/**
 * Step I-1.5 — "Delete client" archives (soft delete), invoices outlive the client, and only an
 * Owner can purge an archived client that has no invoices.
 */
class ClientArchiveTest extends TestCase
{
    use RefreshDatabase;

    private Organization $org;

    private User $owner;

    private Client $client;

    protected function setUp(): void
    {
        parent::setUp();

        $this->org = Organization::factory()->create();
        $this->owner = User::factory()->for($this->org, 'organization')->role(Role::Owner)->create();
        $this->client = Client::factory()->for($this->org, 'organization')->create(['status' => ClientStatus::Active]);
    }

    public function test_deleting_a_client_archives_it_and_keeps_its_invoices(): void
    {
        $invoice = Invoice::factory()->for($this->client)->create(['org_id' => $this->org->id]);
        $post = Post::factory()->for($this->client)->create(['org_id' => $this->org->id]);

        $this->actingAs($this->owner)
            ->delete(route('clients.destroy', $this->client))
            ->assertRedirect(route('clients.index'));

        $this->assertSoftDeleted($this->client);
        $this->assertSame(ClientStatus::Archived, Client::withTrashed()->find($this->client->id)->status);

        // The invoice (a legal record) and the client's history are untouched…
        $this->assertNotNull(Invoice::find($invoice->id));
        $this->assertSame($this->client->id, $invoice->fresh()->client->id);
        $this->assertNotNull(Post::find($post->id));

        // …and the client is gone from the roster and its pages.
        $this->actingAs($this->owner)->get(route('clients.index'))
            ->assertInertia(fn ($page) => $page->has('clients', 0)->where('archivedCount', 1));
        $this->actingAs($this->owner)->get(route('clients.show', $this->client))->assertNotFound();
    }

    public function test_the_archived_filter_lists_archived_clients_and_they_restore_cleanly(): void
    {
        $this->client->delete();

        $this->actingAs($this->owner)->get(route('clients.index', ['archived' => 1]))
            ->assertInertia(fn ($page) => $page
                ->where('filters.archived', true)
                ->has('clients', 1)
                ->where('clients.0.id', $this->client->id));

        $this->actingAs($this->owner)
            ->post(route('clients.restore', $this->client))
            ->assertRedirect(route('clients.show', $this->client));

        $restored = $this->client->fresh();
        $this->assertFalse($restored->trashed());
        $this->assertSame(ClientStatus::Active, $restored->status);
        $this->actingAs($this->owner)->get(route('clients.show', $this->client))->assertOk();
    }

    public function test_purge_is_blocked_while_the_client_has_invoices(): void
    {
        Invoice::factory()->for($this->client)->create(['org_id' => $this->org->id]);
        $this->client->delete();

        $this->actingAs($this->owner)
            ->delete(route('clients.purge', $this->client))
            ->assertSessionHasErrors('client');

        $this->assertNotNull(Client::withTrashed()->find($this->client->id));
    }

    public function test_the_database_refuses_to_hard_delete_a_client_with_invoices(): void
    {
        Invoice::factory()->for($this->client)->create(['org_id' => $this->org->id]);

        $this->expectException(QueryException::class);

        $this->client->forceDelete();
    }

    public function test_purging_removes_the_client_its_rows_files_and_portal_logins(): void
    {
        Storage::fake('public');
        $path = UploadedFile::fake()->image('cover.jpg')->storeAs('assets', 'cover.jpg', 'public');
        $asset = Asset::factory()->for($this->client)->uploaded()->create(['org_id' => $this->org->id, 'disk' => 'public', 'path' => $path]);
        $post = Post::factory()->for($this->client)->create(['org_id' => $this->org->id]);
        $portalUser = User::factory()->for($this->org, 'organization')->role(Role::ClientReviewer)->create(['client_id' => $this->client->id]);
        $this->client->delete();

        $this->actingAs($this->owner)
            ->delete(route('clients.purge', $this->client))
            ->assertRedirect(route('clients.index', ['archived' => 1]));

        $this->assertNull(Client::withTrashed()->find($this->client->id));
        $this->assertNull(Post::withTrashed()->find($post->id));
        $this->assertNull(Asset::withTrashed()->find($asset->id));
        $this->assertNull(User::find($portalUser->id));
        Storage::disk('public')->assertMissing($path);
    }

    public function test_only_an_owner_can_archive_restore_or_purge(): void
    {
        $strategist = User::factory()->for($this->org, 'organization')->role(Role::Strategist)->create();
        $outsider = User::factory()->for(Organization::factory(), 'organization')->role(Role::Owner)->create();

        $this->actingAs($strategist)->delete(route('clients.destroy', $this->client))->assertForbidden();
        $this->actingAs($outsider)->delete(route('clients.destroy', $this->client))->assertForbidden();

        $this->client->delete();

        $this->actingAs($strategist)->post(route('clients.restore', $this->client))->assertForbidden();
        $this->actingAs($strategist)->delete(route('clients.purge', $this->client))->assertForbidden();
        $this->actingAs($outsider)->post(route('clients.restore', $this->client))->assertForbidden();
        $this->actingAs($outsider)->delete(route('clients.purge', $this->client))->assertForbidden();
    }

    public function test_an_active_client_must_be_archived_before_it_can_be_purged(): void
    {
        $this->actingAs($this->owner)->delete(route('clients.purge', $this->client))->assertForbidden();

        $this->assertNotNull(Client::find($this->client->id));
    }

    public function test_an_archived_clients_posts_do_not_publish(): void
    {
        $post = Post::factory()->for($this->client)->create(['org_id' => $this->org->id, 'status' => PostStatus::Scheduled]);
        $account = SocialAccount::factory()->for($this->client)->create(['org_id' => $this->org->id]);
        $post->targets()->create(['social_account_id' => $account->id, 'scheduled_at_utc' => now()]);
        $this->client->delete();

        $adapter = Mockery::mock(PublishAdapter::class);
        $adapter->shouldNotReceive('publish');
        $this->app->instance(PublishAdapter::class, $adapter);

        $this->app->call([new PublishPostJob($post->id), 'handle']);

        $this->assertSame(PostStatus::Scheduled, $post->fresh()->status);
    }

    public function test_an_archived_clients_review_links_and_portal_are_closed(): void
    {
        $post = Post::factory()->for($this->client)->create(['org_id' => $this->org->id, 'status' => PostStatus::WaitingClient]);
        ['token' => $token] = app(CreateReviewTokenAction::class)($this->client, $post);
        $portalUser = User::factory()->for($this->org, 'organization')->role(Role::ClientReviewer)->create(['client_id' => $this->client->id]);

        $this->get(route('review.show', $token))->assertOk();

        $this->client->delete();

        $this->get(route('review.show', $token))->assertNotFound();
        $this->actingAs($portalUser)->get(route('portal.dashboard'))->assertForbidden();
    }

    public function test_deleting_an_organization_soft_deletes_it_then_purges_its_files(): void
    {
        Storage::fake('public');
        $path = UploadedFile::fake()->image('logo.png')->storeAs('assets', 'logo.png', 'public');
        Asset::factory()->for($this->client)->uploaded()->create(['org_id' => $this->org->id, 'disk' => 'public', 'path' => $path]);
        Invoice::factory()->for($this->client)->create(['org_id' => $this->org->id]);
        $admin = User::factory()->superAdmin()->create();

        // The queue runs synchronously in tests, so the purge job runs right after commit.
        app(DeleteOrganizationAction::class)($this->org, $admin);

        $this->assertNull(Organization::withTrashed()->find($this->org->id));
        $this->assertSame(0, Invoice::withTrashed()->where('org_id', $this->org->id)->count());
        Storage::disk('public')->assertMissing($path);
    }
}
