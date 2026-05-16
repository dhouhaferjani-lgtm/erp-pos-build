<?php

declare(strict_types=1);

namespace App\Modules\Fiscal\Domain\DTOs;

use App\Modules\Fiscal\Domain\Enums\FiscalEventType;
use App\Modules\Fiscal\Domain\Exceptions\FiscalEventTypeNotImplemented;

/**
 * COMPANY_DAY_CLOSURE_MANIFEST payload — RESERVED, schema-only.
 *
 * The Phase 1 device + server vocabulary RESERVES this event type so a
 * future Phase 2 ship can use it without renumbering the chain. The DTO
 * ships as a class so the SDK surface is stable and downstream consumers
 * can type-test against the symbol, but `FiscalEventPayloadRegistry` does
 * NOT map this enum case to the DTO — attempting `dtoClassFor()` or
 * `eventVersionFor()` on this case throws `FiscalEventTypeNotImplemented`.
 *
 * `fromArray()` / `toArray()` mirror the registry's failure semantics:
 * both throw the same `FiscalEventTypeNotImplemented` exception so any
 * direct importer (Phase 2 enablement code paths, ad-hoc projector
 * tests) gets a consistent error surface rather than a generic
 * LogicException.
 *
 * Phase 2 will flesh out the schema (per spec v7 §11) once the
 * company-day closure workflow lands. Until then, this class is
 * intentionally a thin placeholder.
 */
final readonly class CompanyDayClosureManifestPayload
{
    private function __construct() {}

    /**
     * @param  array<string, mixed>  $data
     *
     * @throws FiscalEventTypeNotImplemented always — Phase 1 does not implement this payload.
     */
    public static function fromArray(array $data): self
    {
        unset($data);
        throw new FiscalEventTypeNotImplemented(FiscalEventType::COMPANY_DAY_CLOSURE_MANIFEST);
    }

    /**
     * @return array<string, mixed>
     *
     * @throws FiscalEventTypeNotImplemented always — Phase 1 does not implement this payload.
     */
    public function toArray(): array
    {
        throw new FiscalEventTypeNotImplemented(FiscalEventType::COMPANY_DAY_CLOSURE_MANIFEST);
    }
}
