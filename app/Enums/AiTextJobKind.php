<?php

namespace App\Enums;

use App\Models\AiTextJob;

/**
 * Step M-7.2 — which agency text AI task an {@see AiTextJob} runs asynchronously.
 */
enum AiTextJobKind: string
{
    case Captions = 'captions';
    case Trends = 'trends';
    case Ideas = 'ideas';
}
