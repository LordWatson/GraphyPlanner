<?php

namespace Tests\Feature\Stories;

use App\Actions\Stories\ProcessStoryHandoffsAction;
use App\Enums\AssetSource;
use App\Enums\AssetType;
use App\Enums\PostStatus;
use App\Enums\PostTargetStatus;
use App\Enums\PublishFailureReason;
use App\Enums\Role;
use App\Enums\StoryHandoffOutcome;
use App\Models\Asset;
use App\Models\Client;
use App\Models\Organization;
use App\Models\Post;
use App\Models\Scopes\TenantScopes;
use App\Models\StoryHandoff;
use App\Models\User;
use App\Notifications\PostPublishFailed;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\Concerns\BuildsStoryHandoffs;
use Tests\TestCase;

/**
 * The handoff link is the authorisation, so it must do exactly the same thing whoever's browser
 * opens it. Regression for the audit's top finding: a member signed in to ANOTHER organization
 * (or one restricted to other clients) holding the link had the access scopes applied to the
 * pipeline behind it — the channel published but its post never resolved, a failure was never
 * notified, and the page had no media. The token routes now run with the scopes suspended
 * (`TenantScopes::suspended()`).
 */
class StoryHandoffTenantScopeTest extends TestCase
{
    use BuildsStoryHandoffs, RefreshDatabase;

    private User $owner;

    private User $outsider;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo('2026-11-20 10:00:00');
        $this->setUpStoryHandoffs();
        Notification::fake();
        Storage::fake('local');
        $this->withoutVite();

