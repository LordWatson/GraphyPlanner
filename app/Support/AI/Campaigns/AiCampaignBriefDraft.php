<?php

namespace App\Support\AI\Campaigns;

use App\Models\CampaignBrief;
use JsonSerializable;

/**
 * Step C-2.2 — an AI-suggested campaign brief. Only ever shown as a suggestion the user accepts
 * per section; never saved by itself. `flags` lists, per field, the banned phrases (Brand Brain
 * "do nots" / never-use hashtags / prohibited topics) the guardrail spotted in it.
 */
final readonly class AiCampaignBriefDraft implements JsonSerializable
{
    /**
     * @param  array<string, string|list<string>|null>  $fields  keyed by `CampaignBrief::CONTENT_FIELDS`
     * @param  array<string, list<string>>  $flags
     */
    public function __construct(
        public array $fields = [],
        public array $flags = [],
    ) {}

    public function isEmpty(): bool
    {
        foreach ($this->fields as $value) {
            if (is_array($value) ? $value !== [] : filled($value)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Normalise a provider payload: only known fields, lists as lists of trimmed strings.
     *
     * @param  array<string, mixed>  $payload
     */
    public static function fromArray(array $payload): self
    {
        $fields = [];

        foreach (CampaignBrief::CONTENT_FIELDS as $field) {
            $value = $payload[$field] ?? null;

            if (in_array($field, CampaignBrief::LIST_FIELDS, true)) {
                $fields[$field] = array_values(array_filter(array_map(
                    fn ($line) => is_scalar($line) ? trim((string) $line) : '',
                    is_array($value) ? $value : [],
                ), fn (string $line) => $line !== ''));
            } else {
                $fields[$field] = is_scalar($value) && trim((string) $value) !== '' ? trim((string) $value) : null;
            }
        }

        return new self($fields);
    }

    /**
     * @param  array<string, list<string>>  $flags
     */
    public function withFlags(array $flags): self
    {
        return new self($this->fields, $flags);
    }

    /**
     * @return array{fields: array<string, string|list<string>|null>, flags: array<string, list<string>>}
     */
    public function jsonSerialize(): array
    {
        return ['fields' => $this->fields, 'flags' => $this->flags];
    }
}
