<?php

declare(strict_types=1);

namespace App\Modules\POS\Presentation\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\POS\Application\DTOs\VoucherLedgerPushPayload;
use App\Modules\POS\Application\Services\ReceiptQrIndexSyncService;
use App\Modules\POS\Application\Services\VoucherLedgerPushService;
use App\Modules\POS\Application\Services\VoucherSyncService;
use App\Modules\POS\Presentation\Requests\VoucherLedgerSyncRequest;
use App\Shared\Architecture\CrossTenantRoute;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Gate;

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
    #[CrossTenantRoute(reason: 'Terminal-scoped POS sync: terminal_id is the scoping anchor (extracted from request query via requireTerminalId helper, validated at the service-layer SQL — `single-terminal scope is enforced at the SQL layer` per class docblock). Gate::authorize(\'pos.operate_terminal\') gates the route. Tenant identity flows through the terminal\'s tenant_id column at the SQL layer.')]
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
    #[CrossTenantRoute(reason: 'Terminal-scoped POS sync: same shape as pullVouchers — terminal_id from query is the scoping anchor enforced at the SQL layer; ledger rows filtered to vouchers redeemable at the requesting terminal.')]
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
    #[CrossTenantRoute(reason: 'Terminal-scoped POS sync: same shape as pullVouchers — terminal_id from query is the scoping anchor; QR-index rows filtered to fiscalised receipts at the requesting terminal.')]
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
    #[CrossTenantRoute(reason: 'Terminal-scoped POS sync: each entry carries terminal_id (validated at the push handler — `the push handler still enforces the match` per docblock). VoucherLedgerPushService::push validates terminal_id against the voucher\'s redeemable_at_terminal_id at the SQL layer; entries with mismatched terminal are rejected. Idempotent on client-supplied UUID; gated by Gate::authorize(\'pos.operate_terminal\').')]
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
            // FormRequest validates `event` against VoucherEvent case names,
            // so fromArray() cannot fail here for the FormRequest path.
            $payload = VoucherLedgerPushPayload::fromArray($rawEntry);

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
