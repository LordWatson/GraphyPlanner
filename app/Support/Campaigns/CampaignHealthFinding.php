<?php

namespace App\Support\Campaigns;

use App\Enums\ClientHealth;

/**
 * Step C-0.4 — one reason a campaign isn't green, with the level it raises the campaign to.
 * `code` is stable (the UI and Needs attention key off it); `reason` is the sentence people read.
 */
final readonly class CampaignHealthFinding
{
    public function __construct(
        public ClientHealth $level,
        public string $code,
        public string $reason,
    ) {}

    public static function red(string $code, string $reason): self
    {
        return new self(ClientHealth::Red, $code, $reason);
    }

    public static function amber(string $code, string $reason): self
    {
        return new self(ClientHealth::Amber, $code, $reason);
    }

    /**
     * @return array{level: string, code: string, reason: string}
     */
    public function toArray(): array
    {
        return ['level' => $this->level->value, 'code' => $this->code, 'reason' => $this->reason];
    }
}
