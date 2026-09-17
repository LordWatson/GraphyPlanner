<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Models\BrandBrain;
use App\Models\Client;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class BrandBrainControllerTest extends TestCase
{
    use RefreshDatabase;

    public function test_owner_can_view_the_brand_brain_editor(): void
    {
        $org = Organization::factory()->create();
        $owner = User::factory()->for($org, 'organization')->role(Role::Owner)->create();
        $client = Client::factory()->for($org, 'organization')->create();

        $response = $this->actingAs($owner)->get(route('clients.brand-brain.edit', $client));

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page->where('can.update', true));
    }

    public function test_designer_can_view_but_not_update_the_brand_brain(): void
    {
        $org = Organization::factory()->create();
        $designer = User::factory()->for($org, 'organization')->role(Role::Designer)->create();
        $client = Client::factory()->for($org, 'organization')->create();

        $response = $this->actingAs($designer)->get(route('clients.brand-brain.edit', $client));
        $response->assertOk();
        $response->assertInertia(fn ($page) => $page->where('can.update', false));

        $response = $this->actingAs($designer)->put(route('clients.brand-brain.update', $client), [
            'voice' => ['tone' => 'New tone'],
        ]);
        $response->assertForbidden();
    }

    public function test_owner_can_create_a_brand_brain_through_the_ui(): void
    {
        $org = Organization::factory()->create();
        $owner = User::factory()->for($org, 'organization')->role(Role::Owner)->create();
        $client = Client::factory()->for($org, 'organization')->create();

        $response = $this->actingAs($owner)->put(route('clients.brand-brain.update', $client), [
            'voice' => [
                'tone' => 'Confident',
                'personality' => 'Expert',
                'do_nots' => "No slang\nNo swearing",
            ],
            'content_pillars' => "Education\nBehind the scenes",
        ]);

        $response->assertRedirect(route('clients.brand-brain.edit', $client));

        $brandBrain = BrandBrain::firstWhere('client_id', $client->id);
        $this->assertNotNull($brandBrain);
        $this->assertSame('Confident', $brandBrain->voice['tone']);
        $this->assertSame(['No slang', 'No swearing'], $brandBrain->voice['do_nots']);
        $this->assertSame(['Education', 'Behind the scenes'], $brandBrain->content_pillars);
    }

    public function test_owner_can_update_an_existing_brand_brain(): void
    {
        $org = Organization::factory()->create();
        $owner = User::factory()->for($org, 'organization')->role(Role::Owner)->create();
        $client = Client::factory()->for($org, 'organization')->create();
        BrandBrain::factory()->for($client)->create([
            'voice' => ['tone' => 'Old tone'],
        ]);

        $response = $this->actingAs($owner)->put(route('clients.brand-brain.update', $client), [
            'voice' => ['tone' => 'Updated tone'],
        ]);

        $response->assertRedirect(route('clients.brand-brain.edit', $client));
        $this->assertSame(1, BrandBrain::where('client_id', $client->id)->count());
        $this->assertSame('Updated tone', $client->brandBrain->fresh()->voice['tone']);
    }

    public function test_client_reviewer_can_view_their_own_clients_brand_brain_but_not_update_it(): void
    {
        $org = Organization::factory()->create();
        $client = Client::factory()->for($org, 'organization')->create();
        $reviewer = User::factory()->for($org, 'organization')->role(Role::ClientReviewer)->create([
            'client_id' => $client->id,
        ]);

        $response = $this->actingAs($reviewer)->get(route('clients.brand-brain.edit', $client));
        $response->assertOk();

        $response = $this->actingAs($reviewer)->put(route('clients.brand-brain.update', $client), [
            'voice' => ['tone' => 'Hijacked'],
        ]);
        $response->assertForbidden();
    }

    public function test_client_reviewer_cannot_view_another_clients_brand_brain(): void
    {
        $org = Organization::factory()->create();
        $client = Client::factory()->for($org, 'organization')->create();
        $otherClient = Client::factory()->for($org, 'organization')->create();
        $reviewer = User::factory()->for($org, 'organization')->role(Role::ClientReviewer)->create([
            'client_id' => $client->id,
        ]);

        $response = $this->actingAs($reviewer)->get(route('clients.brand-brain.edit', $otherClient));

        $response->assertForbidden();
    }

    public function test_owner_can_upload_a_brand_persona_pdf(): void
    {
        Storage::fake('public');

        $org = Organization::factory()->create();
        $owner = User::factory()->for($org, 'organization')->role(Role::Owner)->create();
        $client = Client::factory()->for($org, 'organization')->create();

        $file = UploadedFile::fake()->create('persona.pdf', 100, 'application/pdf');

        $response = $this->actingAs($owner)->post(route('clients.brand-brain.persona.store', $client), [
            'persona' => $file,
        ]);

        $response->assertRedirect(route('clients.brand-brain.edit', $client));

        $brandBrain = BrandBrain::firstWhere('client_id', $client->id);
        $this->assertNotNull($brandBrain);
        $this->assertSame('persona.pdf', $brandBrain->persona_original_filename);
        $this->assertNotNull($brandBrain->persona_path);
        Storage::disk('public')->assertExists($brandBrain->persona_path);
    }

    public function test_uploading_a_new_brand_persona_replaces_the_old_file(): void
    {
        Storage::fake('public');

        $org = Organization::factory()->create();
        $owner = User::factory()->for($org, 'organization')->role(Role::Owner)->create();
        $client = Client::factory()->for($org, 'organization')->create();

        $this->actingAs($owner)->post(route('clients.brand-brain.persona.store', $client), [
            'persona' => UploadedFile::fake()->create('first.pdf', 100, 'application/pdf'),
        ]);

        $brandBrain = BrandBrain::firstWhere('client_id', $client->id);
        $oldPath = $brandBrain->persona_path;
        Storage::disk('public')->assertExists($oldPath);

        $this->actingAs($owner)->post(route('clients.brand-brain.persona.store', $client), [
            'persona' => UploadedFile::fake()->create('second.pdf', 100, 'application/pdf'),
        ]);

        Storage::disk('public')->assertMissing($oldPath);
        $this->assertSame('second.pdf', $brandBrain->fresh()->persona_original_filename);
    }

    public function test_designer_cannot_upload_a_brand_persona_pdf(): void
    {
        Storage::fake('public');

        $org = Organization::factory()->create();
        $designer = User::factory()->for($org, 'organization')->role(Role::Designer)->create();
        $client = Client::factory()->for($org, 'organization')->create();

        $response = $this->actingAs($designer)->post(route('clients.brand-brain.persona.store', $client), [
            'persona' => UploadedFile::fake()->create('persona.pdf', 100, 'application/pdf'),
        ]);

        $response->assertForbidden();
    }

    public function test_brand_persona_upload_rejects_non_pdf_files(): void
    {
        Storage::fake('public');

        $org = Organization::factory()->create();
        $owner = User::factory()->for($org, 'organization')->role(Role::Owner)->create();
        $client = Client::factory()->for($org, 'organization')->create();

        $response = $this->actingAs($owner)->post(route('clients.brand-brain.persona.store', $client), [
            'persona' => UploadedFile::fake()->create('persona.png', 100, 'image/png'),
        ]);

        $response->assertSessionHasErrors('persona');
    }
}
