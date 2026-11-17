<?php

namespace Tests\Feature\Stories;

use App\Contracts\AssetStorage;
use App\Enums\DataExportStatus;
use App\Enums\Role;
use App\Jobs\BuildOrganizationDataExportJob;
use App\Jobs\PurgeOrganizationJob;
use App\Models\Client;
use App\Models\DataExport;
use App\Models\Organization;
use App\Models\StoryHandoff;
use App\Services\Security\OrganizationDataExport;
use App\Services\StoredFileInventory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\BuildsStoryHandoffs;
use Tests\TestCase;
use ZipArchive;

/**
 * Step S-6.4 — a Story handoff is the organization's own record: it leaves with the data export
 * (without the link's token hash) and goes when the organization is purged.
 */
class StoryHandoffDataLifecycleTest extends TestCase
{
    use BuildsStoryHandoffs, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
        $this->travelTo('2026-11-20 10:00:00');
        $this->setUpStoryHandoffs();
    }

    private function handoff(): StoryHandoff
    {
        $post = $this->storyPost();
        $this->refusingAdapter();

        return $this->openHandoff($this->startPublishing($post));
    }

    private function exportedHandoffsCsv(): string
    {
        $owner = $this->member(Role::Owner);
        $export = DataExport::create([
            'org_id' => $this->org->id,
            'requested_by' => $owner->id,
            'status' => DataExportStatus::Pending,
        ]);

        app(BuildOrganizationDataExportJob::class, ['dataExportId' => $export->id])
            ->handle(app(OrganizationDataExport::class));

        $export->refresh();
        $this->assertSame(DataExportStatus::Ready, $export->status);

        $local = (string) tempnam(sys_get_temp_dir(), 'alima-test-');
        file_put_contents($local, Storage::disk((string) $export->disk)->get((string) $export->path));

        $zip = new ZipArchive;
        $this->assertTrue($zip->open($local) === true);
        $csv = (string) $zip->getFromName('story_handoffs.csv');
        $zip->close();
        @unlink($local);

        return $csv;
    }

    public function test_the_export_carries_the_handoffs_without_the_token_hash(): void
    {
        $handoff = $this->handoff();
        $handoff->update(['problem_note' => 'The sticker would not stick']);
        $hash = (string) DB::table('story_handoffs')->where('id', $handoff->id)->value('token_hash');

        $csv = $this->exportedHandoffsCsv();
        $header = str_getcsv(strtok($csv, "\n") ?: '', escape: '\\');

        $this->assertSame(64, strlen($hash));
        $this->assertNotContains('token_hash', $header);
        $this->assertStringNotContainsString($hash, $csv);
        $this->assertContains('outcome', $header);
        $this->assertContains('due_at', $header);
        $this->assertStringContainsString('The sticker would not stick', $csv);
        $this->assertStringContainsString($handoff->publish_attempt_id, $csv);
    }

    public function test_another_organizations_handoffs_are_not_exported(): void
    {
        $handoff = $this->handoff();
        DB::table('story_handoffs')->where('id', $handoff->id)->update(['problem_note' => 'ours']);

        $other = Organization::factory()->create();
        $mine = $this->org;
        $this->org = $other;

        $this->assertStringNotContainsString('ours', $this->exportedHandoffsCsv());

        $this->org = $mine;
    }

    public function test_every_story_handoff_column_but_the_hash_is_exported(): void
    {
        $this->assertSame(['token_hash'], OrganizationDataExport::REDACTED['story_handoffs']);
        $this->assertContains('token_hash', Schema::getColumnListing('story_handoffs'));
        $this->assertArrayNotHasKey('token_hash', (new StoryHandoff(['token_hash' => 'x']))->toArray());
    }

    public function test_purging_the_organization_deletes_its_handoffs(): void
    {
        $handoff = $this->handoff();
        $otherOrg = Organization::factory()->create();
        $kept = StoryHandoff::factory()->create([
            'post_target_id' => $this->otherOrgsStoryTarget($otherOrg),
        ]);

        $this->org->delete();
        (new PurgeOrganizationJob($this->org->id))->handle(app(AssetStorage::class), app(StoredFileInventory::class));

        $this->assertDatabaseMissing('organizations', ['id' => $this->org->id]);
        $this->assertDatabaseMissing('story_handoffs', ['id' => $handoff->id]);
        $this->assertDatabaseHas('story_handoffs', ['id' => $kept->id]);
    }

    private function otherOrgsStoryTarget(Organization $otherOrg): int
    {
        $mine = [$this->org, $this->client];
        $this->org = $otherOrg;
        $this->client = Client::factory()->for($otherOrg, 'organization')->create();

        $targetId = $this->storyTarget($this->storyPost())->id;

        [$this->org, $this->client] = $mine;

        return $targetId;
    }
}
