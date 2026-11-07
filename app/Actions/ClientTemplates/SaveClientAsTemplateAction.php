<?php

namespace App\Actions\ClientTemplates;

use App\Models\Client;
use App\Models\ClientTemplate;
use App\Models\User;
use App\Support\Clients\ClientTemplateSnapshot;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * "Save this client as a template" — the realistic way an agency gets its first one.
 *
 * Nobody fills in a blank template form. They set a client up the way they like, realise they will
 * do it nine more times, and want that captured. So the capture is from a working client rather
 * than from a wizard.
 *
 * Re-saving under an existing name updates that template rather than creating a near-duplicate:
 * "save as template" pressed twice is somebody refining one, not making two.
 */
class SaveClientAsTemplateAction
{
    /**
     * @param  array{name: string, description?: string|null}  $data
     */
    public function __invoke(Client $client, User $actor, array $data): ClientTemplate
    {
        return DB::transaction(function () use ($client, $actor, $data): ClientTemplate {
            $client->loadMissing('brandBrain');

            $template = ClientTemplate::query()->updateOrCreate(
                ['org_id' => $client->org_id, 'name' => trim($data['name'])],
                [
                    'created_by' => $actor->id,
                    'description' => $data['description'] ?? null,
                    'snapshot' => ClientTemplateSnapshot::capture($client),
                    // A template saved by a person is theirs, even if it happens to share a name
                    // with one we shipped — otherwise editing a built-in would be impossible.
                    'is_built_in' => false,
                ],
            );

            Log::info('Client template saved', [
                'org_id' => $client->org_id,
                'client_id' => $client->id,
                'template_id' => $template->id,
                'actor_user_id' => $actor->id,
            ]);

            return $template;
        });
    }
}
