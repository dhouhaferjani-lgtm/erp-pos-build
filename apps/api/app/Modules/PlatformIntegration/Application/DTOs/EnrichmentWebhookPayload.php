<?php

declare(strict_types=1);

namespace App\Modules\PlatformIntegration\Application\DTOs;

use Spatie\LaravelData\Data;

class EnrichmentWebhookPayload extends Data
{
    /**
     * Tenant anchor echoed back by the platform (2026-08-05).
     *
     * The webhook route is unauthenticated, so `ResolveTenancy` binds no
     * tenant: under database-per-tenant everything downstream runs on the
     * CENTRAL connection, where `products` does not exist. The tenant cannot be
     * derived from `tracking_id` either — that lookup is itself the tenant-table
     * read that fails. So the anchor has to arrive with the payload.
     *
     * NULLABLE because the platform does not send `tenant_id` yet (tracked in
     * docs/superpowers/tickets/2026-08-05-enrichment-webhook-platform-contract.md).
     *
     * DECLARED rather than constructor-PROMOTED, deliberately: a promoted
     * property's default is applied by the CONSTRUCTOR, and `unserialize()`
     * never calls one. A DTO serialized into a queue payload before this
     * property existed therefore restores it UNINITIALIZED — reading it fatals
     * with "Typed property must not be accessed before initialization" (verified
     * against this exact class, 2026-08-05). A class-level default is applied by
     * the class definition itself and survives unserialize.
     */
    public ?string $tenantId = null;

    public function __construct(
        public string $event,
        public string $trackingId,
        public ?string $barcode,
        public string $status,
        public ?string $enrichmentQuality,
        public bool $hasBarcodeAssigned,
        public string $vertical,
        public string $timestamp,
        public ?string $locale = null,
        ?string $tenantId = null,
    ) {
        $this->tenantId = $tenantId;
    }

    /**
     * @param  array<string, mixed>  $webhook
     */
    public static function fromWebhook(array $webhook): self
    {
        return new self(
            event: (string) ($webhook['event'] ?? ''),
            trackingId: (string) ($webhook['tracking_id'] ?? ''),
            barcode: isset($webhook['barcode']) ? (string) $webhook['barcode'] : null,
            status: (string) ($webhook['status'] ?? ''),
            enrichmentQuality: isset($webhook['enrichment_quality']) ? (string) $webhook['enrichment_quality'] : null,
            hasBarcodeAssigned: (bool) ($webhook['has_barcode_assigned'] ?? false),
            vertical: (string) ($webhook['vertical'] ?? ''),
            locale: isset($webhook['locale']) ? (string) $webhook['locale'] : null,
            timestamp: (string) ($webhook['timestamp'] ?? now()->toIso8601String()),
            tenantId: isset($webhook['tenant_id']) && $webhook['tenant_id'] !== ''
                ? (string) $webhook['tenant_id']
                : null,
        );
    }
}
