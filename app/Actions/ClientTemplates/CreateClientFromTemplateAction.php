<?php

namespace App\Actions\ClientTemplates;

use App\Actions\Clients\CreateClientAction;
use App\Models\Client;
use App\Models\ClientTemplate;
use App\Models\User;
use App\Support\Clients\ClientTemplateSnapshot;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

/**
 * Create a client with a template's settings already applied.
 *
 * The template supplies the engagement shape; the caller supplies the identity (name, website,
 * emails) — see {@see ClientTemplateSnapshot} for why those are never templated. **The caller's
 * values always win**, so a template can never overwrite something a person just typed: it fills
 * the blanks rather than deciding them.
 *
 * Reuses `CreateClientAction` rather than inserting a `Client` of its own, so everything that
 * action does now and later (logging, and whatever else is added to client creation) happens for a
 * templated client too.
 */
class CreateClientFromTemplateAction
{
    public function __construct(private readonly CreateClientAction $createClient) {}

    /**
     * @param  array<string, mixed>  $data  the identity fields a person filled in
     */
    public function __invoke(User $actor, ClientTemplate $template, array $data): Client
    {
        // Belt and braces behind the policy and the route binding: a template from another
        // organization must never be applied, and the org comes from the actor either way.
        if ($template->org_id !== $actor->org_id) {
            throw ValidationException::withMessages([
                'template' => __('That template belongs to another organization.'),
            ]);
        }

        return DB::transaction(function () use ($actor, $template, $data): Client {
            // Template first, caller second: whatever the person typed wins.
            $client = ($this->createClient)($actor, [
                ...ClientTemplateSnapshot::clientAttributes($template),
                ...$data,
            ]);

            $brainFields = ClientTemplateSnapshot::brandBrainAttributes($template);

            if ($brainFields !== []) {
                // The strategy an agency reuses across similar clients. Never the persona file.
                $client->brandBrain()->create($brainFields);
            }

            Log::info('Client created from template', [
                'org_id' => $actor->org_id,
                'client_id' => $client->id,
                'template_id' => $template->id,
                'actor_user_id' => $actor->id,
            ]);

            return $client;
        });
    }
}
