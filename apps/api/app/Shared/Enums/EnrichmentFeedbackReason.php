<?php

declare(strict_types=1);

namespace App\Shared\Enums;

enum EnrichmentFeedbackReason: string
{
    case WrongProduct = 'wrong_product';
    case BadData = 'bad_data';
}
