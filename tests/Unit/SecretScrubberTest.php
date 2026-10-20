<?php

namespace Tests\Unit;

use App\Support\Observability\SecretScrubber;
use PHPUnit\Framework\TestCase;
use Sentry\Event;

/**
 * Step I-16.1 / M-1.1 — secrets never leave the app in error reports or alerts.
 */
class SecretScrubberTest extends TestCase
{
    public function test_it_filters_secret_keys_recursively_and_keeps_ids(): void
    {
        $scrubbed = SecretScrubber::scrub([
            'post_id' => 12,
            'password' => 'hunter2',
            'organization' => ['upload_post_key' => 'up_live_123', 'name' => 'Studio'],
            'headers' => ['Authorization' => 'Bearer abc', 'X-Upload-Post-Signature' => 'sha256=dead', 'accept' => 'json'],
            'xai_key' => 'xai-1',
            'remember_token' => 'tok',
        ]);

        $this->assertSame(12, $scrubbed['post_id']);
        $this->assertSame(SecretScrubber::FILTERED, $scrubbed['password']);
        $this->assertSame(SecretScrubber::FILTERED, $scrubbed['organization']['upload_post_key']);
        $this->assertSame('Studio', $scrubbed['organization']['name']);
        $this->assertSame(SecretScrubber::FILTERED, $scrubbed['headers']['Authorization']);
        $this->assertSame(SecretScrubber::FILTERED, $scrubbed['headers']['X-Upload-Post-Signature']);
        $this->assertSame('json', $scrubbed['headers']['accept']);
        $this->assertSame(SecretScrubber::FILTERED, $scrubbed['xai_key']);
        $this->assertSame(SecretScrubber::FILTERED, $scrubbed['remember_token']);
    }

    public function test_the_sentry_hook_scrubs_request_extra_and_contexts(): void
    {
        $event = Event::createEvent();
        $event->setRequest(['data' => ['password' => 'p', 'caption' => 'Hi'], 'cookies' => ['laravel_session' => 's']]);
        $event->setExtra(['api_key' => 'k']);
        $event->setContext('laravel', ['correlation_id' => 'req-1', 'webhook_secret' => 'w']);

        $event = SecretScrubber::beforeSend($event);

        $this->assertSame(SecretScrubber::FILTERED, $event->getRequest()['data']['password']);
        $this->assertSame('Hi', $event->getRequest()['data']['caption']);
        $this->assertSame(SecretScrubber::FILTERED, $event->getRequest()['cookies']);
        $this->assertSame(SecretScrubber::FILTERED, $event->getExtra()['api_key']);
        $this->assertSame('req-1', $event->getContexts()['laravel']['correlation_id']);
        $this->assertSame(SecretScrubber::FILTERED, $event->getContexts()['laravel']['webhook_secret']);
    }
}
