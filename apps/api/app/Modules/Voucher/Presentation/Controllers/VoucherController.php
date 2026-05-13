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
        $tenantId = $user->tenant_id;
        $companyId = $this->companyContext->requireCompanyId();

        $perPage = min((int) ($request->query('per_page', '20')), 100);

        $query = Voucher::query()
            ->where('tenant_id', $tenantId)
            ->where('company_id', $companyId)
            ->with(['partner', 'issuedAtTerminal', 'issuedBy'])
            ->orderByDesc('issued_at');

        if ($request->filled('source')) {
            $sourceValue = $request->query('source');
            if (is_string($sourceValue)) {
                $source = VoucherSource::tryFrom($sourceValue);
                if ($source === null) {
                    return response()->json([
                        'error' => [
                            'code' => 'INVALID_SOURCE',
                            'message' => 'Invalid source value. Must be one of: '
                                .implode(', ', array_column(VoucherSource::cases(), 'value')),
                        ],
                    ], 422);
                }
                $query->where('source', $source->value);
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
            'data' => array_map(
                fn (Voucher $v): array => $this->formatVoucher($v),
                $paginator->items()
            ),
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
        $tenantId = $user->tenant_id;
        $companyId = $this->companyContext->requireCompanyId();

        if (! Str::isUuid($id)) {
            return response()->json([
                'error' => ['code' => 'INVALID_ID', 'message' => 'Invalid voucher ID format.'],
            ], 422);
        }

        $voucher = Voucher::with([
            'ledger.user',
            'ledger.terminal',
            'ledger.receipt',
            'issuedBy',
            'partner',
            'issuedToPartner',
            'sourceReceipt',
            'issuedAtTerminal',
        ])
            ->where('tenant_id', $tenantId)
            ->where('company_id', $companyId)
            ->find($id);

        if ($voucher === null) {
            return response()->json([
                'error' => ['code' => 'VOUCHER_NOT_FOUND', 'message' => 'Voucher not found.'],
            ], 404);
        }

        $data = $this->formatVoucher($voucher);
        $data['ledger'] = $voucher->ledger
            ->map(fn (VoucherLedger $row): array => $this->formatLedger($row))
            ->all();
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
        $tenantId = $user->tenant_id;
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
            tenantId: $tenantId,
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
        $tenantId = $user->tenant_id;
        $companyId = $this->companyContext->requireCompanyId();

        if (! Str::isUuid($id)) {
            return response()->json([
                'error' => ['code' => 'INVALID_ID', 'message' => 'Invalid voucher ID format.'],
            ], 422);
        }

        $voucher = Voucher::where('tenant_id', $tenantId)
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
        $tenantId = $user->tenant_id;
        $companyId = $this->companyContext->requireCompanyId();

        if (! Str::isUuid($id)) {
            return response()->json([
                'error' => ['code' => 'INVALID_ID', 'message' => 'Invalid voucher ID format.'],
            ], 422);
        }

        $voucher = Voucher::where('tenant_id', $tenantId)
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
            $voucher->override_reason = $reason;
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
        $tenantId = $user->tenant_id;
        $companyId = $this->companyContext->requireCompanyId();

        if (! Str::isUuid($id)) {
            return response()->json([
                'error' => ['code' => 'INVALID_ID', 'message' => 'Invalid voucher ID format.'],
            ], 422);
        }

        $voucher = Voucher::where('tenant_id', $tenantId)
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

        DB::transaction(function () use ($voucher, $user, $newExpiresAt, $reason): void {
            $parsedExpiry = Carbon::parse($newExpiresAt);

            // Metadata-only ledger row. amount=0.00000 — no GL impact, no GL journal
            // entry (gl_journal_entry_id stays null). Symmetrical with the Transferred
            // event written by transfer() above; both record administrative state
            // changes that must remain reconstructible from the ledger projection.
            // Codex review m2 (2026-04-30).
            VoucherLedger::forceCreate([
                'id' => (string) Str::uuid(),
                'tenant_id' => $voucher->tenant_id,
                'company_id' => $voucher->company_id,
                'voucher_id' => $voucher->id,
                'event' => VoucherEvent::ExpiryExtended,
                'amount' => '0.00000',
                'currency' => $voucher->currency,
                'receipt_id' => null,
                'terminal_id' => null,
                'user_id' => $user->id,
                'gl_journal_entry_id' => null,
                'authorized_by_user_id' => null,
                'policy_trigger' => 'manual_expiry_extension',
                'reverses_voucher_ledger_id' => null,
                'occurred_at' => Carbon::now(),
            ]);

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
     * Map a Voucher model to the denormalized response shape the frontend expects.
     *
     * Relies on partner, issuedAtTerminal, and issuedBy being already eager-loaded.
     *
     * @return array<string, mixed>
     */
    private function formatVoucher(Voucher $voucher): array
    {
        return [
            'id' => $voucher->id,
            'code' => $voucher->code,
            'source' => $voucher->source->value,
            'status' => $voucher->status->value,
            'redemption_mode' => $voucher->redemption_mode->value,
            'voucher_kind' => $voucher->voucher_kind->value,
            'currency' => $voucher->currency,
            'initial_balance' => $voucher->initial_balance,
            'current_balance' => $voucher->current_balance,
            'issued_at' => $voucher->issued_at->toIso8601String(),
            'expires_at' => $voucher->expires_at?->toIso8601String(),
            'created_at' => $voucher->created_at->toIso8601String(),
            'updated_at' => $voucher->updated_at->toIso8601String(),
            'partner_id' => $voucher->partner_id,
            'partner_name' => $voucher->partner?->name,
            'cashier_id' => $voucher->issued_by_user_id,
            'cashier_name' => $voucher->issuedBy->name,
            'terminal_id' => $voucher->issued_at_terminal_id,
            'terminal_name' => $voucher->issuedAtTerminal?->name,
            'notes' => $voucher->notes,
            'override_reason' => $voucher->override_reason,
            'policy_trigger' => $voucher->policy_trigger,
            'authorized_by_user_id' => $voucher->authorized_by_user_id,
            'source_receipt_id' => $voucher->source_receipt_id,
            'source_loyalty_transaction_id' => $voucher->source_loyalty_transaction_id,
            'source_promotional_campaign_id' => $voucher->source_promotional_campaign_id,
            'issued_to_partner_id' => $voucher->issued_to_partner_id,
        ];
    }

    /**
     * Map a VoucherLedger row to the denormalized shape the LedgerHistoryTable expects.
     *
     * Relies on user, terminal, and receipt being already eager-loaded.
     *
     * @return array<string, mixed>
     */
    private function formatLedger(VoucherLedger $row): array
    {
        return [
            'id' => $row->id,
            'event' => $row->event->value,
            'amount' => $row->amount,
            'receipt_id' => $row->receipt_id,
            'receipt_number' => $row->receipt?->receipt_number,
            'terminal_id' => $row->terminal_id,
            'terminal_name' => $row->terminal?->name,
            'user_id' => $row->user_id,
            'user_name' => $row->user->name,
            'policy_trigger' => $row->policy_trigger,
            'notes' => null,
            'occurred_at' => $row->occurred_at->toIso8601String(),
        ];
    }

    /**
     * Build the provenance object for a voucher based on its source.
     *
     * The source discriminator lives at top-level (voucher.source) — do NOT
     * repeat it inside this payload. The ProvenanceSection in the frontend
     * switches on voucher.source and reads fields from this object directly.
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