        $this->owner = $this->member(Role::Owner);
        $this->outsider = $this->member(Role::Owner, Organization::factory()->create());
    }

    /**
     * A Story out for posting by hand, with an image, and the plain token of its link.
     *
     * @return array{0: Post, 1: StoryHandoff, 2: string}
     */
    private function readyStory(): array
    {
        $post = $this->storyPost(postAttributes: ['assignee_id' => $this->owner->id]);
        $this->attachImage($post);
        $this->refusingAdapter();
        $post = $this->startPublishing($post);
        $handoff = $this->openHandoff($post);

        return [$post, $handoff, $this->issueToken($handoff)];
    }

    private function attachImage(Post $post): Asset
    {
        $image = imagecreatetruecolor(90, 160);
        ob_start();
        imagejpeg($image);
        $bytes = (string) ob_get_clean();
        $path = 'assets/'.Str::uuid().'.jpg';
        Storage::disk('local')->put($path, $bytes);

        $asset = Asset::factory()->create([
            'client_id' => $post->client_id,
            'source' => AssetSource::Upload,
            'type' => AssetType::Image,
            'disk' => 'local',
            'path' => $path,
            'mime_type' => 'image/jpeg',
            'size' => strlen($bytes),
        ]);
        $post->assets()->attach($asset, ['sort_order' => 0]);

        return $asset;
    }

    /**
     * A member of the Story's own organization who is restricted to a different client.
     */
    private function memberRestrictedToAnotherClient(): User
    {
        $this->org->update(['restrict_staff_to_assigned_clients' => true]);
        $other = Client::factory()->for($this->org, 'organization')->create(['name' => 'Bravo Bakery']);
        $designer = $this->member(Role::Designer);
        $designer->assignedClients()->attach($other);

        $this->assertSame([$other->id], $designer->accessibleClientIds());

        return $designer;
    }

    // --- Signed in to another organization ----------------------------------------------------

    public function test_posted_by_a_member_of_another_organization_publishes_the_post_and_records_nobody(): void
    {
        [$post, $handoff, $token] = $this->readyStory();

        $this->actingAs($this->outsider)
            ->post(route('story-handoff.posted', ['token' => $token]))
            ->assertRedirect(route('story-handoff.show', ['token' => $token]))
            ->assertSessionHasNoErrors();

        $handoff->refresh();
        $this->assertSame(StoryHandoffOutcome::Posted, $handoff->outcome);
        $this->assertNotNull($handoff->completed_at);
        $this->assertSame(StoryHandoff::VIA_LINK, $handoff->completed_via);
        // Somebody from another organization is, to this post, a guest.
        $this->assertNull($handoff->completed_by_user_id);

        $story = $this->storyTarget($post);
        $this->assertSame(PostTargetStatus::Published, $story->status);
        $this->assertNotNull($story->published_at);

        // The regression: the channel published, and the post stayed `publishing` for ever.
        $this->assertDatabaseHas('posts', ['id' => $post->id, 'status' => PostStatus::Published->value]);
        $this->assertDatabaseMissing('post_activity_logs', ['post_id' => $post->id, 'user_id' => $this->outsider->id]);
        $this->assertFalse(TenantScopes::areSuspended());
    }

    public function test_posted_by_a_member_of_the_storys_own_organization_records_them(): void
    {
        [$post, $handoff, $token] = $this->readyStory();
        $member = $this->member(Role::Designer);

        $this->actingAs($member)
            ->post(route('story-handoff.posted', ['token' => $token]))
            ->assertSessionHasNoErrors();

        $this->assertSame($member->id, $handoff->refresh()->completed_by_user_id);
        $this->assertDatabaseHas('posts', ['id' => $post->id, 'status' => PostStatus::Published->value]);
    }

    public function test_a_problem_reported_by_a_member_of_another_organization_fails_the_post_and_tells_its_own_team(): void
    {
        [$post, $handoff, $token] = $this->readyStory();
        $outsiderTeammate = $this->member(Role::Strategist, $this->outsider->organization()->firstOrFail());

        $this->actingAs($this->outsider)
            ->post(route('story-handoff.problem', ['token' => $token]), ['note' => 'Instagram would not take the image.'])
            ->assertRedirect(route('story-handoff.show', ['token' => $token]))
            ->assertSessionHasNoErrors();

        $handoff->refresh();
        $this->assertSame(StoryHandoffOutcome::Problem, $handoff->outcome);
        $this->assertSame('Instagram would not take the image.', $handoff->problem_note);
        $this->assertNull($handoff->completed_by_user_id);

        $story = $this->storyTarget($post);
        $this->assertSame(PostTargetStatus::Failed, $story->status);
        $this->assertSame(PublishFailureReason::StoryProblemReported, PublishFailureReason::classify($story->error));
        $this->assertDatabaseHas('posts', ['id' => $post->id, 'status' => PostStatus::Failed->value]);

        // The failure reaches the post's own organization, and nobody in the stranger's.
        Notification::assertSentTo($this->owner, PostPublishFailed::class);
        Notification::assertNotSentTo($this->outsider, PostPublishFailed::class);
        Notification::assertNotSentTo($outsiderTeammate, PostPublishFailed::class);
    }

    public function test_the_page_and_its_media_are_the_same_for_a_member_of_another_organization(): void
    {
        [, $handoff, $token] = $this->readyStory();

        $guest = $this->get(route('story-handoff.show', ['token' => $token]))->assertOk()->inertiaProps()['handoff'];
        $this->assertNotNull($guest['media']);

        $response = $this->actingAs($this->outsider)->get(route('story-handoff.show', ['token' => $token]));

        $response->assertOk()->assertInertia(fn ($page) => $page
            ->component('story-handoff/show', false)
            ->where('handoff.state', 'ready')
            ->where('handoff.client.name', 'Acme Coffee')
            ->where('handoff.account.handle', '@acmecoffee')
            ->where('handoff.media.kind', 'image')
            ->where('handoff.media.url', route('story-handoff.media', ['token' => $token]))
            ->where('handoff.media.mime_type', 'image/jpeg')
            // Still a stranger to the post itself.
            ->where('can.openPost', false)
            ->where('postUrl', null)
            ->where('next', null));
        $this->assertSame($guest, $response->inertiaProps()['handoff']);

        $media = $this->actingAs($this->outsider)->get(route('story-handoff.media', ['token' => $token]));
        $media->assertOk()->assertHeader('Content-Type', 'image/jpeg');
        $this->assertNotFalse(getimagesizefromstring((string) $media->getContent()));

        $this->actingAs($this->outsider)->post(route('story-handoff.shared', ['token' => $token]))->assertNoContent();
        $handoff->refresh();
        $this->assertNotNull($handoff->opened_at);
        $this->assertNotNull($handoff->shared_at);
        $this->assertFalse(TenantScopes::areSuspended());
    }

    public function test_a_late_confirmation_by_a_member_of_another_organization_publishes_the_missed_story(): void
    {
        [$post, $handoff, $token] = $this->readyStory();

        $this->travelTo('2026-11-20 12:00:00');
        $this->assertSame(1, app(ProcessStoryHandoffsAction::class)()['expired']);
        $this->assertSame(StoryHandoffOutcome::Missed, $handoff->refresh()->outcome);
        $this->assertSame(PostStatus::Failed, $post->refresh()->status);

        $this->actingAs($this->outsider)
            ->get(route('story-handoff.show', ['token' => $token]))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('story-handoff/show', false)
                ->where('handoff.state', 'missed')
                ->where('handoff.can_confirm_late', true));

        $this->actingAs($this->outsider)
            ->post(route('story-handoff.posted', ['token' => $token]))
            ->assertRedirect(route('story-handoff.show', ['token' => $token]))
            ->assertSessionHasNoErrors();

        $handoff->refresh();
        $this->assertSame(StoryHandoffOutcome::Posted, $handoff->outcome);
        $this->assertNull($handoff->completed_by_user_id);
        $story = $this->storyTarget($post);
        $this->assertSame(PostTargetStatus::Published, $story->status);
        $this->assertNull($story->error);
        $this->assertDatabaseHas('posts', ['id' => $post->id, 'status' => PostStatus::Published->value]);
    }

    public function test_scopes_are_back_on_after_a_token_route_answers_404(): void
    {
        $this->readyStory();

        foreach (['show', 'media'] as $name) {
            $this->actingAs($this->outsider)->get(route('story-handoff.'.$name, ['token' => Str::random(64)]))->assertNotFound();
        }

        foreach (['shared', 'posted'] as $name) {
            $this->actingAs($this->outsider)->post(route('story-handoff.'.$name, ['token' => Str::random(64)]))->assertNotFound();
        }

        $this->actingAs($this->outsider)
            ->post(route('story-handoff.problem', ['token' => Str::random(64)]), ['note' => 'It would not post.'])
            ->assertNotFound();

        $this->assertFalse(TenantScopes::areSuspended());
        // The stranger's own queries are scoped to their organization again.
        $this->assertSame(0, Post::query()->count());
        $this->assertSame(0, StoryHandoff::query()->count());
    }

    // --- Same organization, restricted to other clients ---------------------------------------

    public function test_a_member_restricted_to_other_clients_can_still_open_the_link_and_its_media(): void
    {
        [$post, $handoff, $token] = $this->readyStory();
        $restricted = $this->memberRestrictedToAnotherClient();
        $this->assertFalse($restricted->can('view', $post));

        $this->actingAs($restricted)
            ->get(route('story-handoff.show', ['token' => $token]))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('story-handoff/show', false)
                ->where('handoff.state', 'ready')
                ->where('handoff.client.name', 'Acme Coffee')
                ->where('handoff.media.kind', 'image')
                // The link opens this one Story; it is not a way into a client they are not on.
                ->where('can.openPost', false)
                ->where('postUrl', null));

        $this->actingAs($restricted)->get(route('story-handoff.media', ['token' => $token]))->assertOk();
        $this->assertNotNull($handoff->refresh()->opened_at);
    }

    public function test_a_member_restricted_to_other_clients_can_complete_the_link(): void
    {
        [$post, $handoff, $token] = $this->readyStory();
        $restricted = $this->memberRestrictedToAnotherClient();

        $this->actingAs($restricted)
            ->post(route('story-handoff.posted', ['token' => $token]))
            ->assertRedirect(route('story-handoff.show', ['token' => $token]))
            ->assertSessionHasNoErrors();

        $handoff->refresh();
        $this->assertSame(StoryHandoffOutcome::Posted, $handoff->outcome);
        // A member of the Story's own organization, so it is recorded against them.
        $this->assertSame($restricted->id, $handoff->completed_by_user_id);
        $this->assertSame(PostTargetStatus::Published, $this->storyTarget($post)->status);
        $this->assertDatabaseHas('posts', ['id' => $post->id, 'status' => PostStatus::Published->value]);
    }

    public function test_a_member_restricted_to_other_clients_can_report_a_problem_and_the_team_is_told(): void
    {
        [$post, $handoff, $token] = $this->readyStory();
        $restricted = $this->memberRestrictedToAnotherClient();

        $this->actingAs($restricted)
            ->post(route('story-handoff.problem', ['token' => $token]), ['note' => 'The link sticker was greyed out.'])
            ->assertSessionHasNoErrors();

        $this->assertSame(StoryHandoffOutcome::Problem, $handoff->refresh()->outcome);
        $this->assertSame(PostTargetStatus::Failed, $this->storyTarget($post)->status);
        $this->assertDatabaseHas('posts', ['id' => $post->id, 'status' => PostStatus::Failed->value]);
        Notification::assertSentTo($this->owner, PostPublishFailed::class);
    }
}
