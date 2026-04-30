<?php

declare(strict_types=1);

namespace App\Modules\Voucher\Presentation\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Company\Services\CompanyContext;
use App\Modules\Identity\Domain\User;
use App\Modules\Voucher\Application\DTOs\VoucherIssuanceRequest;
use App\Modules\Voucher\Application\Services\VoucherIssuanceService;
use App\Modules\Voucher\Domain\Enums\RedemptionMode;
use App\Modules\Voucher\Domain\Enums\VoucherEvent;
use App\Modules\Voucher\Domain\Enums\VoucherSource;
use App\Modules\Voucher\Domain\Enums\VoucherStatus;
use App\Modules\Voucher\Domain\Exceptions\GoodwillFourEyesRequiredException;
use App\Modules\Voucher\Domain\Exceptions\GoodwillRequiresNamedCustomerException;
use App\Modules\Voucher\Domain\Voucher;
use App\Modules\Voucher\Domain\VoucherLedger;
use App\Modules\Voucher\Presentation\Requests\ExtendExpiryRequest;
use App\Modules\Voucher\Presentation\Requests\IssueGoodwillRequest;
use App\Modules\Voucher\Presentation\Requests\TransferVoucherRequest;
use App\Modules\Voucher\Presentation\Requests\VoidVoucherRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;

/**
 * Voucher back-office management controller.
 *
 * Provides read and write operations on vouchers for the back-office web app.
 * All endpoints are tenant + company scoped via CompanyContext + auth user.
 *
 * Permissions are enforced on the routes themselves (see routes.php).
 */
final class VoucherController extends Controller
{
    public function __construct(
        private readonly CompanyContext $companyContext,
        private readonly VoucherIssuanceService $issuanceService,
    ) {}

    /**
     * GET /api/v1/vouchers
     *
     * Paginated list of vouchers for the authenticated company.
     * Query params: source, status, partner_id, search, page, per_page (default 20, max 100).
     *
     * Requires either pos.void_voucher OR pos.issue_goodwill_voucher permission.
     */
    public function index(Request $request): JsonResponse
    {
        if (! Gate::any(['pos.void_voucher', 'pos.issue_goodwill_voucher'])) {
            abort(403);
        }

        /** @var User $user */
        $user = $request->user();
        $companyId = $this->companyContext->requireCompanyId();

        $perPage = min((int) ($request->query('per_page', '20')), 100);

        $query = Voucher::query()
            ->where('tenant_id', $user->tenant_id)
            ->where('company_id', $companyId)
            ->orderByDesc('issued_at');

        if ($request->filled('source')) {
            $sourceValue = $request->query('source');
            if (is_string($sourceValue)) {
                $source = VoucherSource::tryFrom($sourceValue);
                if ($source !== null) {
                    $query->where('source', $source->value);
                }
            }
        }

        if ($request->filled('status')) {
            $statusValue = $request->query('status');
            if (is_string($statusValue)) {
                $status = VoucherStatus::tryFrom($statusValue);
                if ($status !== null) {
                    $query->where('status', $status->value);
                }
            }
        }

        if ($request->filled('partner_id')) {
            $partnerId = $request->query('partner_id');
            if (is_string($partnerId) && Str::isUuid($partnerId)) {
                $query->where('partner_id', $partnerId);
            }
        }

        if ($request->filled('search')) {
            $search = $request->query('search');
            if (is_string($search)) {
                $query->where(function ($q) use ($search): void {
                    $q->where('code', 'like', '%'.$search.'%')
                        ->orWhere('notes', 'like', '%'.$search.'%');
                });
            }
        }

        $paginator = $query->paginate($perPage);

        return response()->json([
            'data' => $paginator->items(),
            'meta' => [
                'current_page' => $paginator->currentPage(),
                'last_page' => $paginator->lastPage(),
                'total' => $paginator->total(),
                'per_page' => $paginator->perPage(),
            ],
        ]);
    }

