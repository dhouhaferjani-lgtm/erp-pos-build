<?php

declare(strict_types=1);

namespace App\Modules\Document\Domain\Exceptions;

use DomainException;

final class DeliveryNoteClaimNotFinalisedException extends DomainException
{
    public static function forMarkerCount(int $expected, int $affected): self
    {
        return new self("Delivery-note marker finalisation affected {$affected} rows; expected {$expected}.");
    }

    public static function forPayloadCount(int $expected, int $affected): self
    {
        return new self("Delivery-note payload finalisation affected {$affected} rows; expected {$expected}.");
    }
}
