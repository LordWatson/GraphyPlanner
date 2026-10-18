<?php

namespace Tests\Feature\Security;

use App\Actions\Posts\TransitionPostStatusAction;
use App\Contracts\AssetStorage;
use App\Enums\InvoiceStatus;
use App\Enums\PostStatus;
use App\Enums\Role;
use App\Mail\ClientReviewRequestedMail;
use App\Models\Asset;
use App\Models\Client;
use App\Models\Invoice;
use App\Models\Organization;
use App\Models\Post;
use App\Models\SocialAccount;
use App\Models\User;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Step I-1.6 — secrets never reach a prop, raw review tokens never reach the database, and private
 * files are only reachable through signed URLs or authorized routes.
 */
class SecretsAndPrivateMediaTest extends TestCase
{
    use RefreshDatabase;

    private Organization $org;

    private User $owner;

    private Client $client;

    protected function setUp(): void
    {
        parent::setUp();

        // The real private disk (not `Storage::fake()`, which replaces signed URLs with plain
        // ones), pointed at a throwaway root so the test never touches real files.
        config(['filesystems.disks.local.root' => storage_path('framework/testing/private-files')]);
        Storage::forgetDisk('local');

        $this->org = Organization::factory()->create();
        $this->owner = User::factory()->for($this->org, 'organization')->role(Role::Owner)->create();
        $this->client = Client::factory()->for($this->org, 'organization')->create(['approval_email' => 'client@example.com']);
    }

    protected function tearDown(): void
    {
        (new Filesystem)->deleteDirectory(storage_path('framework/testing/private-files'));

        parent::tearDown();
    }

    public function test_no_page_that_renders_accounts_leaks_the_meta_token_or_vendor_profile_id(): void
    {
        $account = SocialAccount::factory()->for($this->client)->create([
            'org_id' => $this->org->id,
            'meta_access_token' => 'meta-secret-token-123',
            'external_profile_id' => 'vendor-profile-secret-456',
        ]);
        $post = Post::factory()->for($this->client)->create(['org_id' => $this->org->id]);
        $post->targets()->create(['social_account_id' => $account->id, 'scheduled_at_utc' => now()->addDay()]);

        $this->assertArrayNotHasKey('meta_access_token', $account->toArray());
        $this->assertArrayNotHasKey('external_profile_id', $account->toArray());

        foreach ([
            route('clients.show', $this->client),
            route('posts.edit', $post),
            route('calendar'),
            route('needs-attention'),
            route('dashboard'),
        ] as $url) {
            $this->actingAs($this->owner)->get($url)
                ->assertOk()
                ->assertDontSee('meta-secret-token-123', false)
                ->assertDontSee('vendor-profile-secret-456', false);
        }
    }

    public function test_no_database_column_holds_a_raw_review_token(): void
    {
        Mail::fake();
        $post = Post::factory()->for($this->client)->create(['org_id' => $this->org->id, 'status' => PostStatus::InternalReview]);

        app(TransitionPostStatusAction::class)($post, PostStatus::WaitingClient, $this->owner);

        $plainToken = null;
        Mail::assertQueued(ClientReviewRequestedMail::class, function (ClientReviewRequestedMail $mail) use (&$plainToken) {
            $plainToken = str($mail->reviewUrl)->afterLast('/review/')->toString();

            return true;
        });

        $this->assertNotEmpty($plainToken);
        $this->assertFalse(Schema::hasColumn('post_activity_logs', 'review_url'));

        // Scan every table: the plain token must not be stored anywhere (only its hash is).
        foreach (Schema::getTableListing() as $table) {
            $table = str($table)->afterLast('.')->toString();

            if (in_array($table, ['jobs', 'failed_jobs', 'job_batches'], true)) {
                continue; // transient queue payloads, not records
            }

            $this->assertStringNotContainsString($plainToken, json_encode(DB::table($table)->get()), "Table {$table} holds a raw review token.");
        }
    }

