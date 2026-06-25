<?php

declare(strict_types=1);

namespace App\Modules\BatchExpiry\Presentation\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\BatchExpiry\Application\DTOs\GroupedWriteOffData;
use App\Modules\BatchExpiry\Application\DTOs\GroupedWriteOffLine;
use App\Modules\BatchExpiry\Application\DTOs\GroupedWriteOffResult;
use App\Modules\BatchExpiry\Application\Services\GroupedWriteOffService;
use App\Modules\BatchExpiry\Domain\Entities\Batch;
use App\Modules\BatchExpiry\Domain\Exceptions\InsufficientBatchStockException;
use App\Modules\BatchExpiry\Presentation\Requests\GroupedWriteOffRequest;
use App\Modules\Inventory\Domain\Enums\MovementReason;
use Illuminate\Http\JsonResponse;

/**
 * Thin HTTP wrapper around GroupedWriteOffService.
 *
 * Responsibilities: validate (via GroupedWriteOffRequest), map the
 * reason string to a MovementReason enum, resolve batch UUIDs to
 * integer ids, delegate to the service, and return the result.
 * No business logic lives here.
 */
final class GroupedWriteOffController extends Controller
{
    public function __construct(
        private readonly GroupedWriteOffService $groupedWriteOffService,
    ) {}

    /**
     * POST /api/v1/batches/write-off-grouped
     *
     * First application  → 201 Created with the GroupedWriteOffResult payload.
     * Idempotent replay  → 200 OK with the same payload (stock not touched again).
     */
    public function writeOffGrouped(GroupedWriteOffRequest $request): JsonResponse
    {
        $validated = $request->validated();

        /** @var string $locationId */
        $locationId = (string) $validated['location_id'];

        /** @var string $reasonString */
        $reasonString = (string) $validated['reason'];

        /** @var string $idempotencyKey */
        $idempotencyKey = (string) $validated['idempotency_key'];

        /** @var list<array{batch_id: string, quantity: string}> $rawLines */
        $rawLines = $validated['lines'];

        // Map reason string → enum (expiry|damage|other).
        $reason = $this->resolveReason($reasonString);

        // Resolve each batch UUID → integer id (company-scoped; the FormRequest
        // already validated existence so a missing row is never expected here).
        $uuids = array_map(static fn (array $line): string => (string) $line['batch_id'], $rawLines);
        $uuidToId = Batch::query()
            ->whereIn('uuid', $uuids)
            ->pluck('id', 'uuid');

        // Build the typed line DTOs.
        /** @var list<GroupedWriteOffLine> $lines */
        $lines = array_map(
            static function (array $raw) use ($uuidToId): GroupedWriteOffLine {
                $batchUuid = (string) $raw['batch_id'];
                $batchId = (int) $uuidToId->get($batchUuid);

                /** @var numeric-string $quantity */
                $quantity = (string) $raw['quantity'];

                return new GroupedWriteOffLine(
                    batchId: $batchId,
                    quantity: $quantity,
                );
            },
            $rawLines,
        );

        $data = new GroupedWriteOffData(
            locationId: $locationId,
            lines: $lines,
            reason: $reason,
            idempotencyKey: $idempotencyKey,
        );

        try {
            $result = $this->groupedWriteOffService->writeOffGroup(
                data: $data,
                userId: (string) $request->user()?->id,
            );
        } catch (InsufficientBatchStockException $e) {
            return response()->json([
                'error' => [
                    'code' => 'INSUFFICIENT_BATCH_STOCK',
                    'message' => $e->getMessage(),
                ],
            ], 422);
        } catch (\DomainException $e) {
            return response()->json([
                'error' => [
                    'code' => 'WRITE_OFF_FAILED',
                    'message' => $e->getMessage(),
                ],
            ], 422);
        }

        // A replayed result (same idempotency_key, no stock change) → 200 OK.
        // A first-time application → 201 Created.
        $status = $result->replayed ? 200 : 201;

        return response()->json([
            'data' => $this->formatResult($result),
        ], $status);
    }

    /**
     * Map the API reason string to the domain enum.
     *
     * Accepted values: expiry → Expiry, damage → Damage, other → WriteOff.
     * The FormRequest `in:expiry,damage,other` rule guarantees these are the
     * only three values that reach this method.
     */
    private function resolveReason(string $reason): MovementReason
    {
        return match ($reason) {
            'expiry' => MovementReason::Expiry,
            'damage' => MovementReason::Damage,
            'other' => MovementReason::WriteOff,
            default => throw new \InvalidArgumentException(
                "Unexpected reason '{$reason}' — FormRequest should have rejected this."
            ),
        };
    }

    /**
     * Serialize a GroupedWriteOffResult to the API response shape.
     *
     * @return array<string, mixed>
     */
    private function formatResult(GroupedWriteOffResult $result): array
    {
        return [
            'idempotency_key' => $result->idempotencyKey,
            'replayed' => $result->replayed,
            'movements' => array_map(
                static fn ($m): array => $m->toArray(),
                $result->movements,
            ),
        ];
    }
}
