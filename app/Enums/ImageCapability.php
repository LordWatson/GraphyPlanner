<?php

namespace App\Enums;

/**
 * Optional features an App\Contracts\ImageProvider may or may not support (Step 16.1).
 */
enum ImageCapability: string
{
    case MultiReference = 'multi_reference';
    case TextRendering = 'text_rendering';
    case Edit = 'edit';
}
