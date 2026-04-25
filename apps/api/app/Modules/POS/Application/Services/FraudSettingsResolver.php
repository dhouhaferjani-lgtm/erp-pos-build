<?php

declare(strict_types=1);

namespace App\Modules\POS\Application\Services;

use App\Modules\Compliance\Domain\CompanyFraudSettings;
use App\Modules\Compliance\Infrastructure\Repositories\CompanyFraudSettingsRepository;
use App\Modules\POS\Application\DTOs\FraudSettingsDTO;

final class FraudSettingsResolver
{
    public function __construct(
        private readonly CompanyFraudSettingsRepository $repository,
    ) {}

    public function forCompany(string $companyId): FraudSettingsDTO
    {
        $row = $this->repository->findByCompany($companyId);

        if ($row === null) {
            $defaults = CompanyFraudSettings::getDefaults();

            return new FraudSettingsDTO(
                companyId: $companyId,
                cashVarianceOverSoft: (string) $defaults['cash_variance_over_soft'],
                cashVarianceOverHard: (string) $defaults['cash_variance_over_hard'],
                cashVarianceUnderSoft: (string) $defaults['cash_variance_under_soft'],
                cashVarianceUnderHard: (string) $defaults['cash_variance_under_hard'],
                requireBlindCashCount: (bool) $defaults['require_blind_cash_count'],
                requireManagerPinAboveHard: (bool) $defaults['require_manager_pin_above_hard'],
                cashVarianceEmailSeverity: (string) $defaults['cash_variance_email_severity'],
            );
        }

        return new FraudSettingsDTO(
            companyId: $companyId,
            cashVarianceOverSoft: (string) $row->cash_variance_over_soft,
            cashVarianceOverHard: (string) $row->cash_variance_over_hard,
            cashVarianceUnderSoft: (string) $row->cash_variance_under_soft,
            cashVarianceUnderHard: (string) $row->cash_variance_under_hard,
            requireBlindCashCount: (bool) $row->require_blind_cash_count,
            requireManagerPinAboveHard: (bool) $row->require_manager_pin_above_hard,
            cashVarianceEmailSeverity: (string) $row->cash_variance_email_severity,
        );
    }

    public function forLocation(string $companyId, ?string $locationId = null): FraudSettingsDTO
    {
        // $locationId currently ignored; future per-location override goes here.
        return $this->forCompany($companyId);
    }
}
