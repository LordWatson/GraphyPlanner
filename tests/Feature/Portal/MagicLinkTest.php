<?php

namespace Tests\Feature\Portal;

use App\Actions\Portal\CreatePortalLoginTokenAction;
use App\Actions\Posts\SendClientReviewRequestedEmailAction;
use App\Enums\PostStatus;
use App\Enums\Role;
use App\Mail\ClientReviewRequestedMail;
use App\Models\Client;
use App\Models\ClientContact;
use App\Models\Organization;
use App\Models\PortalLoginToken;
use App\Models\Post;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * Step M-6.2 (I-8.1) — the passwordless magic link, and that a review email prefers it for a
 * contact who already has a portal login.
 */
class MagicLinkTest extends TestCase
{
    use RefreshDatabase;

    private Organization $org;

    private Client $client;

    private User $reviewer;

    protected function setUp(): void
    {
        parent::setUp();

        Mail::fake();

        $this->org = Organization::factory()->create();
        $this->client = Client::factory()->for($this->org, 'organization')->create(['approval_email' => null]);
        $this->reviewer = User::factory()->for($this->org, 'organization')->role(Role::ClientReviewer)->create([
            'client_id' => $this->client->id,
            'email' => 'reviewer@acme.test',
        ]);
    }

    private function visiblePost(): Post
    {
        return Post::factory()->for($this->client)->create([
            'org_id' => $this->org->id,
            'status' => PostStatus::WaitingClient,
        ]);
    }

    public function test_a_valid_magic_link_signs_the_reviewer_in_and_lands_on_the_post(): void
    {
        $post = $this->visiblePost();
        $token = app(CreatePortalLoginTokenAction::class)($this->reviewer, $post)['token'];

        $response = $this->get("/portal-login/{$token}");

        $response->assertRedirect(route('portal.posts.show', $post));
        $this->assertAuthenticatedAs($this->reviewer);
    }

    public function test_a_magic_link_without_a_post_lands_on_the_portal_dashboard(): void
    {
        $token = app(CreatePortalLoginTokenAction::class)($this->reviewer)['token'];

        $this->get("/portal-login/{$token}")->assertRedirect(route('portal.dashboard'));
        $this->assertAuthenticatedAs($this->reviewer);
    }

    public function test_a_magic_link_is_single_use(): void
    {
        ['token' => $token, 'model' => $model] = app(CreatePortalLoginTokenAction::class)($this->reviewer);

        $this->get("/portal-login/{$token}")->assertRedirect(route('portal.dashboard'));
        $this->assertNotNull($model->fresh()->used_at);

        // A second use is rejected (the token is consumed).
        $this->get("/portal-login/{$token}")->assertRedirect(route('login'));
    }

    public function test_an_expired_magic_link_falls_back_to_login(): void
    {
        $plain = 'expired-plain-token';
        PortalLoginToken::create([
            'user_id' => $this->reviewer->id,
            'token_hash' => PortalLoginToken::hashToken($plain),
            'expires_at' => now()->subDay(),
        ]);

        $this->get("/portal-login/{$plain}")->assertRedirect(route('login'));
        $this->assertGuest();
    }

    public function test_an_unknown_magic_link_falls_back_to_login(): void
    {
        $this->get('/portal-login/does-not-exist')->assertRedirect(route('login'));
        $this->assertGuest();
    }

    public function test_the_token_hash_is_stored_never_the_plain_token(): void
    {
        $token = app(CreatePortalLoginTokenAction::class)($this->reviewer)['token'];

        $this->assertDatabaseMissing('portal_login_tokens', ['token_hash' => $token]);
        $this->assertDatabaseHas('portal_login_tokens', ['token_hash' => PortalLoginToken::hashToken($token)]);
    }

    public function test_review_email_sends_a_magic_link_to_a_contact_with_portal_access_and_a_review_link_to_one_without(): void
    {
        // The reviewer (has a portal login) and a plain approver contact (no login).
        ClientContact::factory()->forClient($this->client)->create(['email' => 'reviewer@acme.test', 'receives_review_emails' => true]);
        ClientContact::factory()->forClient($this->client)->create(['email' => 'plain@acme.test', 'receives_review_emails' => true]);

        $post = $this->visiblePost();

        app(SendClientReviewRequestedEmailAction::class)($post);

        Mail::assertQueued(ClientReviewRequestedMail::class, fn (ClientReviewRequestedMail $mail) => $mail->hasTo('reviewer@acme.test') && str_contains($mail->reviewUrl, '/portal-login/'));
        Mail::assertQueued(ClientReviewRequestedMail::class, fn (ClientReviewRequestedMail $mail) => $mail->hasTo('plain@acme.test') && str_contains($mail->reviewUrl, '/review/'));
        $this->assertDatabaseCount('portal_login_tokens', 1);
    }
}
