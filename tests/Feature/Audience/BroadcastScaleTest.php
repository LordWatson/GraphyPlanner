<?php

namespace Tests\Feature\Audience;

use App\Actions\Audience\SendAudienceBroadcastAction;
use App\Enums\AudienceSource;
use App\Enums\OrganizationType;
use App\Enums\Role;
use App\Jobs\SendAudienceBroadcastJob;
use App\Models\AudienceBroadcast;
use App\Models\AudienceContact;
use App\Models\Client;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Mail\Transport\ArrayTransport;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/**
 * A broadcast is handed to one job, not sent in a loop inside the web request.
 *
 * The old shape pushed one queued mail per contact from the controller: a 5,000-contact list was
 * 5,000 sequential writes before the response could return, and a bigger one hit
 * `max_execution_time` part-way through leaving no record of how far it got.
 */
class BroadcastScaleTest extends TestCase
{
    use RefreshDatabase;

    private Organization $org;

    private Client $brand;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();

        $this->org = Organization::factory()->create([
            'type' => OrganizationType::Business,
            'plan' => 'creator_pro',
        ]);
        $this->brand = Client::factory()->for($this->org, 'organization')->primary()->create();
        $this->owner = User::factory()->for($this->org, 'organization')->role(Role::Owner)->create();
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function contact(string $email, array $attributes = []): AudienceContact
    {
        return AudienceContact::create([
            'org_id' => $this->org->id,
            'client_id' => $this->brand->id,
            'email' => $email,
            'source' => AudienceSource::BioCapture,
            'consent_at' => now(),
            ...$attributes,
        ]);
    }

    private function contacts(int $count): void
    {
        for ($i = 0; $i < $count; $i++) {
            $this->contact("fan{$i}@example.test");
        }
    }

    private function send(): AudienceBroadcast
    {
        return app(SendAudienceBroadcastAction::class)($this->brand, $this->owner, [
            'subject' => 'News',
            'body' => 'Hello',
            'segment' => AudienceBroadcast::SEGMENT_ALL,
        ]);
    }

    /**
     * A committed broadcast with no job dispatched.
     *
     * The test queue is `sync`, so going through the Action would run the job inline and the
     * broadcast would already be sent before a test about resuming could set up its state.
     *
     * @param  array<string, mixed>  $attributes
     */
    private function makeBroadcast(array $attributes = []): AudienceBroadcast
    {
        return AudienceBroadcast::create([
            'org_id' => $this->org->id,
            'client_id' => $this->brand->id,
            'created_by' => $this->owner->id,
            'subject' => 'News',
            'body' => 'Hello',
            'segment' => AudienceBroadcast::SEGMENT_ALL,
            'sent_at' => now(),
            'recipients' => 10,
            ...$attributes,
        ]);
    }

    private function outbox(): int
    {
        /** @var ArrayTransport $transport */
        $transport = Mail::mailer()->getSymfonyTransport();

        return $transport->messages()->count();
    }

    public function test_the_request_dispatches_one_job_whatever_the_list_size(): void
    {
        Queue::fake();
        $this->contacts(120);

        $broadcast = $this->send();

        // One job — not 120 queued mails pushed from the request.
        Queue::assertPushed(SendAudienceBroadcastJob::class, 1);
        $this->assertSame(120, $broadcast->recipients);
        $this->assertNull($broadcast->finished_at);
    }

    public function test_the_job_sends_to_everyone_and_records_that_it_finished(): void
    {
        $this->contacts(12);

        $broadcast = $this->send();
        (new SendAudienceBroadcastJob($broadcast->id))->handle();

        $broadcast->refresh();
        $this->assertSame(12, $broadcast->sent_count);
        $this->assertSame(0, $broadcast->failed_count);
        $this->assertNotNull($broadcast->finished_at);
        $this->assertSame(12, $this->outbox());
    }

    public function test_an_unconfirmed_or_unsubscribed_contact_is_never_sent_to(): void
    {
        $this->contacts(3);
        $this->contact('unconfirmed@example.test', ['consent_at' => null]);
        $this->contact('gone@example.test', ['unsubscribed_at' => now()]);

        $broadcast = $this->send();
        (new SendAudienceBroadcastJob($broadcast->id))->handle();

        // Consent is re-read at send time, not frozen when the owner pressed the button.
        $this->assertSame(3, $broadcast->refresh()->sent_count);
        $this->assertSame(3, $this->outbox());
    }

    /**
     * The point of recording a resume marker: a job killed part-way through must not start the
     * list again and send the first contacts twice.
     */
    public function test_a_retry_resumes_rather_than_sending_the_first_contacts_twice(): void
    {
        $this->contacts(10);
        $ids = AudienceContact::query()->orderBy('id')->pluck('id');

        // What an interrupted first attempt leaves behind: four done, a marker, not finished.
        $broadcast = $this->makeBroadcast([
            'sent_count' => 4,
            'last_contact_id' => $ids[3],
            'finished_at' => null,
        ]);

        (new SendAudienceBroadcastJob($broadcast->id))->handle();

        $broadcast->refresh();
        $this->assertSame(10, $broadcast->sent_count, 'The count should reach the full list, not 14.');
        $this->assertSame(6, $this->outbox(), 'Only the six that had not been sent should go out.');
        $this->assertNotNull($broadcast->finished_at);
    }

    public function test_a_finished_broadcast_is_never_sent_again(): void
    {
        $this->contacts(5);
        $broadcast = $this->makeBroadcast(['finished_at' => now(), 'sent_count' => 5]);

        (new SendAudienceBroadcastJob($broadcast->id))->handle();

        $this->assertSame(0, $this->outbox());
    }

    public function test_the_job_is_unique_per_broadcast(): void
    {
        // A double dispatch cannot send a broadcast twice.
        $this->assertSame('7', (new SendAudienceBroadcastJob(7))->uniqueId());
    }

    public function test_an_empty_segment_is_refused_before_anything_is_recorded(): void
    {
        Queue::fake();

        try {
            $this->send();
            $this->fail('An empty segment should be refused.');
        } catch (ValidationException) {
            // The owner is told, rather than a broadcast of nothing being recorded.
        }

        $this->assertSame(0, AudienceBroadcast::query()->withoutOrgScope()->count());
        Queue::assertNothingPushed();
    }
}
