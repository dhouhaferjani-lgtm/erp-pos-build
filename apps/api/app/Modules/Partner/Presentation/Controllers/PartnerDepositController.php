<?php

declare(strict_types=1);

namespace App\Modules\Partner\Presentation\Controllers;

use App\Modules\Company\Services\CompanyContext;
use App\Modules\Identity\Domain\User;
use App\Modules\Partner\Application\Services\RecordCustomerDepositService;
use App\Modules\Partner\Domain\Partner;
use App\Modules\Partner\Presentation\Requests\RecordDepositRequest;
use App\Modules\POS\Application\Services\DepositReceiptQueryService;
use App\Shared\Domain\CurrencyScale;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Back-office customer-account deposits — `partners/{partner}/deposits`.
 *
 * POST records a payment toward the customer's account (settle FIFO, overflow to
 * credit) and returns the receipt + allocation summary + refreshed balances.
 * GET returns the paginated deposit history. Both are gated by the
 * `payments.create` / `payments.view` permissions on the routes.
 */
final class PartnerDepositController
{
    public function __construct(
        private readonly CompanyContext $companyContext,
        private readonly RecordCustomerDepositService $recordService,
        private readonly DepositReceiptQueryService $historyService,
    ) {}

    public function store(RecordDepositRequest $request, string $partner): JsonResponse
    {
        $company = $this->companyContext->requireCompany();
        /** @var User $user */
        $user = $request->user();

        $partnerModel = Partner::query()
            ->where('company_id', $company->id)
            ->where('id', $partner)
            ->first();

        if (! $partnerModel instanceof Partner) {
            return $this->error($request, 'PARTNER_NOT_FOUND', 'Partner not found', 404);
        }

        if (! $partnerModel->isCustomer()) {
            return $this->error(
                $request,
                'PARTNER_NOT_CUSTOMER',
                'Deposits can only be recorded against a customer account.',
                422,
            );
        }

        $validated = $request->validated();
        $currency = is_string($validated['currency'] ?? null) && $validated['currency'] !== ''
            ? $validated['currency']
            : $company->currency;

        // Currency-aware amount check at the boundary: a zero / over-precise
        // amount is a client validation error (422), not a fiscal-authoring 500.
        $amountError = $this->amountError((string) $validated['amount'], $currency);
        if ($amountError !== null) {
            return $this->error($request, 'INVALID_AMOUNT', $amountError, 422);
        }

        $result = $this->recordService->record(
            partner: $partnerModel,
            actorUserId: $user->id,
            actorName: $user->name,
            currencyCode: $currency,
            amount: $validated['amount'],
            methodCode: $validated['payment_method_code'],
            repositoryId: $validated['repository_id'],
            notes: $validated['note'] ?? null,
        );

        return response()->json([
            'data' => $result->toArray(),
            'meta' => $this->meta($request),
        ], 201);
    }

    public function index(Request $request, string $partner): JsonResponse
    {
        $company = $this->companyContext->requireCompany();

        $partnerExists = Partner::query()
            ->where('company_id', $company->id)
            ->where('id', $partner)
            ->exists();

        if (! $partnerExists) {
            return $this->error($request, 'PARTNER_NOT_FOUND', 'Partner not found', 404);
        }

        $perPage = (int) $request->integer('per_page', 20);
        $perPage = max(1, min($perPage, 100));

        $history = $this->historyService->historyForPartner(
            tenantId: $company->tenant_id,
            companyId: $company->id,
            partnerId: $partner,
            perPage: $perPage,
        );

        return response()->json([
            'data' => $history['data'],
            'meta' => $history['meta'] + $this->meta($request),
        ]);
    }

    /**
     * Currency-aware validation of the deposit amount string (already matched to a
     * plain non-negative decimal by the FormRequest). Returns a human message when
     * the amount is not strictly positive or carries more precision than the
     * currency scale; null when acceptable.
     */
    private function amountError(string $amount, string $currency): ?string
    {
        // The FormRequest already matched a plain non-negative decimal; the
        // is_numeric guard is therefore always true here and narrows $amount to
        // numeric-string for the bccomp() below.
        if (! is_numeric($amount)) {
            return 'Amount must be a valid number.';
        }

        $scale = CurrencyScale::for($currency);

        $dot = strpos($amount, '.');
        if ($dot !== false) {
            $fraction = substr($amount, $dot + 1);
            if (strlen($fraction) > $scale && rtrim(substr($fraction, $scale), '0') !== '') {
                return sprintf('Amount precision exceeds the %s currency scale (%d decimals).', $currency, $scale);
            }
        }

        if (bccomp($amount, '0', $scale) <= 0) {
            return 'Amount must be greater than zero.';
        }

        return null;
    }

    private function error(Request $request, string $code, string $message, int $status): JsonResponse
    {
        return response()->json([
            'error' => ['code' => $code, 'message' => $message],
            'meta' => $this->meta($request),
        ], $status);
    }

    /**
     * @return array{timestamp: string, request_id: string}
     */
    private function meta(Request $request): array
    {
        return [
            'timestamp' => now()->toIso8601String(),
            'request_id' => $request->header('X-Request-ID', (string) uuid_create()),
        ];
    }
}
