<?php

namespace App\Http\Requests;

use App\Models\ReferralPartner;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Str;

/**
 * Validates the public "register interest" form. `website` is a honeypot: humans never see it, so
 * a filled one marks a bot (the controller pretends to succeed and stores nothing).
 */
class StoreRegistrationInterestRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        if (is_string($this->input('email'))) {
            $this->merge(['email' => Str::lower(trim($this->input('email')))]);
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'min:2', 'max:120'],
            'email' => ['required', 'string', 'email', 'max:255'],
            'phone' => ['nullable', 'string', 'max:40', 'regex:/^[0-9+()\-.\s]{6,40}$/'],
            'company_name' => ['required', 'string', 'min:2', 'max:160'],
            'location' => ['nullable', 'string', 'max:160'],
            // Filled in by the page, not the visitor.
            'timezone' => ['nullable', 'timezone:all'],
            'referrer' => ['nullable', 'string', 'max:512'],
            'utm_source' => ['nullable', 'string', 'max:120'],
            'utm_medium' => ['nullable', 'string', 'max:120'],
            'utm_campaign' => ['nullable', 'string', 'max:120'],
            'website' => ['nullable', 'string'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'phone.regex' => 'That doesn’t look like a phone number.',
        ];
    }

    public function isBot(): bool
    {
        return filled($this->input('website'));
    }

    /**
     * The validated form plus what the request itself tells us (IP, Cloudflare geo headers when
     * the site sits behind Cloudflare, browser locale, referral cookie).
     *
     * @return array<string, mixed>
     */
    public function toAttributes(): array
    {
        $data = collect($this->validated())->except('website')->all();
        $country = Str::upper((string) $this->header('CF-IPCountry'));
        $referrerHost = parse_url((string) ($data['referrer'] ?? ''), PHP_URL_HOST);
        $referralCode = $this->cookie(ReferralPartner::COOKIE);

        return [
            ...$data,
            // Our own pages are not a source.
            'referrer' => $referrerHost && $referrerHost !== $this->getHost() ? $data['referrer'] : null,
            'ip_address' => $this->ip(),
            // `XX` unknown, `T1` Tor.
            'country' => preg_match('/^[A-Z]{2}$/', $country) && $country !== 'XX' ? $country : null,
            'city' => $this->header('CF-IPCity') ? mb_substr((string) $this->header('CF-IPCity'), 0, 120) : null,
            'locale' => $this->getPreferredLanguage() ? mb_substr((string) $this->getPreferredLanguage(), 0, 35) : null,
            'user_agent' => $this->userAgent() ? mb_substr($this->userAgent(), 0, 512) : null,
            'referral_code' => is_string($referralCode) ? ReferralPartner::findByCode($referralCode)?->code : null,
        ];
    }
}
