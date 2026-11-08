<?php

namespace App\Support\WhatsApp;

use InvalidArgumentException;

/**
 * Step W-0.1 / W-1.4 — one outbound template send: which approved template, and the values that
 * fill it.
 *
 * The constructor is the guard W-1.4 asked for. Keys must match the template's `variables()`
 * exactly, so a notification cannot send a message with a hole in it (`{{2}}` showing through to a
 * client) or quietly pass a variable the approved copy has no slot for. Wrong keys are a
 * programming error, not a delivery failure, so this throws where it can be seen in a test rather
 * than failing at Meta hours later.
 */
final readonly class WhatsAppTemplateMessage
{
    /** @var array<string, string> */
    public array $values;

    /**
     * @param  array<string, string|int|float|null>  $values  keyed by the template's variable names
     */
    public function __construct(public MessageTemplate $template, array $values = [])
    {
        $expected = $template->variables();
        $given = array_keys($values);

        sort($expected);
        sort($given);

        if ($expected !== $given) {
            throw new InvalidArgumentException(sprintf(
                'Template %s takes [%s], got [%s].',
                $template->value,
                implode(', ', $template->variables()),
                implode(', ', array_keys($values)),
            ));
        }

        $this->values = array_map(
            // Meta refuses a variable containing a newline or four consecutive spaces, and a post
            // title pasted out of a brief contains both. An empty variable is refused too, so a
            // missing value becomes a dash rather than nothing.
            fn (mixed $value) => trim((string) preg_replace('/\s+/u', ' ', (string) $value)) ?: '-',
            $values,
        );
    }

    /**
     * Twilio's `ContentVariables`: a JSON object keyed by the placeholder's position, which is why
     * the order in `variables()` is part of the approved copy's contract.
     */
    public function contentVariables(): string
    {
        $positional = [];

        foreach ($this->template->variables() as $index => $name) {
            $positional[(string) ($index + 1)] = $this->values[$name];
        }

        return (string) json_encode($positional);
    }

    /**
     * What the recipient will actually read, used for the message log and for previewing copy in
     * tests. Not what is sent — Twilio renders the approved template itself.
     */
    public function preview(): string
    {
        $body = $this->template->sampleBody();

        foreach ($this->template->variables() as $index => $name) {
            $body = str_replace('{{'.($index + 1).'}}', $this->values[$name], $body);
        }

        return $body;
    }
}
