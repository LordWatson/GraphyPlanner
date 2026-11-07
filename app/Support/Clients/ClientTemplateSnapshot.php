<?php

namespace App\Support\Clients;

use App\Models\Client;
use App\Models\ClientTemplate;

/**
 * What a client onboarding template copies, and what it deliberately does not.
 *
 * One list in one place, read by both directions — capturing a template from a client and applying
 * one to a new client. Two lists would drift, and the failure would be silent: a field quietly
 * stops being templated and nobody notices until a client is set up wrong.
 *
 * **What is copied:** the *how we work with this kind of client* settings — approval mode and
 * policy, lead days, posting cadence and goal, reporting schedule, billing cycle and retainer
 * shape, language, and the brand brain's strategy fields.
 *
 * **What is not, and why it must not be:**
 *
 *  - **Identity** — name, legal name, website, industry, location. A template is for the shape of
 *    the engagement, not for who the client is; copying these would create near-duplicate clients.
 *  - **Email addresses** (`approval_email`, `billing_email`) and the billing address. Sending one
 *    client's approval requests to another's inbox is the worst outcome this feature could have,
 *    so those fields are not in the list at all rather than guarded somewhere downstream.
 *  - **Tax registration** and `default_tax_rate_id` — legal identifiers belong to one entity.
 *  - **`owner_user_id`** — who runs an account is a staffing decision made per client.
 *  - **Anything with history**: posts, assets, invoices, health. A template is settings, not data.
 *
 * `ClientTemplateFieldsTest` asserts every copied key is a real `Client` fillable, and that none of
 * the excluded ones have crept in.
 */
final class ClientTemplateSnapshot
{
    /**
     * Client columns a template carries.
     *
     * @var list<string>
     */
    public const CLIENT_FIELDS = [
        'status',
        'brand_kind',
        'billing_cycle',
        'retainer_amount',
        'auto_invoice_retainer',
        'billing_currency',
        'default_language',
        'default_approval_mode',
        'approval_policy',
        'approval_lead_days',
        'report_schedule',
        'target_posts_per_week',
        'posting_goal',
        'tags',
    ];

    /**
     * Brand brain columns a template carries: the strategy an agency reuses across similar
     * clients, never the uploaded persona file (which belongs to one brand).
     *
     * @var list<string>
     */
    public const BRAND_BRAIN_FIELDS = [
        'voice',
        'audience',
        'offer',
        'visual',
        'music_policy',
        'hashtag_policy',
        'content_pillars',
    ];

    /**
     * Fields that must never be templated, with the reason. Asserted by the test, so "we meant
     * that" is written down rather than remembered.
     *
     * @var array<string, string>
     */
    public const NEVER = [
        'name' => 'identity, not engagement shape',
        'legal_name' => 'identity',
        'website' => 'identity',
        'industry' => 'identity',
        'location' => 'identity',
        'countries' => 'identity',
        'approval_email' => 'one client’s approvals must never reach another’s inbox',
        'billing_email' => 'one client’s invoices must never reach another’s inbox',
        'billing_address' => 'belongs to one entity',
        'tax_registration_number' => 'a legal identifier belongs to one entity',
        'default_tax_rate_id' => 'a tax rate belongs to one entity',
        'owner_user_id' => 'who runs an account is a staffing decision per client',
        'is_primary' => 'structural — one primary brand per business workspace',
        'org_id' => 'set from the acting member, never copied',
        'start_date' => 'when this engagement began',
        'notes_internal' => 'written about one client',
    ];

    /**
     * Capture `$client`'s current settings.
     *
     * @return array{client: array<string, mixed>, brand_brain: array<string, mixed>}
     */
    public static function capture(Client $client): array
    {
        $brain = $client->brandBrain;

        // Cast values, not raw attributes. A JSON-cast column (the brand brain's fields) comes back
        // from `getAttributes()` as its encoded string, which would then be stored double-encoded
        // and applied to the new client as a literal `"…"`. Backed enums serialise to their value
        // on the way into the JSON column and are parsed again by the cast on the way out.
        return [
            'client' => self::pick($client->only(self::CLIENT_FIELDS), self::CLIENT_FIELDS),
            'brand_brain' => $brain === null ? [] : self::pick($brain->only(self::BRAND_BRAIN_FIELDS), self::BRAND_BRAIN_FIELDS),
        ];
    }

    /**
     * The client attributes a template contributes to a new client.
     *
     * Filtered against `CLIENT_FIELDS` on the way *out* as well as in, so an old snapshot — or a
     * hand-edited one — can never set a field the current rules say is not templatable.
     *
     * @return array<string, mixed>
     */
    public static function clientAttributes(ClientTemplate $template): array
    {
        return self::pick((array) ($template->snapshot['client'] ?? []), self::CLIENT_FIELDS);
    }

    /**
     * @return array<string, mixed>
     */
    public static function brandBrainAttributes(ClientTemplate $template): array
    {
        return self::pick((array) ($template->snapshot['brand_brain'] ?? []), self::BRAND_BRAIN_FIELDS);
    }

    /**
     * @param  array<string, mixed>  $source
     * @param  list<string>  $keys
     * @return array<string, mixed>
     */
    private static function pick(array $source, array $keys): array
    {
        $picked = [];

        foreach ($keys as $key) {
            if (array_key_exists($key, $source) && $source[$key] !== null) {
                $picked[$key] = $source[$key];
            }
        }

        return $picked;
    }
}
