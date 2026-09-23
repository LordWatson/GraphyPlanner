<?php

namespace App\Services;

use App\Enums\ClientHealth;
use App\Enums\ClientStatus;
use App\Enums\ConnectionStatus;
use App\Models\Client;

/**
 * Computes a client's health status (Red/Amber/Green) and the human-readable reason behind it.
 *
 * The health status is never stored — it is derived on the fly from the client's current
 * attributes, so it always reflects the latest data. Rules are evaluated red-first, then amber,
 * falling back to green, and each rule is independently testable (see
 * tests/Unit/Services/ClientHealthServiceTest.php).
 */
class ClientHealthService
{
    /**
     * @return array{status: ClientHealth, reason: string}
     */
    public function compute(Client $client): array
    {
        if ($result = $this->redTrigger($client)) {
            return $result;
        }

        if ($result = $this->amberTrigger($client)) {
            return $result;
        }

        return $this->greenResult($client);
    }

    /**
     * @return array{status: ClientHealth, reason: string}|null
     */
    private function redTrigger(Client $client): ?array
    {
        if ($client->status === ClientStatus::Offboarding) {
            return $this->result(ClientHealth::Red, 'Client is offboarding.');
        }

        if ($client->status === ClientStatus::Active && $client->owner_user_id === null) {
            return $this->result(ClientHealth::Red, 'Active client has no assigned owner.');
        }

        if ($client->status === ClientStatus::Active
            && $client->retainer_amount === null
            && $client->billing_cycle === null
        ) {
            return $this->result(ClientHealth::Red, 'Active client has no billing setup (missing retainer amount and billing cycle).');
        }

        if ($this->hasTokenExpiredAccount($client)) {
            return $this->result(ClientHealth::Red, 'A connected social account has an expired/revoked token.');
        }

        return null;
    }

    /**
     * Step 1.6: a disconnected vendor token is a red flag on its own — the client can't publish
     * to that account until someone reconnects it. Uses `loadMissing()` so callers that haven't
     * eager-loaded `socialAccounts` (e.g. a single client detail page) still work correctly.
     */
    private function hasTokenExpiredAccount(Client $client): bool
    {
        return $client->loadMissing('socialAccounts')
            ->socialAccounts
            ->contains(fn ($account) => $account->connection_status === ConnectionStatus::TokenExpired);
    }

    /**
     * @return array{status: ClientHealth, reason: string}|null
     */
    private function amberTrigger(Client $client): ?array
    {
        if ($client->status === ClientStatus::Paused) {
            return $this->result(ClientHealth::Amber, 'Client is paused.');
        }

        if ($client->status === ClientStatus::Active && $client->start_date === null) {
            return $this->result(ClientHealth::Amber, 'Active client has no start date recorded.');
        }

        if ($client->status === ClientStatus::Active
            && (($client->retainer_amount === null) !== ($client->billing_cycle === null))
        ) {
            return $this->result(ClientHealth::Amber, 'Client billing setup is incomplete (retainer amount or billing cycle missing).');
        }

        return null;
    }

    /**
     * @return array{status: ClientHealth, reason: string}
     */
    private function greenResult(Client $client): array
    {
        if ($client->status === ClientStatus::Archived) {
            return $this->result(ClientHealth::Green, 'Client is archived; no active engagement risk.');
        }

        return $this->result(ClientHealth::Green, 'Active client in good standing.');
    }

    /**
     * @return array{status: ClientHealth, reason: string}
     */
    private function result(ClientHealth $status, string $reason): array
    {
        return ['status' => $status, 'reason' => $reason];
    }
}
