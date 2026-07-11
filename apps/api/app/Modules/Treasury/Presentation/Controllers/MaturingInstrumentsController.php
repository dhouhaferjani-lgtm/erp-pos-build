<?php

declare(strict_types=1);

namespace App\Modules\Treasury\Presentation\Controllers;

use App\Modules\Company\Services\CompanyContext;
use App\Modules\Treasury\Domain\Enums\InstrumentDirection;
use App\Modules\Treasury\Domain\Enums\InstrumentStatus;
use App\Modules\Treasury\Domain\PaymentInstrument;
use App\Shared\Contracts\CurrencyScaleResolverInterface;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

final class MaturingInstrumentsController extends Controller
{
    public function __construct(
        private readonly CompanyContext $companyContext,
        private readonly CurrencyScaleResolverInterface $scaleResolver,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $company = $this->companyContext->requireCompany();
        $validated = $request->validate([
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date', 'after_or_equal:from'],
            'direction' => ['nullable', 'in:inbound,outbound'],
            'kind' => ['nullable', 'in:cheque,effet,other'],
            'repository_id' => ['nullable', 'uuid'],
            'partner_id' => ['nullable', 'uuid'],
            'needs_details' => ['nullable', 'in:true,false,1,0'],
        ]);

        $query = PaymentInstrument::query()
            ->where('tenant_id', $company->tenant_id)
            ->where('company_id', $company->id)
            ->whereIn('status', [InstrumentStatus::Received, InstrumentStatus::Deposited])
            ->with(['paymentMethod', 'partner', 'repository', 'depositedTo']);

        foreach (['direction', 'kind', 'repository_id', 'partner_id'] as $field) {
            if (isset($validated[$field])) {
                $query->where($field, $validated[$field]);
            }
        }
        if (array_key_exists('needs_details', $validated)) {
            $query->where('needs_details', filter_var($validated['needs_details'], FILTER_VALIDATE_BOOLEAN));
        }
        if (isset($validated['from'])) {
            $query->whereDate('maturity_date', '>=', $validated['from']);
        }
        if (isset($validated['to'])) {
            $query->whereDate('maturity_date', '<=', $validated['to']);
        }

        $instruments = $query->orderBy('maturity_date')->orderBy('id')->get();
        $scale = $this->scaleResolver->getScale($company->currency);
        $today = CarbonImmutable::today($company->timezone);
        $buckets = $this->emptyBuckets($scale);
        $grandTotal = $this->emptyTotal($scale);
        $rows = [];

        foreach ($instruments as $instrument) {
            $bucket = $this->bucketFor($instrument, $today);
            $buckets[$bucket]['count']++;
            $grandTotal['count']++;
            if ($instrument->direction === InstrumentDirection::Inbound) {
                $buckets[$bucket]['total_in'] = bcadd($buckets[$bucket]['total_in'], $instrument->amount, $scale);
                $grandTotal['total_in'] = bcadd($grandTotal['total_in'], $instrument->amount, $scale);
            } else {
                $buckets[$bucket]['total_out'] = bcadd($buckets[$bucket]['total_out'], $instrument->amount, $scale);
                $grandTotal['total_out'] = bcadd($grandTotal['total_out'], $instrument->amount, $scale);
            }
            $rows[] = $this->formatRow($instrument, $bucket);
        }

        return response()->json([
            'data' => $rows,
            'meta' => [
                'buckets' => $buckets,
                'grand_total' => $grandTotal,
            ],
        ]);
    }

    /** @return array{count: int, total_in: numeric-string, total_out: numeric-string} */
    private function emptyTotal(int $scale): array
    {
        return [
            'count' => 0,
            'total_in' => bcadd('0', '0', $scale),
            'total_out' => bcadd('0', '0', $scale),
        ];
    }

    /** @return array{overdue: array{count: int, total_in: numeric-string, total_out: numeric-string}, d0_7: array{count: int, total_in: numeric-string, total_out: numeric-string}, d8_30: array{count: int, total_in: numeric-string, total_out: numeric-string}, d31_60: array{count: int, total_in: numeric-string, total_out: numeric-string}, d61_90: array{count: int, total_in: numeric-string, total_out: numeric-string}, d90_plus: array{count: int, total_in: numeric-string, total_out: numeric-string}} */
    private function emptyBuckets(int $scale): array
    {
        return [
            'overdue' => $this->emptyTotal($scale),
            'd0_7' => $this->emptyTotal($scale),
            'd8_30' => $this->emptyTotal($scale),
            'd31_60' => $this->emptyTotal($scale),
            'd61_90' => $this->emptyTotal($scale),
            'd90_plus' => $this->emptyTotal($scale),
        ];
    }

    /** @return 'overdue'|'d0_7'|'d8_30'|'d31_60'|'d61_90'|'d90_plus' */
    private function bucketFor(PaymentInstrument $instrument, CarbonImmutable $today): string
    {
        if ($instrument->maturity_date === null) {
            return 'd0_7';
        }
        $maturity = CarbonImmutable::parse($instrument->maturity_date->toDateString(), $today->timezone);
        if ($maturity->isBefore($today)) {
            return 'overdue';
        }
        $days = (int) $today->diffInDays($maturity);

        return match (true) {
            $days <= 7 => 'd0_7',
            $days <= 30 => 'd8_30',
            $days <= 60 => 'd31_60',
            $days <= 90 => 'd61_90',
            default => 'd90_plus',
        };
    }

    /** @return array<string, mixed> */
    private function formatRow(PaymentInstrument $instrument, string $bucket): array
    {
        return [
            'id' => $instrument->id,
            'reference' => $instrument->reference,
            'amount' => $instrument->amount,
            'currency' => $instrument->currency,
            'maturity_date' => $instrument->maturity_date?->toDateString(),
            'received_date' => $instrument->received_date->toDateString(),
            'status' => $instrument->status->value,
            'direction' => $instrument->direction->value,
            'kind' => $instrument->kind?->value,
            'repository_id' => $instrument->repository_id,
            'partner_id' => $instrument->partner_id,
            'needs_details' => $instrument->needs_details,
            'certainty' => $instrument->status === InstrumentStatus::Deposited ? 'remitted' : 'portfolio',
            'bucket' => $bucket,
        ];
    }
}
