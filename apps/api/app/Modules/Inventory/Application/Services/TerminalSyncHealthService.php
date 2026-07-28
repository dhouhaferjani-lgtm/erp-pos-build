<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Application\Services;

use App\Modules\Inventory\Domain\Enums\TerminalSyncHealthState;
use App\Modules\Inventory\Domain\InventoryCounting;
use App\Shared\Contracts\POS\TerminalSyncHealthSource;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Contracts\Cache\Repository;

/**
 * Inventory-owned, non-fiscal visibility into POS sync risk at count finalize.
 *
 * Pending-receipt truth exists only on each device's SQLite database. Devices
 * report that count after a sync tick; this service retains the latest report
 * in shared cache. Missing cache data is explicitly "unknown" and therefore
 * requires acknowledgement — it is never presented as a clean bill of health.
 */
final class TerminalSyncHealthService
{
    private const CACHE_TTL_SECONDS = 86400;

    private const STALE_AFTER_SECONDS = 300;

    public function __construct(
        private readonly Repository $cache,
        private readonly TerminalSyncHealthSource $terminalSource,
    ) {}

    public function record(
        string $companyId,
        string $terminalId,
        int $pendingReceiptCount,
        CarbonInterface $lastSyncAt,
    ): void {
        $this->cache->put($this->cacheKey($companyId, $terminalId), [
            'pending_receipt_count' => $pendingReceiptCount,
            'last_sync_at' => $lastSyncAt->toIso8601String(),
            'reported_at' => now()->toIso8601String(),
        ], self::CACHE_TTL_SECONDS);
    }

    /**
     * @return array{
     *     requires_acknowledgement: bool,
     *     acknowledgement_signature: string|null,
     *     stale_after_seconds: int,
     *     terminals: list<array{
     *         id: string,
     *         code: string,
     *         name: string,
     *         location_id: string,
     *         state: string,
     *         pending_receipt_count: int|null,
     *         last_sync_at: string|null,
     *         reported_at: string|null
     *     }>
     * }
     */
    public function forCounting(InventoryCounting $counting): array
    {
        /** @var list<string> $locationIds */
        $locationIds = $counting->items()
            ->distinct()
            ->pluck('location_id')
            ->map(static fn ($id): string => (string) $id)
            ->all();

        if ($locationIds === []) {
            return [
                'requires_acknowledgement' => false,
                'acknowledgement_signature' => null,
                'stale_after_seconds' => self::STALE_AFTER_SECONDS,
                'terminals' => [],
            ];
        }

        // Include inactive/training physical tills too: either can retain a
        // production-era local queue after its server-side status changes.
        $terminals = $this->terminalSource->physicalAtLocations(
            $counting->company_id,
            $locationIds,
        );

        $requiresAcknowledgement = false;
        $payload = [];
        $staleBoundary = now()->subSeconds(self::STALE_AFTER_SECONDS);

        foreach ($terminals as $terminal) {
            $cached = $this->cache->get($this->cacheKey($counting->company_id, $terminal['id']));
            $pendingCount = is_array($cached) && is_int($cached['pending_receipt_count'] ?? null)
                ? $cached['pending_receipt_count']
                : null;
            $lastSyncAt = is_array($cached) && is_string($cached['last_sync_at'] ?? null)
                ? $cached['last_sync_at']
                : null;
            $reportedAt = is_array($cached) && is_string($cached['reported_at'] ?? null)
                ? $cached['reported_at']
                : null;

            $state = match (true) {
                $lastSyncAt === null || $reportedAt === null => TerminalSyncHealthState::Unknown,
                $pendingCount !== null && $pendingCount > 0 => TerminalSyncHealthState::Pending,
                CarbonImmutable::parse($reportedAt)->lt($staleBoundary) => TerminalSyncHealthState::Stale,
                default => TerminalSyncHealthState::Healthy,
            };

            if ($state !== TerminalSyncHealthState::Healthy) {
                $requiresAcknowledgement = true;
            }

            $payload[] = [
                'id' => $terminal['id'],
                'code' => $terminal['code'],
                'name' => $terminal['name'],
                'location_id' => $terminal['location_id'],
                'state' => $state->value,
                'pending_receipt_count' => $pendingCount,
                'last_sync_at' => $lastSyncAt,
                'reported_at' => $reportedAt,
            ];
        }

        $signaturePayload = array_map(static fn (array $terminal): array => [
            'id' => $terminal['id'],
            'state' => $terminal['state'],
            'pending_receipt_count' => $terminal['pending_receipt_count'],
        ], $payload);

        return [
            'requires_acknowledgement' => $requiresAcknowledgement,
            'acknowledgement_signature' => $requiresAcknowledgement
                ? hash('sha256', json_encode($signaturePayload, JSON_THROW_ON_ERROR))
                : null,
            'stale_after_seconds' => self::STALE_AFTER_SECONDS,
            'terminals' => $payload,
        ];
    }

    private function cacheKey(string $companyId, string $terminalId): string
    {
        return "inventory:terminal-sync-health:{$companyId}:{$terminalId}";
    }
}