    /**
     * GET /api/v1/vouchers/{id}
     *
     * Single voucher with full ledger history and provenance fields.
     *
     * Requires either pos.void_voucher OR pos.issue_goodwill_voucher permission.
     */
    public function show(Request $request, string $id): JsonResponse
    {
        if (! Gate::any(['pos.void_voucher', 'pos.issue_goodwill_voucher'])) {
            abort(403);
        }

        /** @var User $user */
        $user = $request->user();
        $companyId = $this->companyContext->requireCompanyId();

        if (! Str::isUuid($id)) {
            return response()->json([
                'error' => ['code' => 'INVALID_ID', 'message' => 'Invalid voucher ID format.'],
            ], 422);
        }

        $voucher = Voucher::with([
            'ledger',
            'issuedBy',
            'partner',
            'issuedToPartner',
            'sourceReceipt',
            'issuedAtTerminal',
        ])
            ->where('tenant_id', $user->tenant_id)
            ->where('company_id', $companyId)
            ->find($id);

        if ($voucher === null) {
            return response()->json([
                'error' => ['code' => 'VOUCHER_NOT_FOUND', 'message' => 'Voucher not found.'],
            ], 404);
        }

        $data = $voucher->toArray();
        $data['ledger'] = $voucher->ledger->toArray();
        $data['provenance'] = $this->buildProvenance($voucher);

        return response()->json(['data' => $data]);
    }

    /**
     * POST /api/v1/vouchers/issue-goodwill
     *
     * Issue a discretionary goodwill voucher.
     * Calls VoucherIssuanceService::issueGoodwill().
     */
    public function issueGoodwill(IssueGoodwillRequest $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        $companyId = $this->companyContext->requireCompanyId();

        $validated = $request->validated();

        /** @var numeric-string $amountRaw */
        $amountRaw = (string) $validated['amount'];
        /** @var string $currency */
        $currency = (string) $validated['currency'];

        $expiresAt = isset($validated['expires_at']) && is_string($validated['expires_at'])
            ? Carbon::parse($validated['expires_at'])
            : null;

        /** @var string|null $partnerId */
        $partnerId = isset($validated['partner_id']) && is_string($validated['partner_id'])
            ? $validated['partner_id']
            : null;

        /** @var string|null $terminalId */
        $terminalId = isset($validated['terminal_id']) && is_string($validated['terminal_id'])
            ? $validated['terminal_id']
            : null;

        /** @var string|null $secondAdminUserId */
        $secondAdminUserId = isset($validated['second_admin_user_id']) && is_string($validated['second_admin_user_id'])
            ? $validated['second_admin_user_id']
            : null;

        $redemptionMode = RedemptionMode::from($validated['redemption_mode']);

        /** @var string|null $notes */
        $notes = isset($validated['notes']) && is_string($validated['notes'])
            ? $validated['notes']
            : null;

        $dto = new VoucherIssuanceRequest(
            amount: $amountRaw,
            currency: $currency,
            tenantId: $user->tenant_id,
            companyId: $companyId,
            issuedByUserId: $user->id,
            sourceReceiptId: null,
            issuedToPartnerId: $partnerId,
            issuedAtTerminalId: $terminalId,
            expiresAt: $expiresAt,
            notes: $notes,
            authorizedByUserId: $secondAdminUserId,
            overrideReason: null,
            policyTrigger: 'goodwill_back_office',
            redemptionMode: $redemptionMode,
        );

        try {
            $voucher = $this->issuanceService->issueGoodwill($dto);
        } catch (GoodwillFourEyesRequiredException $e) {
            return response()->json([
                'error' => [
                    'code' => 'FOUR_EYES_REQUIRED',
                    'message' => $e->getMessage(),
                ],
            ], 422);
        } catch (GoodwillRequiresNamedCustomerException $e) {
            return response()->json([
                'error' => [
                    'code' => 'NAMED_CUSTOMER_REQUIRED',
                    'message' => $e->getMessage(),
                ],
            ], 422);
        }

        return response()->json(['data' => $voucher->toArray()], 201);
    }

