<?php

declare(strict_types=1);

namespace Tests\PHPStan\Fixtures;

use App\Modules\Document\Domain\Enums\DeliveryNoteBillingLane;
use App\Modules\Document\Domain\Services\Billing\DeliveryNoteClaimSet;

final class DeliveryNoteClaimSetExternalIssuanceFixture
{
    public function issue(): DeliveryNoteClaimSet
    {
        return DeliveryNoteClaimSet::fromReservation(
            ['00000000-0000-0000-0000-000000000001'],
            '00000000-0000-0000-0000-000000000002',
            DeliveryNoteBillingLane::Consolidation,
            '2026-08-18T00:00:00+00:00',
        );
    }
}
