<?php

declare(strict_types=1);

namespace App\Modules\Treasury\Presentation\Controllers;

use App\Modules\Company\Services\CompanyContext;
use App\Modules\Company\Domain\Location;
use App\Modules\Company\Services\LocationScopeResolver;
use App\Modules\Company\Services\LocationScopeBoundary;
use App\Modules\Identity\Domain\User;
use App\Modules\Treasury\Domain\Enums\InstrumentDirection;
use App\Modules\Treasury\Domain\Enums\InstrumentStatus;
use App\Modules\Treasury\Domain\PaymentInstrument;
use App\Shared\Contracts\CurrencyScaleResolverInterface;
use App\Shared\Domain\CurrencyScale;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

final class MaturingInstrumentsController extends Controller
{
    public function __construct(
        private readonly CompanyContext $companyContext,
        private readonly CurrencyScaleResolverInterface $scaleResolver,
        private readonly LocationScopeResolver $locationScopeResolver,
        private readonly LocationScopeBoundary $locationScopeBoundary,
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
            'group_by' => ['nullable', 'in:location'],
            'location_ids' => ['nullable', 'array'],
            'location_ids.*' => ['uuid'],
        ]);

        $user = $request->user();
        if (! $user instanceof User) {
            abort(401);
        }
        $effectiveLocationIds = $this->locationScopeResolver->resolve(
            $user,
            $this->requestedLocationIds($validated['location_ids'] ?? []),
            null,
        );
        $unrestricted = $this->locationScopeBoundary->isUnrestricted($company->id, $effectiveLocationIds);

        $query = PaymentInstrument::query()
            ->where('tenant_id', $company->tenant_id)
            ->where('company_id', $company->id)
            ->whereIn('status', [InstrumentStatus::Received, InstrumentStatus::Deposited])
            ->where(function ($locationQuery) use ($effectiveLocationIds, $unrestricted): void {
                $locationQuery->whereIn('location_id', $effectiveLocationIds);
                if ($unrestricted) {
                    $locationQuery->orWhereNull('location_id');
                }
            })
            ->with(['paymentMethod', 'partner', 'repository', 'depositedTo']);

        foreach (['direction', 'kind', 'repository_id', 'partner_id'] as $field) {
            if (isset($validated[$field])) {
                $query->where($field, $validated[$field]);
            }
        }
        if (array_key_exists('needs_details', $validated)) {
            $query->where('needs_details', filter_var($validated['needs_details'], FILTER_VALIDATE_BOOLEAN));
        }
        $today = CarbonImmutable::today($company->timezone);
        $from = isset($validated['from']) ? CarbonImmutable::parse($validated['from'], $company->timezone) : null;
        $to = isset($validated['to']) ? CarbonImmutable::parse($validated['to'], $company->timezone) : null;
        $includesAtSight = ($from === null || ! $today->isBefore($from))
            && ($to === null || ! $today->isAfter($to));

        if ($from !== null || $to !== null) {
            $query->where(function ($dateQuery) use ($from, $to, $includesAtSight): void {
                if ($includesAtSight) {
                    $dateQuery->whereNull('maturity_date')->orWhere(function ($datedQuery) use ($from, $to): void {
                        if ($from !== null) {
                            $datedQuery->whereDate('maturity_date', '>=', $from->toDateString());
                        }
                        if ($to !== null) {
                            $datedQuery->whereDate('maturity_date', '<=', $to->toDateString());
                        }
                    });

                    return;
                }

                $dateQuery->whereNotNull('maturity_date');
                if ($from !== null) {
                    $dateQuery->whereDate('maturity_date', '>=', $from->toDateString());
                }
                if ($to !== null) {
                    $dateQuery->whereDate('maturity_date', '<=', $to->toDateString());
                }
            });
        }

        $instruments = $query->orderBy('maturity_date')->orderBy('id')->get();
        $scale = $this->scaleResolver->getScale($company->currency);
        $locationNames = Location::query()->whereIn('id', $effectiveLocationIds)->pluck('name', 'id');
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
            $rows[] = $this->formatRow($instrument, $bucket, $instrument->location_id === null
                ? null
                : (string) ($locationNames->get($instrument->location_id) ?? $instrument->location_id));
        }

        $bucketsByLocation = null;
        if (($validated['group_by'] ?? null) === 'location') {
            $locationTotals = [];
            foreach ($instruments as $instrument) {
                $key = $instrument->location_id ?? 'unattributed';
                if ($key === 'unattributed' && ! $unrestricted) {
                    continue;
                }
                $locationTotals[$key] ??= [
                    'location_id' => $instrument->location_id,
                    'location_name' => $instrument->location_id === null
                        ? 'Unattributed'
                        : (string) ($locationNames->get($instrument->location_id) ?? $instrument->location_id),
                    'count' => 0,
                    'total_in' => CurrencyScale::bcformatStrict('0', $scale),
                    'total_out' => CurrencyScale::bcformatStrict('0', $scale),
                ];
                $locationTotals[$key]['count']++;
                $directionKey = $instrument->direction === InstrumentDirection::Inbound ? 'total_in' : 'total_out';
                $locationTotals[$key][$directionKey] = bcadd($locationTotals[$key][$directionKey], $instrument->amount, $scale);
            }
            $bucketsByLocation = array_values($locationTotals);
        }

        return response()->json([
            'data' => $rows,
            'meta' => [
                'buckets' => $buckets,
                'grand_total' => $grandTotal,
                ...($bucketsByLocation !== null ? ['buckets_by_location' => $bucketsByLocation] : []),
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
    private function formatRow(PaymentInstrument $instrument, string $bucket, ?string $locationName = null): array
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
            'location_id' => $instrument->location_id,
            'location_name' => $locationName,
            'partner_id' => $instrument->partner_id,
            'needs_details' => $instrument->needs_details,
            'certainty' => $instrument->status === InstrumentStatus::Deposited ? 'remitted' : 'portfolio',
            'bucket' => $bucket,
        ];
    }

    /** @return list<string> */
    private function requestedLocationIds(mixed $value): array
    {
        return array_values(array_filter(
            is_array($value) ? $value : [],
            static fn (mixed $id): bool => is_string($id),
        ));
    }
}
