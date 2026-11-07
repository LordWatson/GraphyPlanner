<?php

namespace Tests\Feature\Security;

use App\Enums\DataExportStatus;
use App\Enums\PostStatus;
use App\Enums\PostTargetStatus;
use App\Enums\Role;
use App\Jobs\BuildOrganizationDataExportJob;
use App\Models\Client;
use App\Models\DataExport;
use App\Models\Organization;
use App\Models\Post;
use App\Models\SocialAccount;
use App\Models\User;
use App\Notifications\DataExportReady;
use App\Services\Security\OrganizationDataExport;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;
use ZipArchive;

/**
 * "Download everything you hold for us" — the portability half of GDPR, and the other side of
 * `PurgeOrganizationJob`.
 *
 * Two of these tests are drift guards, because the two ways this feature can do real harm are both
 * silent: a new table quietly falling out of the export, and a new column quietly carrying a
 * credential into a file we hand to a customer.
 */
class DataExportTest extends TestCase
{
    use RefreshDatabase;

    private Organization $org;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');

        $this->org = Organization::factory()->create(['name' => 'Studio North']);
        $this->owner = User::factory()->for($this->org, 'organization')->role(Role::Owner)->create();
    }

    private function build(): DataExport
    {
        $export = DataExport::create([
            'org_id' => $this->org->id,
            'requested_by' => $this->owner->id,
            'status' => DataExportStatus::Pending,
        ]);

        app(BuildOrganizationDataExportJob::class, ['dataExportId' => $export->id])
            ->handle(app(OrganizationDataExport::class));

        return $export->fresh();
    }

    /**
     * @return array<string, string> file name => contents
     */
    private function archiveContents(DataExport $export): array
    {
        $bytes = Storage::disk((string) $export->disk)->get((string) $export->path);
        $local = tempnam(sys_get_temp_dir(), 'alima-test-');
        file_put_contents($local, $bytes);

        $zip = new ZipArchive;
        $this->assertTrue($zip->open($local) === true, 'The export is not a readable archive.');

        $files = [];

        for ($i = 0; $i < $zip->numFiles; $i++) {
            $name = (string) $zip->getNameIndex($i);
            $files[$name] = (string) $zip->getFromIndex($i);
        }

        $zip->close();
        @unlink($local);

        return $files;
    }

    // --- the archive ---------------------------------------------------------------------------

    public function test_it_writes_one_csv_per_set_plus_a_readme(): void
    {
        $export = $this->build();

        $this->assertSame(DataExportStatus::Ready, $export->status);

        $files = $this->archiveContents($export);

        $this->assertArrayHasKey('README.txt', $files);

        foreach (array_keys(OrganizationDataExport::SETS) as $table) {
            $this->assertArrayHasKey($table.'.csv', $files, "The archive has no [{$table}] set.");
        }
    }

    public function test_it_carries_the_organizations_own_records(): void
    {
        $client = Client::factory()->for($this->org, 'organization')->create(['name' => 'Acme Bakery']);
        Post::factory()->for($client)->create([
            'org_id' => $this->org->id,
            'status' => PostStatus::Approved,
            'master_caption' => 'Sourdough on Saturday',
        ]);

        $files = $this->archiveContents($this->build());

        $this->assertStringContainsString('Studio North', $files['organizations.csv']);
        $this->assertStringContainsString('Acme Bakery', $files['clients.csv']);
        $this->assertStringContainsString('Sourdough on Saturday', $files['posts.csv']);
    }

    /**
     * The one failure that would make this feature a breach rather than a feature.
     */
    public function test_another_organizations_records_are_never_in_the_archive(): void
    {
        $otherOrg = Organization::factory()->create(['name' => 'Rival Studio']);
        $theirClient = Client::factory()->for($otherOrg, 'organization')->create(['name' => 'Their Client']);
        Post::factory()->for($theirClient)->create([
            'org_id' => $otherOrg->id,
            'master_caption' => 'Their secret campaign',
        ]);
        User::factory()->for($otherOrg, 'organization')->create(['name' => 'Their Owner']);

        $files = $this->archiveContents($this->build());

        foreach ($files as $name => $contents) {
            $this->assertStringNotContainsString('Rival Studio', $contents, "[{$name}] leaked another org.");
            $this->assertStringNotContainsString('Their Client', $contents, "[{$name}] leaked another org's client.");
            $this->assertStringNotContainsString('Their secret campaign', $contents, "[{$name}] leaked another org's post.");
            $this->assertStringNotContainsString('Their Owner', $contents, "[{$name}] leaked another org's staff.");
        }
    }

    /**
     * A child set is reached through its parent, so it must be scoped by the parent's organisation
     * and not merely by existing.
     */
    public function test_a_child_set_follows_its_parents_organization(): void
    {
        $otherOrg = Organization::factory()->create();
        $theirClient = Client::factory()->for($otherOrg, 'organization')->create();
        $theirPost = Post::factory()->for($theirClient)->create(['org_id' => $otherOrg->id]);
        $theirAccount = SocialAccount::factory()->for($theirClient)->create(['org_id' => $otherOrg->id]);
        $theirPost->targets()->create([
            'social_account_id' => $theirAccount->id,
            'status' => PostTargetStatus::Pending,
            'external_post_id' => 'theirs-999',
        ]);

        $files = $this->archiveContents($this->build());

        $this->assertStringNotContainsString('theirs-999', $files['post_targets.csv']);
    }

    public function test_a_credential_is_never_written_into_the_archive(): void
    {
        $this->org->update([
            'upload_post_key' => 'up_live_SUPERSECRET',
            'xai_key' => 'xai_SUPERSECRET',
        ]);

        $files = $this->archiveContents($this->build());

        foreach ($files as $name => $contents) {
            $this->assertStringNotContainsString('SUPERSECRET', $contents, "[{$name}] carried a credential out.");
        }

        // The owner's password hash is a credential too, however much it is "their" row.
        $this->assertStringNotContainsString($this->owner->password, $files['users.csv']);
    }

    public function test_an_empty_set_still_has_its_header_so_none_reads_differently_to_missing(): void
    {
        $files = $this->archiveContents($this->build());

        $this->assertStringContainsString('master_caption', $files['posts.csv']);
        $this->assertSame(1, substr_count(trim($files['posts.csv']), "\n") + 1);
    }

    public function test_the_readme_says_what_is_not_in_there(): void
    {
        $readme = $this->archiveContents($this->build())['README.txt'];

        $this->assertStringContainsString('Media files', $readme);
        $this->assertStringContainsString('Credentials are blanked', $readme);
        $this->assertStringContainsString('Studio North', $readme);
    }

    public function test_the_requester_is_told_when_it_is_ready(): void
    {
        Notification::fake();

        $this->build();

        Notification::assertSentTo($this->owner, DataExportReady::class);
    }

    // --- drift guards --------------------------------------------------------------------------

    /**
     * A table that is neither exported nor excluded is a table that silently is not in anybody's
     * GDPR export. Forcing the decision here is the only way that stays true.
     */
    public function test_every_table_is_either_exported_or_excluded_with_a_reason(): void
    {
        $connection = DB::connection();
        $tables = $connection->getDriverName() === 'sqlite'
            ? Schema::getTableListing()
            : Schema::getTableListing($connection->getDatabaseName());

        $undecided = [];

        foreach ($tables as $table) {
            $table = str_contains($table, '.') ? explode('.', $table)[1] : $table;

            if (array_key_exists($table, OrganizationDataExport::SETS)
                || array_key_exists($table, OrganizationDataExport::EXCLUDED)) {
                continue;
            }

            $undecided[] = $table;
        }

        $this->assertSame([], $undecided, sprintf(
            'These tables are in neither OrganizationDataExport::SETS nor ::EXCLUDED: %s. '
            .'Export them, or exclude them with the reason.',
            implode(', ', $undecided),
        ));
    }

    /**
     * The archive leaves our control, so a column that authenticates has to be redacted. Column
     * names are checked rather than values: a new `*_token` column must be a decision, not a leak
     * discovered by a customer reading their own export.
     */
    public function test_every_credential_looking_column_in_an_exported_set_is_redacted(): void
    {
        $suspicious = '/(^|_)(password|secret|token|api_key|access_key)($|_)/i';
        $leaks = [];

        foreach (array_keys(OrganizationDataExport::SETS) as $table) {
            $redacted = OrganizationDataExport::REDACTED[$table] ?? [];

            foreach (Schema::getColumnListing($table) as $column) {
                if (preg_match($suspicious, $column) !== 1) {
                    continue;
                }

                if (in_array($column, $redacted, true)
                    || array_key_exists("{$table}.{$column}", OrganizationDataExport::NOT_CREDENTIALS)) {
                    continue;
                }

                $leaks[] = "{$table}.{$column}";
            }
        }

        $this->assertSame([], $leaks, sprintf(
            'These columns look like credentials and are exported: %s. Add them to '
            .'OrganizationDataExport::REDACTED, or to ::NOT_CREDENTIALS with the reason.',
            implode(', ', $leaks),
        ));
    }

    public function test_every_set_names_a_real_table_and_a_real_column(): void
    {
        foreach (OrganizationDataExport::SETS as $table => $set) {
            $this->assertTrue(Schema::hasTable($table), "[{$table}] is exported but does not exist.");

            if ($set['scope'] === 'org') {
                $this->assertTrue(Schema::hasColumn($table, 'org_id'), "[{$table}] is scoped on org_id, which it has not got.");
            }

            if ($set['scope'] === 'parent') {
                $this->assertArrayHasKey($set['parent'], OrganizationDataExport::SETS, "[{$table}] points at a parent that is not an exported set.");
                $this->assertTrue(Schema::hasColumn($table, $set['column']), "[{$table}] is scoped on {$set['column']}, which it has not got.");
            }
        }
    }

    public function test_a_redacted_column_actually_exists(): void
    {
        foreach (OrganizationDataExport::REDACTED as $table => $columns) {
            foreach ($columns as $column) {
                $this->assertTrue(
                    Schema::hasColumn($table, $column),
                    "REDACTED names {$table}.{$column}, which does not exist — a renamed column would be exported again.",
                );
            }
        }
    }

    // --- requesting, downloading and expiry ----------------------------------------------------

    public function test_an_owner_can_request_one_and_it_is_queued(): void
    {
        Queue::fake();

        $this->actingAs($this->owner)->post(route('data-export.store'))->assertRedirect();

        Queue::assertPushed(BuildOrganizationDataExportJob::class);
        $this->assertDatabaseHas('data_exports', [
            'org_id' => $this->org->id,
            'requested_by' => $this->owner->id,
            'status' => DataExportStatus::Pending->value,
        ]);
    }

    public function test_a_second_request_while_one_is_running_is_refused(): void
    {
        Queue::fake();

        $this->actingAs($this->owner)->post(route('data-export.store'));
        $this->actingAs($this->owner)->post(route('data-export.store'));

        Queue::assertPushed(BuildOrganizationDataExportJob::class, 1);
        $this->assertSame(1, DataExport::query()->count());
    }

    public function test_a_teammate_cannot_request_or_see_one(): void
    {
        $strategist = User::factory()->for($this->org, 'organization')->role(Role::Strategist)->create();

        $this->actingAs($strategist)->get(route('data-export.index'))->assertForbidden();
        $this->actingAs($strategist)->post(route('data-export.store'))->assertForbidden();
    }

    public function test_the_owner_sees_their_exports(): void
    {
        $export = $this->build();

        $this->actingAs($this->owner)->get(route('data-export.index'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('settings/data-export')
                ->where('exports.0.id', $export->id)
                ->where('exports.0.downloadable', true)
                ->where('retentionDays', (int) config('exports.retention_days')));
    }

    public function test_the_archive_downloads_as_a_zip(): void
    {
        $export = $this->build();

        $response = $this->confirmPassword()->actingAs($this->owner)
            ->get(route('data-export.download', $export));

        $response->assertOk();
        $this->assertStringStartsWith('application/zip', (string) $response->headers->get('content-type'));
        $this->assertStringContainsString('attachment', (string) $response->headers->get('content-disposition'));
    }

    public function test_the_download_asks_for_the_password_again(): void
    {
        $export = $this->build();

        $this->actingAs($this->owner)->get(route('data-export.download', $export))
            ->assertRedirect(route('password.confirm'));
    }

    public function test_another_organizations_export_cannot_be_downloaded(): void
    {
        $otherOrg = Organization::factory()->create();
        $theirs = DataExport::create([
            'org_id' => $otherOrg->id,
            'status' => DataExportStatus::Ready,
            'disk' => 'local',
            'path' => 'exports/'.$otherOrg->id.'/theirs.zip',
            'expires_at' => now()->addDay(),
        ]);

        $this->confirmPassword()->actingAs($this->owner)
            ->get(route('data-export.download', $theirs))
            ->assertForbidden();
    }

    public function test_an_expired_export_is_gone_rather_than_just_hidden(): void
    {
        $export = $this->build();
        $disk = (string) $export->disk;
        $path = (string) $export->path;

        Storage::disk($disk)->assertExists($path);

        $export->forceFill(['expires_at' => now()->subMinute()])->save();
        $this->artisan('exports:purge-expired')->assertSuccessful();

        Storage::disk($disk)->assertMissing($path);
        $fresh = $export->fresh();
        $this->assertSame(DataExportStatus::Expired, $fresh->status);
        $this->assertNull($fresh->path);

        $this->confirmPassword()->actingAs($this->owner)
            ->get(route('data-export.download', $fresh))
            ->assertStatus(410);
    }

    public function test_an_export_inside_its_window_is_left_alone(): void
    {
        $export = $this->build();

        $this->artisan('exports:purge-expired')->assertSuccessful();

        $this->assertSame(DataExportStatus::Ready, $export->fresh()->status);
        Storage::disk((string) $export->disk)->assertExists((string) $export->path);
    }

    public function test_a_failure_is_recorded_in_words_rather_than_disappearing(): void
    {
        $export = DataExport::create([
            'org_id' => $this->org->id,
            'requested_by' => $this->owner->id,
            'status' => DataExportStatus::Pending,
        ]);

        // The organisation was deleted between the request and the worker picking the job up. A
        // soft delete, because a hard one takes this row with it (and there is then nothing to
        // record a failure on).
        $this->org->delete();

        app(BuildOrganizationDataExportJob::class, ['dataExportId' => $export->id])
            ->handle(app(OrganizationDataExport::class));

        $fresh = DataExport::withoutGlobalScopes()->find($export->id);
        $this->assertSame(DataExportStatus::Failed, $fresh->status);
        $this->assertNotNull($fresh->failure_reason);
    }
}
