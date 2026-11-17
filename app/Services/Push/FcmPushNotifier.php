<?php

namespace App\Services\Push;

use App\Contracts\PushDeliveryResult;
use App\Contracts\PushNotifier;
use App\Models\PushSubscription;
use App\Support\Push\PushMessage;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Native shell (Capacitor) — the {@see PushNotifier} every sender gets. A phone's device token is
 * delivered over Firebase Cloud Messaging (HTTP v1, which also fronts APNs for iOS); a browser
 * subscription is passed straight to the web transport it wraps. So one user can hold both kinds
 * and no sender has to know which is which.
 *
 * `push.fcm.transport = log` (the default) accepts registrations and logs instead of sending, so
 * dev and tests need no Firebase project. `send()` never throws and never logs a token, a key or
 * the payload. An `UNREGISTERED` token (the app was uninstalled) is `Gone`, which the caller prunes.
 */
class FcmPushNotifier implements PushNotifier
{
    private const TOKEN_CACHE_KEY = 'push.fcm.access-token';

    public function __construct(private readonly PushNotifier $web) {}

    /** Whether a device token can be registered and (transport permitting) delivered. */
    public static function nativeConfigured(): bool
    {
        if (! config('push.enabled')) {
            return false;
        }

        return config('push.fcm.transport') !== 'fcm' || self::credentials() !== null;
    }

    public function isConfigured(): bool
    {
        return $this->web->isConfigured() || self::nativeConfigured();
    }

    public function publicKey(): ?string
    {
        return $this->web->publicKey();
    }

    public function send(PushSubscription $subscription, PushMessage $message): PushDeliveryResult
    {
        if (! $subscription->isNative()) {
            return $this->web->send($subscription, $message);
        }

        if (! self::nativeConfigured()) {
            return PushDeliveryResult::Skipped;
        }

        if (config('push.fcm.transport') !== 'fcm') {
            Log::info('Native push logged, not sent (push.fcm.transport = log)', [
                'user_id' => $subscription->user_id,
                'subscription_id' => $subscription->id,
                'tag' => $message->tag,
            ]);

            return PushDeliveryResult::Failed;
        }

        try {
            $credentials = self::credentials();
            $response = Http::withToken($this->accessToken($credentials))
                ->timeout(10)
                ->post("https://fcm.googleapis.com/v1/projects/{$credentials['project_id']}/messages:send", [
                    'message' => $this->payload($subscription, $message),
                ]);

            if ($response->successful()) {
                return PushDeliveryResult::Delivered;
            }

            if ($response->status() === 404 || $response->json('error.details.0.errorCode') === 'UNREGISTERED') {
                return PushDeliveryResult::Gone;
            }

            if ($response->status() === 401) {
                Cache::forget(self::TOKEN_CACHE_KEY);
            }

            Log::warning('Native push delivery failed', [
                'subscription_id' => $subscription->id,
                'status' => $response->status(),
                'error' => $response->json('error.status'),
            ]);
        } catch (Throwable $e) {
            Log::warning('Native push delivery threw', [
                'subscription_id' => $subscription->id,
                'exception' => $e::class,
            ]);
        }

        return PushDeliveryResult::Failed;
    }

    /**
     * @return array<string, mixed>
     */
    private function payload(PushSubscription $subscription, PushMessage $message): array
    {
        $payload = [
            'token' => $subscription->endpoint,
            'notification' => ['title' => $message->title, 'body' => $message->body],
            // FCM data values must be strings. `url` is where a tap lands (read by the shell).
            'data' => array_filter(['url' => $message->url, 'tag' => $message->tag]),
        ];

        // Same collapse behaviour as the web `tag`: a nudge replaces the first notification.
        if ($message->tag !== null) {
            $payload['android'] = ['notification' => ['tag' => $message->tag]];
            $payload['apns'] = ['headers' => ['apns-collapse-id' => $message->tag]];
        }

        return $payload;
    }

    /**
     * A short-lived OAuth token for the service account (signed JWT → token exchange), cached for
     * just under its one-hour life.
     *
     * @param  array{client_email: string, private_key: string, project_id: string}  $credentials
     */
    private function accessToken(array $credentials): string
    {
        return Cache::remember(self::TOKEN_CACHE_KEY, 3300, fn (): string => (string) Http::asForm()
            ->timeout(10)
            ->post('https://oauth2.googleapis.com/token', [
                'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
                'assertion' => $this->assertion($credentials),
            ])->throw()->json('access_token'));
    }

    /**
     * The RS256-signed JWT Google exchanges for an access token. Extracted so a test can substitute
     * it without a real RSA key (as `MinishlinkWebPushNotifier::makeWebPush()` does for VAPID).
     *
     * @param  array{client_email: string, private_key: string, project_id: string}  $credentials
     */
    protected function assertion(array $credentials): string
    {
        $encode = fn (string $value): string => rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
        $now = time();
        $unsigned = $encode((string) json_encode(['alg' => 'RS256', 'typ' => 'JWT'])).'.'.$encode((string) json_encode([
            'iss' => $credentials['client_email'],
            'scope' => 'https://www.googleapis.com/auth/firebase.messaging',
            'aud' => 'https://oauth2.googleapis.com/token',
            'iat' => $now,
            'exp' => $now + 3600,
        ]));

        if (! openssl_sign($unsigned, $signature, $credentials['private_key'], OPENSSL_ALGO_SHA256)) {
            throw new \RuntimeException('Could not sign the FCM service-account assertion.');
        }

        return $unsigned.'.'.$encode($signature);
    }

    /**
     * @return array{client_email: string, private_key: string, project_id: string}|null
     */
    private static function credentials(): ?array
    {
        $path = config('push.fcm.credentials');

        if (! is_string($path) || $path === '' || ! is_readable($path)) {
            return null;
        }

        $json = json_decode((string) file_get_contents($path), true);

        return is_array($json) && isset($json['client_email'], $json['private_key'], $json['project_id'])
            ? $json
            : null;
    }
}