    public function test_a_private_asset_is_only_reachable_through_its_signed_url(): void
    {
        $stored = app(AssetStorage::class)->store(UploadedFile::fake()->image('cover.jpg'), 'assets/'.$this->org->id);
        $asset = Asset::factory()->for($this->client)->uploaded()->create([
            'org_id' => $this->org->id,
            'disk' => $stored['disk'],
            'path' => $stored['path'],
            'url' => $stored['url'],
        ]);

        $this->assertSame('local', $stored['disk']);

        // A guessed (unsigned) URL is refused…
        $this->get('/files/'.$stored['path'])->assertForbidden();

        // …the signed URL minted on read works…
        $this->get((string) $asset->fresh()->url)->assertOk();

        // …and stops working once it expires.
        $signed = (string) $asset->fresh()->url;
        $this->travel((int) config('assets.url_ttl_minutes') + 1)->minutes();
        $this->get($signed)->assertForbidden();
    }

    public function test_invoice_pdfs_are_only_served_through_authorized_routes(): void
    {
        Storage::disk('local')->put('invoices/inv.pdf', '%PDF-1.4 test');
        $invoice = Invoice::factory()->for($this->client)->create([
            'org_id' => $this->org->id,
            'status' => InvoiceStatus::Sent,
            'pdf_disk' => 'local',
            'pdf_path' => 'invoices/inv.pdf',
            'pdf_url' => 'http://localhost/files/invoices/inv.pdf',
        ]);
        $portalUser = User::factory()->for($this->org, 'organization')->role(Role::ClientReviewer)->create(['client_id' => $this->client->id]);
        $otherClient = Client::factory()->for($this->org, 'organization')->create();
        $otherPortalUser = User::factory()->for($this->org, 'organization')->role(Role::ClientReviewer)->create(['client_id' => $otherClient->id]);
        $outsider = User::factory()->for(Organization::factory(), 'organization')->role(Role::Owner)->create();

        $this->get('/files/invoices/inv.pdf')->assertForbidden();

        $this->actingAs($this->owner)->get(route('invoices.pdf.show', $invoice))
            ->assertOk()
            ->assertHeader('Content-Type', 'application/pdf');
        $this->actingAs($portalUser)->get(route('portal.invoices.pdf', $invoice))->assertOk();

        $this->actingAs($outsider)->get(route('invoices.pdf.show', $invoice))->assertForbidden();
        $this->actingAs($otherPortalUser)->get(route('portal.invoices.pdf', $invoice))->assertNotFound();

        $this->actingAs($this->owner)->get(route('invoices.show', $invoice))
            ->assertInertia(fn ($page) => $page->where('invoice.pdf_url', route('invoices.pdf.show', $invoice)));
    }

    public function test_settings_show_only_the_last_four_characters_of_stored_keys(): void
    {
        $this->org->update(['upload_post_key' => 'up-key-abcdefgh-1234']);

        $this->actingAs($this->owner)->get(route('organization.edit'))
            ->assertInertia(fn ($page) => $page->where('organization.upload_post_key_last4', '1234'))
            ->assertDontSee('up-key-abcdefgh-1234', false);
    }

    public function test_the_owner_can_test_the_upload_post_connection(): void
    {
        $this->org->update(['upload_post_key' => 'up-key-abcdefgh-1234']);
        Http::fake(['*/uploadposts/users' => Http::response(['profiles' => []], 200)]);

        $this->actingAs($this->owner)
            ->post(route('organization.test-upload-post'))
            ->assertRedirect(route('organization.edit'));

        Http::assertSent(fn ($request) => $request->hasHeader('Authorization', 'Apikey up-key-abcdefgh-1234'));

        $strategist = User::factory()->for($this->org, 'organization')->role(Role::Strategist)->create();
        $this->actingAs($strategist)->post(route('organization.test-upload-post'))->assertForbidden();
    }
}
