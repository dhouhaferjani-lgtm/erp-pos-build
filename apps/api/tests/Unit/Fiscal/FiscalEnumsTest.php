<?php

declare(strict_types=1);

namespace Tests\Unit\Fiscal;

use App\Modules\Fiscal\Domain\Enums\IntegrityExceptionClass;
use App\Modules\Fiscal\Domain\Enums\IntegrityStatus;
use App\Modules\Fiscal\Domain\Enums\PayloadParseStatus;
use App\Modules\Fiscal\Domain\Enums\ProjectionStatus;
use App\Modules\Fiscal\Domain\Enums\SignatureStatus;
use PHPUnit\Framework\TestCase;

final class FiscalEnumsTest extends TestCase
{
    public function test_integrity_exception_classes(): void
    {
        $this->assertSame('canonical_hash_mismatch', IntegrityExceptionClass::CanonicalHashMismatch->value);
        $this->assertSame('sequence_conflict', IntegrityExceptionClass::SequenceConflict->value);
        $this->assertFalse(IntegrityExceptionClass::SequenceConflict->isAdmissibleToLedger());
        $this->assertTrue(IntegrityExceptionClass::CanonicalHashMismatch->isAdmissibleToLedger());
    }

    public function test_projection_status_values(): void
    {
        $this->assertSame(
            ['pending', 'running', 'applied', 'dead_lettered'],
            array_map(fn ($case) => $case->value, ProjectionStatus::cases()),
        );
    }

    public function test_payload_parse_status_and_integrity_status_and_signature_status(): void
    {
        $this->assertSame(['pending', 'parsed', 'failed'], array_map(fn ($case) => $case->value, PayloadParseStatus::cases()));
        $this->assertSame(['verified', 'quarantined'], array_map(fn ($case) => $case->value, IntegrityStatus::cases()));
        $this->assertSame(['not_required', 'pending', 'signed', 'failed'], array_map(fn ($case) => $case->value, SignatureStatus::cases()));
    }
}
