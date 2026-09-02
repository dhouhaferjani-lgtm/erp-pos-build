<?php

declare(strict_types=1);

namespace App\Shared\Enums;

enum CatalogLookupOutcome: string
{
    case Found = 'found';
    case NotFound = 'not_found';
    case PlatformUnavailable = 'platform_unavailable';
    case PlatformError = 'platform_error';
    case InvalidBarcode = 'invalid_barcode';
    case VerticalNotSupported = 'vertical_not_supported';
}
