<?php

declare(strict_types=1);

namespace App\Modules\Import\Services;

use App\Modules\Company\Domain\Company;
use App\Modules\Import\Domain\Enums\ImportRowOutcome;
use App\Modules\Import\Domain\ImportJob;
use App\Modules\Inventory\Application\DTOs\OpeningBalanceLine;
use App\Modules\Inventory\Application\DTOs\OpeningBalancePosting;
use App\Modules\Inventory\Application\Services\OpeningBalancePostingService;
use App\Modules\Inventory\Domain\Enums\OpeningLotExpiryOutcome;
use App\Modules\Inventory\Domain\Exceptions\OpeningAlreadyExistsException;
use App\Modules\Product\Domain\Enums\ProductType;
use App\Modules\Product\Domain\Product;
use App\Shared\Contracts\CurrencyScaleResolverInterface;
use App\Shared\Contracts\LocationServiceInterface;
use App\Shared\Domain\CurrencyScale;
use Carbon\CarbonImmutable;

final class ProductOpeningStockPhase
{
    public function __construct(
        private readonly OpeningBalancePostingService $openingPosting,
        private readonly LocationServiceInterface $locationService,
        private readonly CurrencyScaleResolverInterface $scaleResolver,
    ) {}

    /**
     * @return list<array{row_id: string, code: string, detail: string, results: array<string, string>}>
     */
    public function run(ImportJob $job, string $companyId): array
    {
        /** @var Company $company */
        $company = Company::query()->where('tenant_id', $job->tenant_id)->findOrFail($companyId);
        $scale = $this->scaleResolver->getScale($company->currency);

        $results = [];

        $job->rows()
            ->where('outcome', ImportRowOutcome::Imported)
            ->whereNotNull('imported_entity_id')
            ->orderBy('row_number')
            ->chunk(100, function ($rows) use ($job, $companyId, $scale, &$results): void {
                foreach ($rows as $row) {
                    $data = $row->data;
                    if (! $this->positiveQuantity($data['quantity'] ?? null)) {
                        continue;
                    }

                    $product = Product::query()
                        ->where('company_id', $companyId)
                        ->find($row->imported_entity_id);

                    if ($product === null) {
                        $results[] = $this->warning($row->id, 'opening_failed', 'imported product not found', 'skipped: opening_failed');

                        continue;
                    }

                    if ($product->type === ProductType::Service) {
                        $results[] = $this->warning($row->id, 'quantity_ignored_service', 'quantity ignored for service products', 'skipped: quantity_ignored_service');

                        continue;
                    }

                    if (! $this->positiveMoney($data['purchase_price'] ?? null, $scale)) {
                        $results[] = $this->warning($row->id, 'qty_without_cost', 'quantity requires a positive purchase_price', 'skipped: qty_without_cost');

                        continue;
                    }

                    // Batch-tracked products are handled by the posting service
                    // itself (it backs the opened quantity with a DEFAULT lot) —
                    // parapharmacy verticals default every product to batch
                    // tracking, so skipping them would no-op the whole vertical.
                    $locationId = $this->resolveLocationId($job, $companyId, $data);
                    if ($locationId === null) {
                        $results[] = $this->warning($row->id, 'location_unresolved', 'location code could not be resolved', 'skipped: location_unresolved');

                        continue;
                    }

                    $expiryDate = $this->present($data['expiry_date'] ?? null)
                        ? (string) $data['expiry_date']
                        : null;

                    try {
                        $result = $this->openingPosting->post(new OpeningBalancePosting(
                            tenantId: $job->tenant_id,
                            companyId: $companyId,
                            userId: $job->user_id,
                            entryDate: now(),
                            isHistorical: true,
                            sourceType: 'import',
                            sourceId: $row->id,
                            reference: 'IMPORT-'.substr($job->id, 0, 8),
                            notes: null,
                            lines: [
                                OpeningBalanceLine::make(
                                    $product->id,
                                    null,
                                    $locationId,
                                    CurrencyScale::bcformatStrict((string) $data['quantity'], 4),
                                    CurrencyScale::bcformatStrict((string) $data['purchase_price'], $scale),
                                    $scale,
                                    // W4-1 — the optional `expiry_date` column. Blank
                                    // stays blank all the way down: the opening lot is
                                    // then dated by the product's configured shelf
                                    // life, or minted undated. Never invented.
                                    $expiryDate,
                                ),
                            ],
                        ));

                        $results[] = [
                            'row_id' => $row->id,
                            'code' => '',
                            'detail' => '',
                            'results' => ['opening_stock' => 'ok'],
                        ];

                        // 🚨 W4-1 gate r1 — the row is NOT simply `ok` when the
                        // expiry the operator typed did not end up on the lot.
                        // Reporting success for a row whose date was discarded is
                        // the same lost-fact defect this lane exists to remove,
                        // moved one layer up.
                        foreach ($this->expiryWarnings($row->id, $expiryDate, $result->expiryOutcomesInInputOrder[0] ?? null) as $warning) {
                            $results[] = $warning;
                        }
                    } catch (OpeningAlreadyExistsException) {
                        $results[] = $this->warning($row->id, 'opening_exists', 'opening stock already exists for this product/location', 'skipped: opening_exists');
                    } catch (\Throwable $e) {
                        $results[] = $this->warning($row->id, 'opening_failed', $e->getMessage(), 'skipped: opening_failed');
                    }
                }
            });

        return $results;
    }