    /**
     * POST /api/v1/vouchers/{id}/void
     *
     * Void a voucher. Writes a Voided ledger event.
     * Returns 422 when the voucher has redemptions (cascade block).
     */
    public function void(VoidVoucherRequest $request, string $id): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        $companyId = $this->companyContext->requireCompanyId();

        if (! Str::isUuid($id)) {
            return response()->json([
                'error' => ['code' => 'INVALID_ID', 'message' => 'Invalid voucher ID format.'],
            ], 422);
        }

        $voucher = Voucher::where('tenant_id', $user->tenant_id)
            ->where('company_id', $companyId)
            ->find($id);

        if ($voucher === null) {
            return response()->json([
                'error' => ['code' => 'VOUCHER_NOT_FOUND', 'message' => 'Voucher not found.'],
            ], 404);
        }

        if (in_array($voucher->status, [VoucherStatus::Voided, VoucherStatus::FullyRedeemed], true)) {
            return response()->json([
                'error' => [
                    'code' => 'VOUCHER_ALREADY_TERMINAL',
                    'message' => 'Voucher is already voided or fully redeemed.',
                ],
            ], 422);
        }

        // Block if any redemptions exist (cascade-block logic, Phase 1).
        $hasRedemptions = VoucherLedger::where('voucher_id', $voucher->id)
            ->whereIn('event', [VoucherEvent::Redeemed->value, VoucherEvent::PartiallyRedeemed->value])
            ->exists();

        if ($hasRedemptions) {
            return response()->json([
                'error' => [
                    'code' => 'VOUCHER_HAS_REDEMPTIONS',
                    'message' => 'Cannot void a voucher that has been partially or fully redeemed.',
                ],
            ], 422);
        }

        $validated = $request->validated();

        /** @var string $reason */
        $reason = $validated['reason'];

        DB::transaction(function () use ($voucher, $user, $reason): void {
            $now = Carbon::now();
            $voidedBalance = $voucher->current_balance;

            $voidedAmount = bccomp((string) $voidedBalance, '0', 5) > 0
                ? bcmul((string) $voidedBalance, '-1', 5)
                : '0.00000';

            VoucherLedger::forceCreate([
                'id' => (string) Str::uuid(),
                'tenant_id' => $voucher->tenant_id,
                'company_id' => $voucher->company_id,
                'voucher_id' => $voucher->id,
                'event' => VoucherEvent::Voided,
                'amount' => $voidedAmount,
                'currency' => $voucher->currency,
                'receipt_id' => null,
                'terminal_id' => null,
                'user_id' => $user->id,
                'gl_journal_entry_id' => null,
                'authorized_by_user_id' => null,
                'policy_trigger' => 'manual_void',
                'reverses_voucher_ledger_id' => null,
                'occurred_at' => $now,
            ]);

            $noteEntry = '[VOID '.now()->toDateString().'] '.$reason;
            $voucher->notes = $voucher->notes !== null
                ? $voucher->notes."\n".$noteEntry
                : $noteEntry;
            $voucher->override_reason = $reason;
            $voucher->status = VoucherStatus::Voided;
            $voucher->current_balance = '0.00000';
            $voucher->save();
        });

        $voucher->refresh();

