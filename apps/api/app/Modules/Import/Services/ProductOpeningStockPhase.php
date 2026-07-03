<?php

declare(strict_types=1);

namespace App\Modules\Import\Services;

use App\Modules\Company\Domain\Company;
use App\Modules\Import\Domain\ImportJob;
use App\Modules\Inventory\Application\DTOs\OpeningBalanceLine;
use App\Modules\Inventory\Application\DTOs\OpeningBalancePosting;
use App\Modules\Inventory\Application\Services\OpeningBalancePostingService;
use App\Modules\Inventory\Domain\Exceptions\OpeningAlreadyExistsException;
use App\Modules\Product\Domain\Enums\ProductType;
use App\Modules\Product\Domain\Product;
use App\Shared\Contracts\CurrencyScaleResolverInterface;
use App\Shared\Contracts\LocationServiceInterface;
use App\Shared\Domain\CurrencyScale;

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
            ->where('is_imported', true)
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

                    try {
                        $this->openingPosting->post(new OpeningBalancePosting(
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
                                ),
                            ],
                        ));

                        $results[] = [
                            'row_id' => $row->id,
                            'code' => '',
                            'detail' => '',
                            'results' => ['opening_stock' => 'ok'],
                        ];
                    } catch (OpeningAlreadyExistsException) {
                        $results[] = $this->warning($row->id, 'opening_exists', 'opening stock already exists for this product/location', 'skipped: opening_exists');
                    } catch (\Throwable $e) {
                        $results[] = $this->warning($row->id, 'opening_failed', $e->getMessage(), 'skipped: opening_failed');
                    }
                }
            });

        return $results;
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
