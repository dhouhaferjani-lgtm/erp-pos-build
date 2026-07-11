<?php

declare(strict_types=1);

namespace App\Modules\Treasury\Presentation\Controllers;

use App\Modules\Company\Services\CompanyContext;
use App\Modules\Treasury\Application\DTOs\BounceInstrumentData;
use App\Modules\Treasury\Application\DTOs\ClearInstrumentData;
use App\Modules\Treasury\Application\Services\InstrumentLifecycleService;
use App\Modules\Treasury\Application\Services\InstrumentRemittanceService;
use App\Modules\Treasury\Domain\Enums\DishonorRouting;
use App\Modules\Treasury\Domain\Enums\InstrumentKind;
use App\Modules\Treasury\Domain\Enums\RemittanceType;
use App\Modules\Treasury\Domain\InstrumentRemittance;
use App\Modules\Treasury\Domain\InstrumentRemittanceLine;
use App\Shared\Presentation\Validation\ScopedExists;
use DomainException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Routing\Controller;
use Illuminate\Support\Str;

final class InstrumentRemittanceController extends Controller
{
    public function __construct(
        private readonly CompanyContext $companyContext,
        private readonly InstrumentRemittanceService $remittances,
        private readonly InstrumentLifecycleService $lifecycle,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $company = $this->companyContext->requireCompany();
        $validated = $request->validate([
            'status' => ['nullable', 'in:draft,remitted,closed'],
            'instrument_kind' => ['nullable', 'in:cheque,effet'],
            'bank_repository_id' => ['nullable', 'uuid'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);
        $query = InstrumentRemittance::query()
            ->where('tenant_id', $company->tenant_id)
            ->where('company_id', $company->id)
            ->with(['bankRepository', 'lines.instrument']);
        foreach (['status', 'instrument_kind', 'bank_repository_id'] as $field) {
            if (isset($validated[$field])) {
                $query->where($field, $validated[$field]);
            }
        }
        /** @var LengthAwarePaginator<int, InstrumentRemittance> $paginator */
        $paginator = $query->orderByDesc('created_at')->orderByDesc('id')
            ->paginate((int) ($validated['per_page'] ?? 25));

        return response()->json([
            'data' => collect($paginator->items())->map(fn (InstrumentRemittance $slip): array => $this->formatSlip($slip)),
            'meta' => [
                'current_page' => $paginator->currentPage(),
                'last_page' => $paginator->lastPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
            ],
        ]);
    }

    public function show(string $id): JsonResponse
    {
        $slip = $this->findSlip($id);
        $slip->load(['bankRepository', 'lines.instrument']);

        return response()->json(['data' => $this->formatSlip($slip)]);
    }

    public function store(Request $request): JsonResponse
    {
        $company = $this->companyContext->requireCompany();
        $validated = $request->validate([
            'bank_repository_id' => [
                'required', 'uuid',
                ScopedExists::tenantAndCompany('payment_repositories', $company->tenant_id, $company->id),
            ],
            'remittance_type' => ['required', 'in:collection,discount'],
            'instrument_kind' => ['required', 'in:cheque,effet'],
        ]);
        try {
            $slip = $this->remittances->createDraft(
                companyId: $company->id,
                tenantId: $company->tenant_id,
                bankRepositoryId: $validated['bank_repository_id'],
                type: RemittanceType::from($validated['remittance_type']),
                kind: InstrumentKind::from($validated['instrument_kind']),
                userId: $request->user()?->id,
            );
        } catch (DomainException $exception) {
            return $this->domainError($exception);
        }
        $slip->load(['bankRepository', 'lines.instrument']);

        return response()->json(['data' => $this->formatSlip($slip)], 201);
    }

    public function addLine(Request $request, string $id): JsonResponse
    {
        $slip = $this->findSlip($id);
        $company = $this->companyContext->requireCompany();
        $validated = $request->validate([
            'instrument_id' => [
                'required', 'uuid',
                ScopedExists::tenantAndCompany('payment_instruments', $company->tenant_id, $company->id),
            ],
        ]);
        try {
            $line = $this->remittances->addLine($slip->id, $validated['instrument_id']);
        } catch (DomainException $exception) {
            return $this->domainError($exception);
        }
        $line->load('instrument');

        return response()->json(['data' => $this->formatLine($line)], 201);
    }

    public function removeLine(string $id, string $lineId): JsonResponse
    {
        $slip = $this->findSlip($id);
        $line = $this->findLine($slip, $lineId);
        try {
            $this->remittances->removeLine($slip->id, $line->id);
        } catch (DomainException $exception) {
            return $this->domainError($exception);
        }

        return response()->json(null, 204);
    }

    public function remit(Request $request, string $id): JsonResponse
    {
        $slip = $this->findSlip($id);
        try {
            $slip = $this->remittances->remit($slip->id, $request->user()?->id);
        } catch (DomainException $exception) {
            return $this->domainError($exception);
        }
        $slip->load(['bankRepository', 'lines.instrument']);

        return response()->json(['data' => $this->formatSlip($slip)]);
    }

    public function clearLine(Request $request, string $id, string $lineId): JsonResponse
    {
        $slip = $this->findSlip($id);
        $line = $this->findLine($slip, $lineId);
        $validated = $request->validate([
            'fee_amount' => ['nullable', 'string', 'regex:/^\d+(\.\d{1,3})?$/'],
            'fee_vat_amount' => ['nullable', 'string', 'regex:/^\d+(\.\d{1,3})?$/'],
            'value_date' => ['nullable', 'date'],
        ]);
        $line->load('instrument');
        try {
            $this->lifecycle->clear(new ClearInstrumentData(
                instrumentId: $line->instrument_id,
                currency: $line->instrument->currency,
                feeAmount: $validated['fee_amount'] ?? '0.000',
                feeVatAmount: $validated['fee_vat_amount'] ?? '0.000',
                valueDate: $validated['value_date'] ?? null,
                userId: $request->user()?->id,
            ));
        } catch (DomainException $exception) {
            return $this->domainError($exception);
        }
        $line->refresh()->load('instrument');

        return response()->json(['data' => $this->formatLine($line)]);
    }

    public function bounceLine(Request $request, string $id, string $lineId): JsonResponse
    {
        $slip = $this->findSlip($id);
        $line = $this->findLine($slip, $lineId);
        $validated = $request->validate([
            'routing' => ['required', 'in:re_present,receivable,doubtful'],
            'fee_amount' => ['nullable', 'string', 'regex:/^\d+(\.\d{1,3})?$/'],
            'fee_vat_amount' => ['nullable', 'string', 'regex:/^\d+(\.\d{1,3})?$/'],
            'reason' => ['nullable', 'string', 'max:255'],
        ]);
        $line->load('instrument');
        try {
            $this->lifecycle->bounce(new BounceInstrumentData(
                instrumentId: $line->instrument_id,
                routing: DishonorRouting::from($validated['routing']),
                currency: $line->instrument->currency,
                feeAmount: $validated['fee_amount'] ?? '0.000',
                feeVatAmount: $validated['fee_vat_amount'] ?? '0.000',
                reason: $validated['reason'] ?? null,
                userId: $request->user()?->id,
            ));
        } catch (DomainException $exception) {
            return $this->domainError($exception);
        }
        $line->refresh()->load('instrument');

        return response()->json(['data' => $this->formatLine($line)]);
    }

    private function findSlip(string $id): InstrumentRemittance
    {
        if (! Str::isUuid($id)) {
            abort(404);
        }
        $company = $this->companyContext->requireCompany();

        return InstrumentRemittance::query()
            ->where('tenant_id', $company->tenant_id)
            ->where('company_id', $company->id)
            ->whereKey($id)
            ->firstOrFail();
    }

    private function findLine(InstrumentRemittance $slip, string $lineId): InstrumentRemittanceLine
    {
        if (! Str::isUuid($lineId)) {
            abort(404);
        }

        return InstrumentRemittanceLine::query()
            ->where('remittance_id', $slip->id)
            ->whereKey($lineId)
            ->firstOrFail();
    }

    private function domainError(DomainException $exception): JsonResponse
    {
        return response()->json([
            'error' => ['code' => 'BUSINESS_ERROR', 'message' => $exception->getMessage()],
        ], 422);
    }

    /** @return array<string, mixed> */
    private function formatSlip(InstrumentRemittance $slip): array
    {
        return [
            'id' => $slip->id,
            'number' => $slip->number,
            'remittance_type' => $slip->remittance_type->value,
            'instrument_kind' => $slip->instrument_kind->value,
            'bank_repository_id' => $slip->bank_repository_id,
            'bank_repository' => [
                'id' => $slip->bankRepository->id,
                'code' => $slip->bankRepository->code,
                'name' => $slip->bankRepository->name,
            ],
            'status' => $slip->status->value,
            'remitted_at' => $slip->remitted_at?->toIso8601String(),
            'journal_entry_id' => $slip->journal_entry_id,
            'lines' => $slip->lines->map(fn (InstrumentRemittanceLine $line): array => $this->formatLine($line))->values(),
            'created_at' => $slip->created_at?->toIso8601String(),
        ];
    }

    /** @return array<string, mixed> */
    private function formatLine(InstrumentRemittanceLine $line): array
    {
        return [
            'id' => $line->id,
            'remittance_id' => $line->remittance_id,
            'instrument_id' => $line->instrument_id,
            'amount' => $line->amount,
            'line_status' => $line->line_status->value,
            'cleared_at' => $line->cleared_at?->toIso8601String(),
            'bounced_at' => $line->bounced_at?->toIso8601String(),
            'instrument' => [
                'id' => $line->instrument->id,
                'reference' => $line->instrument->reference,
                'amount' => $line->instrument->amount,
                'currency' => $line->instrument->currency,
                'status' => $line->instrument->status->value,
            ],
        ];
    }
}