        return response()->json(['data' => $voucher->toArray()]);
    }

    /**
     * POST /api/v1/vouchers/{id}/transfer
     *
     * Transfer a voucher to a different partner (current holder).
     * Writes a Transferred ledger event.
     */
    public function transfer(TransferVoucherRequest $request, string $id): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        $companyId = $this->companyContext->requireCompanyId();

        if (! Str::isUuid($id)) {
            return response()->json([
                'error' => ['code' => 'INVALID_ID', 'message' => 'Invalid voucher ID format.'],
            ], 422);
        }

        $voucher = Voucher::where('tenant_id', $user->tenant_id)
            ->where('company_id', $companyId)
            ->find($id);

        if ($voucher === null) {
            return response()->json([
                'error' => ['code' => 'VOUCHER_NOT_FOUND', 'message' => 'Voucher not found.'],
            ], 404);
        }

        $validated = $request->validated();

        /** @var string $toPartnerId */
        $toPartnerId = $validated['to_partner_id'];

        /** @var string $reason */
        $reason = $validated['reason'];

        DB::transaction(function () use ($voucher, $user, $toPartnerId, $reason): void {
            $now = Carbon::now();

            VoucherLedger::forceCreate([
                'id' => (string) Str::uuid(),
                'tenant_id' => $voucher->tenant_id,
                'company_id' => $voucher->company_id,
                'voucher_id' => $voucher->id,
                'event' => VoucherEvent::Transferred,
                'amount' => '0.00000',
                'currency' => $voucher->currency,
                'receipt_id' => null,
                'terminal_id' => null,
                'user_id' => $user->id,
                'gl_journal_entry_id' => null,
                'authorized_by_user_id' => null,
                'policy_trigger' => 'manual_transfer',
                'reverses_voucher_ledger_id' => null,
                'occurred_at' => $now,
            ]);

            $noteEntry = '[TRANSFER '.now()->toDateString().' → '.$toPartnerId.'] '.$reason;
            $voucher->notes = $voucher->notes !== null
                ? $voucher->notes."\n".$noteEntry
                : $noteEntry;
            $voucher->partner_id = $toPartnerId;
            $voucher->save();
        });

        $voucher->refresh();

        return response()->json(['data' => $voucher->toArray()]);
    }

    /**
     * POST /api/v1/vouchers/{id}/extend-expiry
     *
     * Extend the expiry date of a voucher.
     */
    public function extendExpiry(ExtendExpiryRequest $request, string $id): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        $companyId = $this->companyContext->requireCompanyId();

        if (! Str::isUuid($id)) {
            return response()->json([
                'error' => ['code' => 'INVALID_ID', 'message' => 'Invalid voucher ID format.'],
            ], 422);
        }

        $voucher = Voucher::where('tenant_id', $user->tenant_id)
            ->where('company_id', $companyId)
            ->find($id);

        if ($voucher === null) {
            return response()->json([
                'error' => ['code' => 'VOUCHER_NOT_FOUND', 'message' => 'Voucher not found.'],
            ], 404);
        }

        $validated = $request->validated();

        /** @var string $newExpiresAt */
        $newExpiresAt = $validated['new_expires_at'];

        /** @var string $reason */
        $reason = $validated['reason'];

        DB::transaction(function () use ($voucher, $newExpiresAt, $reason): void {
            $parsedExpiry = Carbon::parse($newExpiresAt);

            $noteEntry = '[EXPIRY-EXTENDED '.now()->toDateString().' → '.$parsedExpiry->toDateString().'] '.$reason;
            $voucher->notes = $voucher->notes !== null
                ? $voucher->notes."\n".$noteEntry
                : $noteEntry;
            $voucher->expires_at = $parsedExpiry;
            $voucher->save();
        });

        $voucher->refresh();

        return response()->json(['data' => $voucher->toArray()]);
    }

    // -------------------------------------------------------------------------
    // Private helpers
    // -------------------------------------------------------------------------

    /**
     * Build the provenance object for a voucher based on its source.
     *
     * @return array<string, mixed>
     */
    private function buildProvenance(Voucher $voucher): array
    {
        return match ($voucher->source) {
            VoucherSource::Refund, VoucherSource::ExchangeSurplus => [
                'source_receipt_id' => $voucher->source_receipt_id,
                'source_receipt_number' => $voucher->sourceReceipt?->receipt_number,
                'credit_note_link' => null,
            ],
            VoucherSource::Goodwill => [
                'issued_by_user_name' => $voucher->issuedBy->name,
                'notes' => $voucher->notes,
                'authorized_by_user_id' => $voucher->authorized_by_user_id,
                'override_reason' => $voucher->override_reason,
            ],
            VoucherSource::LoyaltyCredit => [
                'source_loyalty_transaction_id' => $voucher->source_loyalty_transaction_id,
            ],
            default => [],
        };
    }
}
