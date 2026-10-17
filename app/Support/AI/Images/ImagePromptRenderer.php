<?php

namespace App\Support\AI\Images;

/**
 * Renders an AiImageBrief into the single, ordered natural-language prompt image models respond
 * to best (Step 16.6): references → subject → composition → style → mood → palette → text → avoid.
 * Shared by every ImageProvider adapter so a brief reads the same whichever vendor renders it.
 * The output is a prompt: never log it.
 */
class ImagePromptRenderer
{
    /**
     * @param  array<int, ReferenceImage>  $references
     */
    public function render(AiImageBrief $brief, array $references = []): string
    {
        $lines = [];

        foreach (array_values($references) as $index => $reference) {
            $lines[] = 'Image '.($index + 1).' is '.$reference->role->promptDescription().'.';
        }

        if ($lines !== []) {
            $lines[] = '';
        }

        $lines[] = 'Create a polished social media image.';
        $lines[] = 'Subject: '.$this->sentence($brief->subject);

        if ($brief->composition !== '') {
            $lines[] = 'Composition: '.$this->sentence($brief->composition);
        }

        if ($brief->style !== '') {
            $lines[] = 'Style: '.$this->sentence($brief->style);
        }

        if ($brief->mood !== '') {
            $lines[] = 'Mood: '.$this->sentence($brief->mood);
        }

        if ($brief->palette !== []) {
            $lines[] = 'Colour palette: '.collect($brief->palette)
                ->map(fn (array $colour): string => $colour['hex'] !== null ? "{$colour['name']} ({$colour['hex']})" : $colour['name'])
                ->implode(', ').'. Let these colours lead; keep any other colours neutral.';
        }

        if ($brief->textOverlay !== null) {
            $lines[] = 'Text: render exactly this headline, spelled correctly, large and legible with generous margins: "'.$brief->textOverlay.'".';
        } else {
            $lines[] = 'Text: no words, letters, captions or watermarks anywhere in the image. Leave calm space where a caption could sit.';
        }

        $avoid = [...$brief->avoid, 'real public figures', 'third-party logos or trademarks'];
        $lines[] = 'Avoid: '.implode('; ', array_unique($avoid)).'.';

        return implode("\n", $lines);
    }

    /**
     * The edit prompt for a plain-words refinement of an existing image.
     */
    public function renderEdit(string $instruction): string
    {
        return implode("\n", [
            'Edit the provided image: '.$this->sentence($instruction),
            'Keep everything else the same: composition, subject, palette, lighting and style.',
            'Do not add any words, letters or watermarks unless the instruction asks for them.',
        ]);
    }

    private function sentence(string $text): string
    {
        $text = trim($text);

        return $text === '' || preg_match('/[.!?]$/', $text) === 1 ? $text : $text.'.';
    }
}
