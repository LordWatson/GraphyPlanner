<?php

namespace App\Support\Campaigns;

use App\Enums\ClientHealth;

/**
 * Step C-0.4 — a campaign's derived health: the worst level among its findings, the headline
 * reason, and every finding (so the UI can list them all with a tooltip on the pill).
 */
final readonly class CampaignHealth
{
    /**
     * @param  list<CampaignHealthFinding>  $findings
     */
    public function __construct(
        public ClientHealth $status,
        public string $reason,
        public array $findings,
    ) {}

    /**
     * @return array{status: string, label: string, reason: string, findings: list<array{level: string, code: string, reason: string}>}
     */
    public function toArray(): array
    {
        return [
            'status' => $this->status->value,
            'label' => $this->status->label(),
            'reason' => $this->reason,
            'findings' => array_map(fn (CampaignHealthFinding $finding) => $finding->toArray(), $this->findings),
        ];
    }
}
