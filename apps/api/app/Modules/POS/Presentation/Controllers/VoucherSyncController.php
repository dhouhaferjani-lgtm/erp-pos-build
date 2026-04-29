<?php

declare(strict_types=1);

namespace App\Modules\POS\Presentation\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\POS\Application\DTOs\VoucherLedgerPushPayload;
use App\Modules\POS\Application\Services\ReceiptQrIndexSyncService;
use App\Modules\POS\Application\Services\VoucherLedgerPushService;
use App\Modules\POS\Application\Services\VoucherSyncService;
use App\Modules\POS\Presentation\Requests\VoucherLedgerSyncRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Gate;
use InvalidArgumentException;

/**
 * Voucher + receipt-QR-index sync endpoints.
 *
 * Three GET pulls (vouchers, voucher-ledger, receipt-qr-index) and one
 * POST push (voucher-ledger). Backs the offline-first guarantee in the
 * Tauri POS client (Task 44 + Session 1.5): the cashier never round-trips
 * the API during interaction; mirroring happens via background sync.
 *
 * The wire-protocol contract is dictated by the already-deployed Tauri
 * client (apps/pos/src/lib/sync/syncService.ts:836-1014). All four
 * endpoints serialise enums as PascalCase case-names, single-terminal
 * scope is enforced at the SQL layer, and the push handler routes
 * through VoucherRedemptionService so the GL leg is server-authored.
 */
final class VoucherSyncController extends Controller
{
    public function __construct(
        private readonly VoucherSyncService $voucherSyncService,
        private readonly ReceiptQrIndexSyncService $qrIndexService,
        private readonly VoucherLedgerPushService $pushService,
    ) {}

    /**
     * GET /api/v1/pos/vouchers/sync
     *
     * Returns vouchers redeemable at the requesting terminal, with
     * `updated_since` cursor support and a 100-row page cap.
     */
    public function pullVouchers(Request $request): JsonResponse
    {
        Gate::authorize('pos.operate_terminal');

        $terminalId = $this->requireTerminalId($request);
        $updatedSince = $this->parseUpdatedSince($request);

        $rows = $this->voucherSyncService->pullVouchers($terminalId, $updatedSince);

        return response()->json([
            'data' => [
                'vouchers' => array_map(
                    static fn ($dto) => $dto->toArray(),
                    $rows,
                ),
                'deleted_ids' => [],
            ],
        ]);
    }

    /**
     * GET /api/v1/pos/voucher-ledger/sync
     *
     * Returns ledger rows for vouchers redeemable at the requesting
     * terminal. Cursor: `created_at > updated_since` (the ledger is
     * append-only — no `updated_at` column).
     */
    public function pullVoucherLedger(Request $request): JsonResponse
    {
        Gate::authorize('pos.operate_terminal');

        $terminalId = $this->requireTerminalId($request);
        $updatedSince = $this->parseUpdatedSince($request);

        $rows = $this->voucherSyncService->pullLedger($terminalId, $updatedSince);

        return response()->json([
            'data' => [
                'entries' => array_map(
                    static fn ($dto) => $dto->toArray(),
                    $rows,
                ),
            ],
        ]);
    }

    /**
     * GET /api/v1/pos/receipts/qr-index
     *
     * Returns one row per fiscalised receipt at the requesting terminal,
     * with a server-signed QR token. Pending-seal receipts are excluded.
     */
    public function pullReceiptQrIndex(Request $request): JsonResponse
    {
        Gate::authorize('pos.operate_terminal');

        $terminalId = $this->requireTerminalId($request);
        $updatedSince = $this->parseUpdatedSince($request);

        $rows = $this->qrIndexService->pull($terminalId, $updatedSince);

        return response()->json([
            'data' => [
                'entries' => array_map(
                    static fn ($dto) => $dto->toArray(),
                    $rows,
                ),
            ],
        ]);
    }

    /**
     * POST /api/v1/pos/voucher-ledger/sync
     *
     * Accepts 1+ ledger entries pushed by the offline POS. Each entry is
     * processed independently — failures don't abort the loop. Idempotent
     * on the client-supplied UUID `id` field.
     */
    public function pushVoucherLedger(VoucherLedgerSyncRequest $request): JsonResponse
    {
        Gate::authorize('pos.operate_terminal');

        $validated = $request->validated();
        /** @var array<int, array<string, mixed>> $entries */
        $entries = $validated['entries'];

        $results = [];
        $synced = 0;
        $duplicates = 0;
        $failed = 0;

        foreach ($entries as $rawEntry) {
            try {
                $payload = VoucherLedgerPushPayload::fromArray($rawEntry);
            } catch (InvalidArgumentException $e) {
                $id = isset($rawEntry['id']) && is_string($rawEntry['id']) ? $rawEntry['id'] : '';
                $results[] = [
                    'id' => $id,
                    'status' => 'failed',
                    'error' => 'event_kind_not_supported_in_offline_path',
                ];
                $failed++;

                continue;
            }

            $requestingTerminalId = $payload->terminalId;

            // If the entry omits terminal_id, fall back to the voucher's
            // redeemable_at_terminal_id (the push handler still enforces
            // the match, so a missing/wrong value is rejected there).
            // We require terminal_id on the entry for pushed redemptions
            // because the client always populates it (see redemption flow
            // in apps/pos/src/lib/voucher/voucherRedemption.ts).
            if ($requestingTerminalId === null) {
                $results[] = [
                    'id' => $payload->id,
                    'status' => 'failed',
                    'error' => 'terminal_id_required',
                ];
                $failed++;

                continue;
            }

            $result = $this->pushService->push($payload, $requestingTerminalId);
            $results[] = $result;

            match ($result['status']) {
                'synced' => $synced++,
                'duplicate' => $duplicates++,
                default => $failed++,
            };
        }

        return response()->json([
            'data' => [
                'results' => $results,
                'synced' => $synced,
                'duplicates' => $duplicates,
                'failed' => $failed,
            ],
        ]);
    }

    private function requireTerminalId(Request $request): string
    {
        $terminalId = $request->query('terminal_id');

        if (! is_string($terminalId) || $terminalId === '') {
            abort(422, 'terminal_id is required');
        }

        return $terminalId;
    }

    private function parseUpdatedSince(Request $request): ?Carbon
    {
        $value = $request->query('updated_since');

        if (! is_string($value) || $value === '') {
            return null;
        }

        return Carbon::parse($value);
    }
}
