<?php

namespace App\Enums;

/**
 * What a reference image tells the image model (Step 16.4). `Draft` is internal: the chosen draft
 * passed back in when a variant is made final, so the final keeps the composition people picked.
 */
enum ReferenceImageRole: string
{
    case Logo = 'logo';
    case Style = 'style';
    case Product = 'product';
    case Draft = 'draft';

    public function label(): string
    {
        return match ($this) {
            self::Logo => 'Logo',
            self::Style => 'Style',
            self::Product => 'Product',
            self::Draft => 'Draft',
        };
    }

    /**
     * Roles a person can tag a Brand Brain reference with.
     *
     * @return array<int, self>
     */
    public static function brandRoles(): array
    {
        return [self::Logo, self::Style, self::Product];
    }

    /**
     * How the prompt introduces an image of this role ("Image 1 is the brand logo…").
     */
    public function promptDescription(): string
    {
        return match ($this) {
            self::Logo => 'the brand logo. Reproduce it exactly if a logo appears; never redraw or recolour it',
            self::Style => 'a style reference. Match its lighting, colour grading, texture and overall look, not its content',
            self::Product => 'a real product. Keep its shape, colours and details accurate',
            self::Draft => 'the approved draft. Keep its composition, framing and subject placement',
        };
    }
}
