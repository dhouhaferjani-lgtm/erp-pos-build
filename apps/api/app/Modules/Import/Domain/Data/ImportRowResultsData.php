<?php

declare(strict_types=1);

namespace App\Modules\Import\Domain\Data;

final readonly class ImportRowResultsData
{
    public const string PHASE_ACCOUNTING_BALANCES = 'accounting_balances';

    public const string PHASE_LEGACY = 'legacy';

    public const string PHASE_OPENING_STOCK = 'opening_stock';

    public const string PHASE_PARTIES_BALANCES = 'parties_balances';

    public const string PHASE_PRODUCT = 'product';

    /** @param array<string, ImportRowResultPhaseData> $phases */
    public function __construct(public array $phases) {}

    /** @param array<string, mixed> $payload */
    public static function fromStorage(array $payload): self
    {
        $phases = [];
        foreach ($payload as $phaseOrBreadcrumb => $value) {
            if (is_array($value)) {
                $breadcrumbs = $phases[$phaseOrBreadcrumb]->breadcrumbs ?? [];
                $current = ImportRowResultPhaseData::fromStorage($value);
                $phases[$phaseOrBreadcrumb] = new ImportRowResultPhaseData(
                    array_merge($breadcrumbs, $current->breadcrumbs),
                );

                continue;
            }

            if (! is_string($value)) {
                continue;
            }

            [$phase, $breadcrumb] = self::legacyLocation($phaseOrBreadcrumb);
            $breadcrumbs = $phases[$phase]->breadcrumbs ?? [];
            $breadcrumbs[$breadcrumb] = $value;
            $phases[$phase] = new ImportRowResultPhaseData($breadcrumbs);
        }

        return new self($phases);
    }

    /**
     * @param  array<string, string>  $breadcrumbs
     */
    public function merged(string $phase, array $breadcrumbs): self
    {
        $phases = $this->phases;
        $existing = $phases[$phase]->breadcrumbs ?? [];
        $phases[$phase] = new ImportRowResultPhaseData(array_merge($existing, $breadcrumbs));

        return new self($phases);
    }

    /** @return array<string, array<string, string>> */
    public function toStorage(): array
    {
        $storage = [];
        foreach ($this->phases as $phase => $breadcrumbs) {
            $storage[$phase] = $breadcrumbs->toStorage();
        }

        return $storage;
    }

    /** @return array{string, string} */
    private static function legacyLocation(string $breadcrumb): array
    {
        if (str_contains($breadcrumb, '.')) {
            [$phase, $name] = explode('.', $breadcrumb, 2);
            if ($phase !== '' && $name !== '') {
                return [$phase, $name];
            }
        }

        $phase = match ($breadcrumb) {
            'category', 'tax_source' => self::PHASE_PRODUCT,
            'opening_lot_expiry', 'opening_stock' => self::PHASE_OPENING_STOCK,
            'ap_balance', 'ar_balance' => self::PHASE_PARTIES_BALANCES,
            'gl_balance' => self::PHASE_ACCOUNTING_BALANCES,
            default => self::PHASE_LEGACY,
        };

        return [$phase, $breadcrumb];
    }
}
