<?php

declare(strict_types=1);

namespace App\Modules\Tenant\Application\Services;

use App\Modules\Catalog\Domain\Entities\ProductAttribute;
use App\Modules\Company\Domain\Company;
use App\Modules\POS\Domain\Terminal;
use App\Modules\Product\Domain\Product;
use App\Modules\Tenant\Domain\Enums\OnboardingStep;
use App\Modules\Treasury\Domain\Enums\RepositoryType;
use App\Modules\Treasury\Domain\PaymentMethod;
use App\Modules\Treasury\Domain\PaymentRepository;

final class OnboardingChecklistService
{
    /**
     * Get the onboarding status for the given company.
     *
     * @return list<array{step: string, label: string, completed: bool, required: bool, settings_path: string}>
     */
    public function getStatus(string $companyId): array
    {
        $company = Company::find($companyId);

        $result = [];

        foreach (OnboardingStep::cases() as $step) {
            $completed = match ($step) {
                OnboardingStep::CompanyInfo => $this->checkCompanyInfo($company),
                OnboardingStep::TaxConfig => $this->checkTaxConfig($company),
                OnboardingStep::PaymentMethods => $this->checkPaymentMethods($companyId),
                OnboardingStep::PaymentRepositories => $this->checkPaymentRepositories($companyId),
                OnboardingStep::PosTerminal => $this->checkPosTerminal($companyId),
                OnboardingStep::FirstProduct => $this->checkFirstProduct($companyId),
                OnboardingStep::ProductOptions => $this->checkProductOptions(),
            };

            $result[] = [
                'step' => $step->value,
                'label' => $step->label(),
                'completed' => $completed,
                'required' => $step->isRequired(),
                'settings_path' => $step->settingsPath(),
            ];
        }

        return $result;
    }

    private function checkCompanyInfo(?Company $company): bool
    {
        if ($company === null) {
            return false;
        }

        $hasName = filled($company->name);
        $hasTaxOrRegistration = filled($company->tax_id) || filled($company->registration_number);

        return $hasName && $hasTaxOrRegistration;
    }

    private function checkTaxConfig(?Company $company): bool
    {
        if ($company === null) {
            return false;
        }

        return $company->default_tax_configuration_id !== null;
    }

    private function checkPaymentMethods(string $companyId): bool
    {
        return PaymentMethod::where('company_id', $companyId)
            ->where('is_active', true)
            ->exists();
    }

    private function checkPaymentRepositories(string $companyId): bool
    {
        return PaymentRepository::where('company_id', $companyId)
            ->where('type', RepositoryType::CashRegister)
            ->where('is_active', true)
            ->exists();
    }

    private function checkPosTerminal(string $companyId): bool
    {
        return Terminal::where('company_id', $companyId)
            ->exists();
    }

    private function checkFirstProduct(string $companyId): bool
    {
        return Product::where('company_id', $companyId)
            ->where('is_active', true)
            ->exists();
    }

    private function checkProductOptions(): bool
    {
        return ProductAttribute::query()->where('is_variant_axis', true)->exists();
    }
}
