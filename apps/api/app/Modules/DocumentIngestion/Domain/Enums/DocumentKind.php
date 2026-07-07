<?php

declare(strict_types=1);

namespace App\Modules\DocumentIngestion\Domain\Enums;

enum DocumentKind: string
{
    case SupplierInvoice = 'supplier_invoice';
    case SupplierDeliveryNote = 'supplier_delivery_note';
}