    /**
     * Row warnings for what became of a supplied `expiry_date` (W4-1 gate r1).
     *
     * Every one of these is NON-BLOCKING: the stock is opened either way. They
     * exist so the result workbook — the only artefact the operator keeps after
     * the wizard closes — never reports a bare `ok` for a row whose date was
     * changed, ignored, or is already in the past.
     *
     * @return list<array{row_id: string, code: string, detail: string, results: array<string, string>}>
     */
    private function expiryWarnings(string $rowId, ?string $expiryDate, ?OpeningLotExpiryOutcome $outcome): array
    {
        if ($expiryDate === null) {
            return [];
        }

        $warnings = [];

        // RULED policy: a PAST expiry on an opening lot is ALLOWED — a
        // parapharmacy may legitimately open with expired stock in order to scrap
        // it — but it is never silent. The lot is born EXPIRED, so FEFO and the
        // transfer guard will treat it as unsellable, and an operator who typed the
        // wrong year needs to see that on the row rather than discover it at the
        // first refused issue.
        if (CarbonImmutable::parse($expiryDate)->isBefore(CarbonImmutable::today())) {
            $warnings[] = $this->expiryNote(
                $rowId,
                'expiry_in_past',
                "expiry_date {$expiryDate} is in the past: this lot opens EXPIRED and cannot be sold or transferred until it is written off",
                'opened with a past expiry',
            );
        }

        if ($outcome === OpeningLotExpiryOutcome::ConflictExistingLot) {
            $warnings[] = $this->expiryNote(
                $rowId,
                OpeningLotExpiryOutcome::ConflictExistingLot->value,
                "the default lot for this product already carries a different expiry; {$expiryDate} was NOT applied. Edit the lot directly to change it.",
                'existing lot expiry kept',
            );
        }

        if ($outcome === OpeningLotExpiryOutcome::IgnoredNotBatchTracked) {
            $warnings[] = $this->expiryNote(
                $rowId,
                OpeningLotExpiryOutcome::IgnoredNotBatchTracked->value,
                "expiry_date {$expiryDate} was ignored: this product is not batch-tracked, so its stock is not held in a lot",
                'expiry ignored',
            );
        }

        // Deliberately NOT folded into the branch above (gate r1 MINOR-4): the
        // product here IS batch-tracked, and telling the operator otherwise would
        // be a false statement about their own catalogue.
        if ($outcome === OpeningLotExpiryOutcome::IgnoredNoDefaultLot) {
            $warnings[] = $this->expiryNote(
                $rowId,
                OpeningLotExpiryOutcome::IgnoredNoDefaultLot->value,
                "expiry_date {$expiryDate} was ignored: this product's existing lots already account for the whole "
                .'opening quantity, so no default lot was created for the date to apply to. Set the expiry on the '
                .'relevant lot directly.',
                'expiry ignored (no default lot)',
            );
        }

        return $warnings;
    }

    /**
     * A non-blocking expiry note.
     *
     * Reported under its OWN result key, never `opening_stock`: `finalizeImport()`
     * array_merges each result into `$row->data['_results']`, so reusing
     * `opening_stock` here would overwrite the `ok` the posting itself earned and
     * the workbook would read as if the stock had not opened.
     *
     * @return array{row_id: string, code: string, detail: string, results: array<string, string>}
     */
    private function expiryNote(string $rowId, string $code, string $detail, string $result): array
    {
        return [
            'row_id' => $rowId,
            'code' => $code,
            'detail' => $detail,
            'results' => ['opening_lot_expiry' => $result],
        ];
    }

    private function positiveQuantity(mixed $value): bool
    {
        return is_numeric($value) && bccomp((string) $value, '0', 4) > 0;
    }

    private function positiveMoney(mixed $value, int $scale): bool
    {
        return is_numeric($value) && bccomp((string) $value, '0', $scale) > 0;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function resolveLocationId(ImportJob $job, string $companyId, array $data): ?string
    {
        $options = $job->options ?? [];
        $locationCode = $this->present($data['location_code'] ?? null)
            ? (string) $data['location_code']
            : ($this->present($options['location_code'] ?? null) ? (string) $options['location_code'] : null);

        if ($locationCode === null) {
            return null;
        }

        return $this->locationService->findIdByCode($companyId, $locationCode);
    }

    private function present(mixed $value): bool
    {
        return $value !== null && trim((string) $value) !== '';
    }

    /**
     * @return array{row_id: string, code: string, detail: string, results: array<string, string>}
     */
    private function warning(string $rowId, string $code, string $detail, string $result): array
    {
        return [
            'row_id' => $rowId,
            'code' => $code,
            'detail' => $detail,
            'results' => ['opening_stock' => $result],
        ];
    }
}
