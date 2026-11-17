<?php

namespace Tests\Unit;

use App\Support\Observability\SecretScrubber;
use PHPUnit\Framework\TestCase;
use Sentry\Breadcrumb;
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

    public function test_a_link_token_in_a_path_is_filtered(): void
    {
        $token = str_repeat('aB3', 21).'z'; // 64 letters and digits, like `Str::random(64)`

        $this->assertSame('https://alima.test/story/[Filtered]', SecretScrubber::scrubUrl("https://alima.test/story/{$token}"));
        $this->assertSame('/story/[Filtered]/media?size=full', SecretScrubber::scrubUrl("/story/{$token}/media?size=full"));
        $this->assertSame('/review/[Filtered]#post', SecretScrubber::scrubUrl('/review/'.substr($token, 0, 40).'#post'));
        $this->assertSame('GET /kit/[Filtered] failed', SecretScrubber::scrubUrl('GET /kit/'.substr($token, 0, 32).' failed'));
    }

    public function test_ordinary_urls_survive(): void
    {
        foreach ([
            '/posts/1234567890/edit',
            '/exports/3f2b8c1e-9d4a-4c56-8a1b-0e2f4d6c8a90/download',
            '/build/assets/app-Dk3mQ9aXcVbNmLpOiUyTrEwQaSdFgHjK.js',
            '/storage/assets/7/0123456789abcdef0123456789abcdef01234567.jpg',
            '/story/short-token',
            'Call to undefined method on a value with no slash 0123456789abcdef0123456789abcdef',
            '/clients/'.str_repeat('a', 31),
        ] as $value) {
            $this->assertSame($value, SecretScrubber::scrubUrl($value));
        }
    }

    public function test_scrub_filters_link_tokens_in_string_values(): void
    {
        $token = str_repeat('Xy9', 21).'Q';

        $scrubbed = SecretScrubber::scrub([
            'url' => "https://alima.test/story/{$token}",
            'nested' => ['referer' => "https://alima.test/review/{$token}", 'post_id' => 7],
        ]);

        $this->assertSame('https://alima.test/story/[Filtered]', $scrubbed['url']);
        $this->assertSame('https://alima.test/review/[Filtered]', $scrubbed['nested']['referer']);
        $this->assertSame(7, $scrubbed['nested']['post_id']);
    }

    public function test_the_sentry_hook_keeps_a_handoff_token_out_of_the_event(): void
    {
        $token = str_repeat('Tk7', 21).'m';

        $event = Event::createEvent();
        $event->setRequest([
            'url' => "https://alima.test/story/{$token}",
            'headers' => ['Referer' => ["https://alima.test/story/{$token}"]],
        ]);
        $event->setTransaction("/story/{$token}");
        $event->setBreadcrumb([
            new Breadcrumb(Breadcrumb::LEVEL_INFO, Breadcrumb::TYPE_HTTP, 'http', "GET /story/{$token}", [
                'url' => "https://alima.test/story/{$token}/media",
                'api_key' => 'k',
                'status' => 200,
            ]),
        ]);

        $event = SecretScrubber::beforeSend($event);

        $this->assertStringNotContainsString($token, (string) json_encode([
            $event->getRequest(),
            $event->getTransaction(),
            $event->getBreadcrumbs()[0]->getMessage(),
            $event->getBreadcrumbs()[0]->getMetadata(),
        ]));
        $this->assertSame('https://alima.test/story/[Filtered]', $event->getRequest()['url']);
        $this->assertSame('/story/[Filtered]', $event->getTransaction());
        $this->assertSame('GET /story/[Filtered]', $event->getBreadcrumbs()[0]->getMessage());
        $this->assertSame('https://alima.test/story/[Filtered]/media', $event->getBreadcrumbs()[0]->getMetadata()['url']);
        $this->assertSame(SecretScrubber::FILTERED, $event->getBreadcrumbs()[0]->getMetadata()['api_key']);
        $this->assertSame(200, $event->getBreadcrumbs()[0]->getMetadata()['status']);
    }

    public function test_the_sentry_hook_leaves_a_route_pattern_transaction_alone(): void
    {
        $event = Event::createEvent();
        $event->setTransaction('/story/{token}');

        $this->assertSame('/story/{token}', SecretScrubber::beforeSend($event)->getTransaction());
    }
}
