<?php

namespace App\Support\WhatsApp;

/**
 * Step W-0.1 — one outbound message template send: which approved template, in which locale, with
 * the ordered variables that fill it.
 *
 * Meta approves a template per language, and `{{1}}`, `{{2}}` … are positional — so the order of
 * `$parameters` is the contract with the approved copy, not a detail. The template registry
 * (Step W-1.4) is what will name them; until then a caller passes the name it registered by hand.
 *
 * shortcut: body variables only. A template with a dynamic header or a URL-button suffix needs its
 * own component, which is worth adding when W-1.4 registers the first template that uses one.
 */
final readonly class WhatsAppTemplateMessage
{
    /**
     * @param  list<string>  $parameters  in `{{1}}`-first order
     */
    public function __construct(
        public string $name,
        public array $parameters = [],
        public string $locale = 'en_GB',
    ) {}

    /**
     * The `template` object of a Cloud API message payload.
     *
     * @return array<string, mixed>
     */
    public function toPayload(): array
    {
        $payload = [
            'name' => $this->name,
            'language' => ['code' => $this->locale],
        ];

        if ($this->parameters !== []) {
            $payload['components'] = [[
                'type' => 'body',
                'parameters' => array_map(
                    // Meta rejects a variable containing a newline or four consecutive spaces
                    // (error 132000), and a post title pasted from a brief will contain both.
                    fn (string $value) => ['type' => 'text', 'text' => (string) preg_replace('/\s+/u', ' ', trim($value))],
                    $this->parameters,
                ),
            ]];
        }

        return $payload;
    }
}
