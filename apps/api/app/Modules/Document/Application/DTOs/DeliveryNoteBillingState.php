<?php

declare(strict_types=1);

namespace App\Modules\Document\Application\DTOs;

use App\Modules\Document\Domain\Enums\DeliveryNoteBillingLane;

final class DeliveryNoteBillingState
{
    public function __construct(
        public readonly ?string $invoiced_at,
        public readonly ?string $invoice_id,
        public readonly ?DeliveryNoteBillingLane $invoiced_via,
    ) {}

    /**
     * `documents.payload` is free-form JSONB cast to `array` (`Document.php:195`), so
     * EVERY key here is an arbitrary legacy value of arbitrary PHP type — a nested JSON
     * object or list decodes to a PHP array, and `(string) $array` raises `E_WARNING`,
     * which Laravel's `HandleExceptions` converts into an `ErrorException`. That is a
     * 500 on `GET /delivery-notes/{id}` AND on the whole `GET /delivery-notes` list,
     * because `DeliveryNoteController::index` maps every row through
     * `DocumentData::fromModel` — one dirty legacy row takes out the primary read
     * surface of this feature for every user in the tenant.
     *
     * The shape is field data by this wave's own admission: the M1C backfill
     * (`2026_08_18_000002_create_delivery_note_billing_marks_table.php`) enumerates
     * `['not' => 'a UUID']` and `['not' => 'a timestamp']` as fixtures.
     *
     * The migration's guard ORDER is `is_string()` FIRST and only then the format
     * check (`safeInvoicedAt():176` — `is_string` then `trim` then `CarbonImmutable::parse`;
     * `safeInvoiceId():204` — `is_string` then `trim` then `Str::isUuid`). This DTO owns
     * the `is_string` half for both keys; the format half lives at the consumer
     * (`DocumentData.php:198` runs `Str::isUuid()` before the `documents.id` lookup).
     * Together the two layers reproduce the migration's contract; NEITHER layer
     * reproduces it alone. `invoiced_via` below has been guarded this way since M1.
     *
     * A non-string value is therefore treated as ABSENT, never cast:
     * a dirty `invoice_id` resolves to no invoicing document, and a dirty `invoiced_at`
     * reads as not-yet-billed — exactly what the backfill records (it writes no marker
     * row for either shape) and never a 500.
     * (M5-terminal r2: treasury `R2-1` == tenancy `F-R2-1`; round 1 = treasury `F-1` /
     * tenancy `F-T1`.)
     *
     * @param  array<string, mixed>  $payload
     */
    public static function fromPayload(array $payload): self
    {
        $invoicedAt = isset($payload['invoiced_at']) && is_string($payload['invoiced_at'])
            ? $payload['invoiced_at']
            : null;
        $invoiceId = isset($payload['invoice_id']) && is_string($payload['invoice_id'])
            ? $payload['invoice_id']
            : null;
        $invoicedVia = isset($payload['invoiced_via']) && is_string($payload['invoiced_via'])
            ? DeliveryNoteBillingLane::tryFrom($payload['invoiced_via'])
            : null;

        if ($invoicedAt !== null && $invoicedVia === null) {
            $invoicedVia = DeliveryNoteBillingLane::LegacyUnknown;
        }

        return new self($invoicedAt, $invoiceId, $invoicedVia);
    }

    /** @return array{invoiced_at: string|null, invoice_id: string|null, invoiced_via: string|null} */
    public function toPayloadPatch(): array
    {
        return [
            'invoiced_at' => $this->invoiced_at,
            'invoice_id' => $this->invoice_id,
            'invoiced_via' => $this->invoiced_via?->value,
        ];
    }
}
