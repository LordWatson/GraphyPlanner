<?php

namespace Tests\Feature\Campaigns;

use App\Enums\BriefStatus;
use App\Enums\Role;
use App\Mail\CampaignBriefSentMail;
use App\Models\Campaign;
use App\Models\CampaignBrief;
use App\Models\Client;
use App\Models\User;
use App\Notifications\CampaignBriefDecided;
use App\Notifications\CampaignBriefReady;
use App\Support\AI\ClientContextBuilder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Tests\Feature\Campaigns\Concerns\BuildsCampaigns;
use Tests\TestCase;

/**
 * Steps C-2.1 (structured brief) and C-2.3 (client brief approval).
 */
class CampaignBriefTest extends TestCase
{
    use BuildsCampaigns, RefreshDatabase;

    private Campaign $campaign;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpCampaignOrg();
        $this->campaign = $this->makeCampaign(['name' => 'Spring launch']);
    }

    /**
     * @return array<string, mixed>
     */
    private function content(): array
    {
        return [
            'background' => 'New spring range lands in March.',
            'audience' => 'Busy parents, 30–45.',
            'key_messages' => ['Fresh for spring.', '  ', 'Made to last.'],
            'call_to_action' => 'Shop the range',
            'avoid' => ['Discount talk'],
            'success_measures' => '40 posts out, 90% on time.',
        ];
    }

    private function sendBrief(): void
    {
        $this->actingAs($this->owner())->put(route('campaigns.brief.update', $this->campaign), $this->content());
        $this->actingAs($this->owner())->post(route('campaigns.brief.send', $this->campaign))->assertSessionHasNoErrors();
    }

    public function test_owners_and_strategists_write_the_brief_and_blank_lines_are_dropped(): void
    {
        $this->actingAs($this->strategist())
            ->put(route('campaigns.brief.update', $this->campaign), $this->content())
            ->assertSessionHasNoErrors();

        $brief = $this->campaign->fresh()->brief;

        $this->assertSame(BriefStatus::Draft, $brief->status);
        $this->assertSame(['Fresh for spring.', 'Made to last.'], $brief->key_messages);
        $this->assertDatabaseHas('campaign_activity_logs', ['campaign_id' => $this->campaign->id, 'event' => 'brief.updated']);
    }

    public function test_designers_viewers_and_other_orgs_cannot_write_the_brief(): void
    {
        foreach ([$this->designer(), $this->viewer(), $this->reviewer, $this->foreignOwner()] as $user) {
            // A client reviewer is redirected to their portal (302, Step M-6.2); the others are
            // refused by policy (403). None of them writes the brief.
            $status = $this->actingAs($user)->put(route('campaigns.brief.update', $this->campaign), $this->content())->getStatusCode();
            $this->assertContains($status, [302, 403]);
        }

        $this->assertDatabaseCount('campaign_briefs', 0);
    }

    public function test_editing_an_approved_brief_reopens_it_to_draft(): void
    {
        CampaignBrief::create([
            'campaign_id' => $this->campaign->id,
            'background' => 'Signed version.',
            'status' => BriefStatus::Approved,
            'approved_at' => now(),
            'approved_by' => $this->reviewer->id,
        ]);

        $this->actingAs($this->owner())->put(route('campaigns.brief.update', $this->campaign), ['background' => 'Changed after signing.']);

        $brief = $this->campaign->fresh()->brief;
        $this->assertSame(BriefStatus::Draft, $brief->status);
        $this->assertNull($brief->approved_at);
        $this->assertNull($brief->approved_by);
        $this->assertDatabaseHas('campaign_activity_logs', ['campaign_id' => $this->campaign->id, 'event' => 'brief.reopened', 'from' => 'approved', 'to' => 'draft']);
    }

    public function test_saving_an_approved_brief_unchanged_keeps_the_approval(): void
    {
        CampaignBrief::create(['campaign_id' => $this->campaign->id, 'background' => 'Same.', 'status' => BriefStatus::Approved, 'approved_at' => now()]);

        $this->actingAs($this->owner())->put(route('campaigns.brief.update', $this->campaign), ['background' => 'Same.']);

        $this->assertSame(BriefStatus::Approved, $this->campaign->fresh()->brief->status);
    }

    public function test_sending_snapshots_a_revision_and_emails_portal_contacts_after_commit(): void
    {
        Mail::fake();
        Notification::fake();

        $this->sendBrief();

        $brief = $this->campaign->fresh()->brief;
        $revision = $brief->revisions()->firstOrFail();

        $this->assertSame(BriefStatus::Sent, $brief->status);
        $this->assertSame(1, $revision->number);
        $this->assertSame(CampaignBrief::hashContent($brief->content()), $revision->content_hash);
        $this->assertSame($brief->content(), $revision->content);
        Mail::assertQueued(CampaignBriefSentMail::class, fn ($mail) => $mail->hasTo($this->reviewer->email));
        Notification::assertSentTo($this->reviewer, CampaignBriefReady::class);
        $this->assertDatabaseHas('campaign_activity_logs', ['campaign_id' => $this->campaign->id, 'event' => 'brief.sent']);
    }

    public function test_an_empty_brief_cannot_be_sent(): void
    {
        $this->actingAs($this->owner())->post(route('campaigns.brief.send', $this->campaign))->assertSessionHasErrors('brief');
    }

    public function test_the_client_approves_the_sent_revision(): void
    {
        Mail::fake();
        Notification::fake();
        $this->sendBrief();
        $revision = $this->campaign->fresh()->brief->revisions()->firstOrFail();

        $this->actingAs($this->reviewer)->post(route('portal.campaigns.brief.decide', $this->campaign), [
            'revision_id' => $revision->id,
            'content_hash' => $revision->content_hash,
            'decision' => 'approved',
        ])->assertSessionHasNoErrors();

        $brief = $this->campaign->fresh()->brief;
        $this->assertSame(BriefStatus::Approved, $brief->status);
        $this->assertSame($this->reviewer->id, $brief->approved_by);
        $this->assertSame($this->reviewer->id, $revision->fresh()->decided_by);
        Notification::assertSentTo([$this->owner(), $this->strategist()], CampaignBriefDecided::class);
        Notification::assertNotSentTo($this->designer(), CampaignBriefDecided::class);
    }

    public function test_asking_for_changes_needs_a_comment(): void
    {
        Mail::fake();
        $this->sendBrief();
        $revision = $this->campaign->fresh()->brief->revisions()->firstOrFail();

        $payload = ['revision_id' => $revision->id, 'content_hash' => $revision->content_hash, 'decision' => 'changes_requested'];

        $this->actingAs($this->reviewer)->post(route('portal.campaigns.brief.decide', $this->campaign), $payload)->assertSessionHasErrors('comment');

        $this->actingAs($this->reviewer)->post(route('portal.campaigns.brief.decide', $this->campaign), [...$payload, 'comment' => 'Lead with the new colours.'])->assertSessionHasNoErrors();
        $this->assertSame(BriefStatus::ChangesRequested, $this->campaign->fresh()->brief->status);
    }

    public function test_a_decision_on_a_stale_revision_is_rejected(): void
    {
        Mail::fake();
        $this->sendBrief();
        $first = $this->campaign->fresh()->brief->revisions()->firstOrFail();

        // The studio edits and re-sends before the client decides.
        $this->actingAs($this->owner())->put(route('campaigns.brief.update', $this->campaign), [...$this->content(), 'background' => 'Updated context.']);
        $this->actingAs($this->owner())->post(route('campaigns.brief.send', $this->campaign));

        $this->actingAs($this->reviewer)->post(route('portal.campaigns.brief.decide', $this->campaign), [
            'revision_id' => $first->id,
            'content_hash' => $first->content_hash,
            'decision' => 'approved',
        ])->assertSessionHasErrors('revision_id');

        $this->assertSame(BriefStatus::Sent, $this->campaign->fresh()->brief->status);
        $this->assertNull($first->fresh()->decision);
    }

    public function test_a_tampered_hash_is_rejected(): void
    {
        Mail::fake();
        $this->sendBrief();
        $revision = $this->campaign->fresh()->brief->revisions()->firstOrFail();

        $this->actingAs($this->reviewer)->post(route('portal.campaigns.brief.decide', $this->campaign), [
            'revision_id' => $revision->id,
            'content_hash' => str_repeat('a', 64),
            'decision' => 'approved',
        ])->assertSessionHasErrors('revision_id');
    }

    public function test_a_reviewer_cannot_decide_another_clients_brief(): void
    {
        Mail::fake();
        $this->sendBrief();
        $revision = $this->campaign->fresh()->brief->revisions()->firstOrFail();
        $otherClient = Client::factory()->for($this->org, 'organization')->create();
        $stranger = User::factory()->for($this->org, 'organization')->role(Role::ClientReviewer)->create(['client_id' => $otherClient->id]);

        $this->actingAs($stranger)->post(route('portal.campaigns.brief.decide', $this->campaign), [
            'revision_id' => $revision->id,
            'content_hash' => $revision->content_hash,
            'decision' => 'approved',
        ])->assertNotFound();

        $this->actingAs($this->owner())->post(route('portal.campaigns.brief.decide', $this->campaign), [
            'revision_id' => $revision->id,
            'content_hash' => $revision->content_hash,
            'decision' => 'approved',
        ])->assertForbidden();
    }

    public function test_the_pdf_carries_the_approval_stamp_and_hash(): void
    {
        Mail::fake();
        $this->sendBrief();
        $revision = $this->campaign->fresh()->brief->revisions()->firstOrFail();
        $this->actingAs($this->reviewer)->post(route('portal.campaigns.brief.decide', $this->campaign), [
            'revision_id' => $revision->id,
            'content_hash' => $revision->content_hash,
            'decision' => 'approved',
        ]);

        $html = view('pdf.campaign-brief', [
            'campaign' => $this->campaign->fresh(),
            'client' => $this->client,
            'organization' => $this->org,
            'content' => $revision->content,
            'approved' => $revision->fresh()->load('decider'),
            'status' => BriefStatus::Approved,
            'timezone' => 'UTC',
            'generatedAt' => now(),
        ])->render();

        $this->assertStringContainsString('Approved by '.$this->reviewer->name, $html);
        $this->assertStringContainsString('revision 1', $html);
        $this->assertStringContainsString($revision->content_hash, $html);

        $this->actingAs($this->owner())->get(route('campaigns.brief.pdf', $this->campaign))
            ->assertOk()
            ->assertHeader('Content-Type', 'application/pdf');
        $this->actingAs($this->reviewer)->get(route('portal.campaigns.brief.pdf', $this->campaign))->assertOk();
    }

    public function test_the_portal_never_sees_a_never_sent_draft(): void
    {
        $this->actingAs($this->owner())->put(route('campaigns.brief.update', $this->campaign), $this->content());

        $this->actingAs($this->reviewer)->get(route('portal.campaigns.brief.pdf', $this->campaign))->assertNotFound();
    }

    public function test_ai_context_includes_the_brief_only_for_campaign_posts(): void
    {
        $this->actingAs($this->owner())->put(route('campaigns.brief.update', $this->campaign), $this->content());

        $builder = app(ClientContextBuilder::class);

        $this->assertStringContainsString('New spring range lands in March.', $builder->describeCampaignBrief($this->campaign->fresh()));
        $this->assertSame('', $builder->describeCampaignBrief(null));
    }
}
