<?php

namespace Tests\Feature\Storage;

use App\Console\Commands\MigrateStoredFilesDiskCommand;
use App\Models\Asset;
use App\Models\Client;
use App\Models\Organization;
use App\Providers\ObservabilityServiceProvider;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * `storage:migrate-disk` — the one-off that has to run when `ASSETS_DISK` changes, plus the `/up`
 * check that refuses a half-finished switch.
 *
 * The test that matters most here is the last one: it reads the schema and fails if any table
 * stores a file the command does not know how to move. Getting that list wrong is silent — the
 * files simply stay on a disk the next web node does not have — so it is checked rather than
 * reviewed.
 */
class StoredFilesDiskMigrationTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Two real local disks rather than `Storage::fake()`: the fake supplies its own disk config
     * (including visibility and url), and this test is specifically about what the command does
     * with those two settings.
     */
    protected function setUp(): void
    {
        parent::setUp();

        foreach (['src', 'dst'] as $disk) {
            $this->defineDisk($disk);
        }
    }

    protected function tearDown(): void
    {
        foreach (['src', 'dst'] as $disk) {
            File::deleteDirectory(storage_path("framework/testing/disks/{$disk}"));
        }

        parent::tearDown();
    }

    /**
     * @param  array<string, mixed>  $extra
     */
    private function defineDisk(string $disk, array $extra = []): void
    {
        $root = storage_path("framework/testing/disks/{$disk}");
        File::deleteDirectory($root);
        File::ensureDirectoryExists($root);

        // No `visibility` key, so `LocalAssetStorage::isPublicDisk()` reads it as private — which
        // is what both the current `local` disk and an `s3` one are. `serve` mirrors the real
        // `local` disk, so signed URLs can be minted (`Asset::url` does that on every read).
        config(["filesystems.disks.{$disk}" => [
            'driver' => 'local',
            'root' => $root,
            'url' => rtrim((string) config('app.url'), '/')."/files-{$disk}",
            'serve' => true,
            'throw' => false,
            ...$extra,
        ]]);
        Storage::forgetDisk($disk);
    }

    /**
     * The `url` *column*. `Asset::url` is an accessor that re-signs a private disk's URL on every
     * read, so reading the model would never show what is actually stored.
     */
    private function storedUrl(int $assetId): ?string
    {
        $value = DB::table('assets')->where('id', $assetId)->value('url');

        return $value === null ? null : (string) $value;
    }

    private function client(): Client
    {
        $org = Organization::factory()->create();

        return Client::factory()->for($org, 'organization')->create();
    }

    public function test_it_copies_every_path_on_a_row_and_repoints_it(): void
    {
        $client = $this->client();

        Storage::disk('src')->put('assets/hero.jpg', 'original bytes');
        Storage::disk('src')->put('assets/hero-thumb.jpg', 'thumb bytes');

        $asset = Asset::factory()->for($client)->create([
            'disk' => 'src',
            'path' => 'assets/hero.jpg',
            'thumbnail_path' => 'assets/hero-thumb.jpg',
            // What a private disk actually leaves behind: a signed URL that expired long ago.
            'url' => 'http://localhost/files/assets/hero.jpg?signature=stale',
        ]);

        $this->artisan('storage:migrate-disk', ['--from' => 'src', '--to' => 'dst'])->assertSuccessful();

        // Both paths moved — the thumbnail shares the one disk column, so missing it would leave
        // the row pointing at a thumbnail that is not on the new disk.
        $this->assertSame('original bytes', Storage::disk('dst')->get('assets/hero.jpg'));
        $this->assertSame('thumb bytes', Storage::disk('dst')->get('assets/hero-thumb.jpg'));

        $this->assertSame('dst', $asset->refresh()->disk);
        // `dst` is private, so `StoredFileUrl` re-signs on every read and the stored URL is dead
        // weight. Keeping the old one would leave a link to a file on the disk we just left.
        $this->assertNull($this->storedUrl($asset->id));

        // Nothing is deleted, so the move can be re-run or rolled back.
        $this->assertTrue(Storage::disk('src')->exists('assets/hero.jpg'));
    }

    public function test_it_moves_a_table_that_is_not_scoped_to_a_client(): void
    {
        // The white-label logo hangs off `organizations`, not a client, so a client-shaped sweep
        // misses it. It is a file on a disk like any other.
        $org = Organization::factory()->create();
        Storage::disk('src')->put('branding/logo.png', 'logo bytes');
        DB::table('organizations')->where('id', $org->id)
            ->update(['brand_logo_disk' => 'src', 'brand_logo_path' => 'branding/logo.png']);

        $this->artisan('storage:migrate-disk', ['--from' => 'src', '--to' => 'dst'])->assertSuccessful();

        $this->assertSame('logo bytes', Storage::disk('dst')->get('branding/logo.png'));
        $this->assertSame('dst', DB::table('organizations')->where('id', $org->id)->value('brand_logo_disk'));
    }

    public function test_a_public_target_disk_keeps_a_usable_stored_url(): void
    {
        $client = $this->client();
        $this->defineDisk('dst', ['visibility' => 'public', 'url' => 'https://cdn.test']);

        Storage::disk('src')->put('assets/hero.jpg', 'bytes');
        $asset = Asset::factory()->for($client)->create([
            'disk' => 'src', 'path' => 'assets/hero.jpg', 'thumbnail_path' => null, 'url' => 'http://old',
        ]);

        $this->artisan('storage:migrate-disk', ['--from' => 'src', '--to' => 'dst'])->assertSuccessful();

        // A public disk is the one case where the stored URL is what gets served, so it is rewritten.
        $this->assertSame('https://cdn.test/assets/hero.jpg', $this->storedUrl($asset->id));
    }

    public function test_it_is_safe_to_run_twice(): void
    {
        $client = $this->client();
        Storage::disk('src')->put('assets/hero.jpg', 'bytes');
        Asset::factory()->for($client)->create([
            'disk' => 'src', 'path' => 'assets/hero.jpg', 'thumbnail_path' => null,
        ]);

        $this->artisan('storage:migrate-disk', ['--from' => 'src', '--to' => 'dst'])->assertSuccessful();
        // Second pass: the row is already on `dst`, so it is not even selected.
        $this->artisan('storage:migrate-disk', ['--from' => 'src', '--to' => 'dst'])
            ->expectsOutputToContain('moved 0')
            ->assertSuccessful();
    }

    public function test_a_dry_run_changes_nothing(): void
    {
        $client = $this->client();
        Storage::disk('src')->put('assets/hero.jpg', 'bytes');
        $asset = Asset::factory()->for($client)->create([
            'disk' => 'src', 'path' => 'assets/hero.jpg', 'thumbnail_path' => null,
        ]);

        $this->artisan('storage:migrate-disk', ['--from' => 'src', '--to' => 'dst', '--dry-run' => true])
            ->assertSuccessful();

        $this->assertFalse(Storage::disk('dst')->exists('assets/hero.jpg'));
        $this->assertSame('src', $asset->refresh()->disk);
    }

    public function test_a_missing_source_file_is_counted_and_the_run_continues(): void
    {
        $client = $this->client();

        // Row says `src`, but the file is gone — the realistic state of an old library.
        Asset::factory()->for($client)->create([
            'disk' => 'src', 'path' => 'assets/gone.jpg', 'thumbnail_path' => null,
        ]);
        Storage::disk('src')->put('assets/here.jpg', 'bytes');
        $good = Asset::factory()->for($client)->create([
            'disk' => 'src', 'path' => 'assets/here.jpg', 'thumbnail_path' => null,
        ]);

        // Non-zero exit, because a half-moved library must not look like a clean run.
        $this->artisan('storage:migrate-disk', ['--from' => 'src', '--to' => 'dst'])->assertFailed();

        // The healthy row still moved.
        $this->assertSame('dst', $good->refresh()->disk);
        $this->assertSame('bytes', Storage::disk('dst')->get('assets/here.jpg'));
    }

    public function test_it_refuses_disks_that_make_no_sense(): void
    {
        $this->artisan('storage:migrate-disk', ['--from' => 'src', '--to' => 'src'])->assertFailed();
        $this->artisan('storage:migrate-disk', ['--from' => 'nope', '--to' => 'dst'])->assertFailed();

        // An s3 target with no bucket would write nothing and report success.
        config(['filesystems.disks.dst.driver' => 's3', 'filesystems.disks.dst.bucket' => null]);
        $this->artisan('storage:migrate-disk', ['--from' => 'src', '--to' => 'dst'])->assertFailed();
    }

    // --- the /up check ------------------------------------------------------------------------

    public function test_the_health_endpoint_fails_while_the_asset_disk_is_misconfigured(): void
    {
        $this->get('/up')->assertOk();

        config([
            'assets.disk' => 's3',
            'filesystems.disks.s3.bucket' => null,
            'filesystems.disks.s3.key' => null,
            'filesystems.disks.s3.secret' => null,
        ]);

        $reason = ObservabilityServiceProvider::assetDiskMisconfiguration();
        $this->assertNotNull($reason);
        $this->assertStringContainsString('bucket', (string) $reason);

        // The load balancer should see this, not the first person to upload a file.
        $this->get('/up')->assertStatus(500);
    }

    public function test_the_health_check_passes_for_a_fully_configured_s3_disk(): void
    {
        config([
            'assets.disk' => 's3',
            'filesystems.disks.s3.bucket' => 'alima-media',
            'filesystems.disks.s3.key' => 'AKIA',
            'filesystems.disks.s3.secret' => 'shh',
        ]);

        $this->assertNull(ObservabilityServiceProvider::assetDiskMisconfiguration());
        $this->get('/up')->assertOk();
    }

    public function test_an_unknown_asset_disk_is_reported(): void
    {
        config(['assets.disk' => 'not-a-disk']);

        $this->assertStringContainsString(
            'not a disk',
            (string) ObservabilityServiceProvider::assetDiskMisconfiguration(),
        );
    }

    // --- the drift guard ---------------------------------------------------------------------

    /**
     * Every column in the schema that names a filesystem disk must be one the command migrates.
     * Add a table that stores a file and forget this list, and its files quietly stay behind on a
     * disk the next web node does not have.
     */
    public function test_the_command_covers_every_disk_column_in_the_schema(): void
    {
        $inSchema = [];

        foreach ($this->tablesOnThisConnection() as $name) {
            foreach (Schema::getColumnListing($name) as $column) {
                if ($column === 'disk' || str_ends_with($column, '_disk')) {
                    $inSchema[] = "{$name}.{$column}";
                }
            }
        }

        $covered = array_map(
            fn (array $target) => $target['table'].'.'.$target['disk'],
            $this->commandTargets(),
        );

        sort($inSchema);
        sort($covered);

        $this->assertSame(
            $inSchema,
            $covered,
            'storage:migrate-disk must list every table that stores a file. Update its TARGETS.',
        );
    }

    /**
     * Each path and url column the command names must exist too, so a renamed column fails here
     * rather than at 3am during the switch.
     */
    public function test_every_column_the_command_names_exists(): void
    {
        foreach ($this->commandTargets() as $target) {
            $columns = Schema::getColumnListing($target['table']);

            foreach (array_merge([$target['disk']], $target['paths'], array_values($target['urls'])) as $column) {
                $this->assertContains($column, $columns, "{$target['table']}.{$column} does not exist.");
            }

            foreach (array_keys($target['urls']) as $pathColumn) {
                $this->assertContains($pathColumn, $target['paths'], "{$target['table']}: {$pathColumn} has a url mapping but is not a path.");
            }
        }
    }

    /**
     * The tables of *this* database, and nothing else.
     *
     * `Schema::getTables()` reads `information_schema` on MySQL, which is server-wide: it returned
     * 388 tables across eight databases on the development machine — other projects, `sakila`,
     * `world` — so the unscoped call made this test compare the command against every disk column
     * on the whole server. SQLite has a single schema and takes no argument.
     *
     * @return list<string>
     */
    private function tablesOnThisConnection(): array
    {
        $connection = DB::connection();

        $tables = $connection->getDriverName() === 'sqlite'
            ? Schema::getTables()
            : Schema::getTables($connection->getDatabaseName());

        return array_values(array_column($tables, 'name'));
    }

    /**
     * @return list<array{table: string, disk: string, paths: list<string>, urls: array<string, string>}>
     */
    private function commandTargets(): array
    {
        $reflection = new \ReflectionClass(MigrateStoredFilesDiskCommand::class);

        /** @var list<array{table: string, disk: string, paths: list<string>, urls: array<string, string>}> $targets */
        $targets = $reflection->getConstant('TARGETS');

        return $targets;
    }
}
