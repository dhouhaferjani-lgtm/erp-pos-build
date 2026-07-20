<?php

declare(strict_types=1);

namespace Tests\Unit\Treasury;

use App\Modules\Treasury\Application\DTOs\MovementIntent;
use App\Modules\Treasury\Domain\Enums\MovementDirection;
use App\Modules\Treasury\Domain\Enums\MovementSourceType;
use Illuminate\Support\Str;
use Tests\TestCase;

final class MovementIntentTest extends TestCase
{
    public function test_idempotency_key_matches_source_type_source_id_and_leg_for_a_pos_tender(): void
    {
        $sourceId = (string) Str::uuid();

        $intent = new MovementIntent(
            repositoryId: (string) Str::uuid(),
            tenantId: (string) Str::uuid(),
            companyId: (string) Str::uuid(),
            direction: MovementDirection::In,
            amount: '30.000',
            currency: 'EUR',
            sourceType: MovementSourceType::FiscalEvent,
            sourceId: $sourceId,
            idempotencyLeg: 'payment:0',
            journalEntryId: null,
            occurredAt: null,
            reasonCode: null,
            reversesMovementId: null,
            createdBy: null,
            notes: null,
        );

        $this->assertSame("fiscal_event:{$sourceId}:payment:0", $intent->idempotencyKey());
    }

    public function test_allow_while_frozen_defaults_to_false_when_omitted(): void
    {
        $intent = new MovementIntent(
            repositoryId: (string) Str::uuid(),
            tenantId: (string) Str::uuid(),
            companyId: (string) Str::uuid(),
            direction: MovementDirection::Out,
            amount: '10.000',
            currency: 'EUR',
            sourceType: MovementSourceType::Payment,
            sourceId: (string) Str::uuid(),
            idempotencyLeg: 'payment:0',
            journalEntryId: null,
            occurredAt: null,
            reasonCode: null,
            reversesMovementId: null,
            createdBy: null,
            notes: null,
        );

        $this->assertFalse($intent->allowWhileFrozen);
        $this->assertFalse($intent->allowBehindCheckpoint);
    }
}
