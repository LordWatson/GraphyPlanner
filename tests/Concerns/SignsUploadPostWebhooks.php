<?php

namespace Tests\Concerns;

use App\Models\Organization;
use Illuminate\Testing\TestResponse;

/**
 * Step 1.5 — posts a correctly signed delivery to `/webhooks/upload-post`.
 *
 * The endpoint fails closed (see `UploadPostWebhookController`), so every test about what a
 * publish outcome *does* has to sign its delivery. Without this the signing boilerplate would be
 * copied into each of them, and a test that quietly stopped signing would stop testing the thing
 * it names.
 *
 * The secret below is a test fixture and is only ever used to sign the payloads here.
 */
trait SignsUploadPostWebhooks
{
    protected const UPLOAD_POST_WEBHOOK_SECRET = 'whsec_test_upload_post';

    /**
     * Give the organization a webhook secret, and return it.
     */
    protected function withUploadPostWebhookSecret(Organization $organization, string $secret = self::UPLOAD_POST_WEBHOOK_SECRET): string
    {
        $organization->update(['upload_post_webhook_secret' => $secret]);

        return $secret;
    }

    /**
     * POST `$payload` as Upload-Post would: HMAC-SHA256 over `{timestamp}.{raw body}`, keyed on the
     * organization's webhook secret (set here when it has none yet).
     *
     * @param  array<string, mixed>  $payload
     */
    protected function postSignedUploadPostWebhook(Organization $organization, array $payload): TestResponse
    {
        $secret = (string) ($organization->upload_post_webhook_secret ?? '');

        if ($secret === '') {
            $secret = $this->withUploadPostWebhookSecret($organization);
        }

        $body = (string) json_encode($payload);
        $timestamp = (string) time();

        return $this->call('POST', route('webhooks.upload-post'), [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X-Upload-Post-Signature' => 'sha256='.hash_hmac('sha256', "{$timestamp}.{$body}", $secret),
            'HTTP_X-Upload-Post-Timestamp' => $timestamp,
        ], $body);
    }
}
