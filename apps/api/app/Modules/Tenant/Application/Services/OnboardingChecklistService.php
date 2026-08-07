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
use Illuminate\Support\Facades\Log;

final class OnboardingChecklistService
{
    /**
     * Get the onboarding status for the given company.
     *
     * BUG-005 / RCA B1 — each step is fault-isolated. The 7 checks span 5
     * modules; before this, a single failure (a tenant whose migration lane is
     * behind and is missing a table, a transient PG error) 500'd the entire
     * `/settings/setup` page. One broken module must degrade its own row, never
     * blank the page — so a throwing check is logged and reported as
     * `completed: false, degraded: true`.
     *
     * @return list<array{step: string, label: string, completed: bool, required: bool, settings_path: string, degraded: bool}>
     */
    public function getStatus(string $companyId): array
    {
        // The company lookup itself is fault-isolated: the per-step checks all
        // treat a null company as "not completed", so a failed lookup degrades
        // the whole list rather than throwing.
        $company = $this->safely(
            'company_lookup',
            $companyId,
            static fn (): ?Company => Company::find($companyId),
        );

        $result = [];

        foreach (OnboardingStep::cases() as $step) {
            $completed = $this->safely(
                $step->value,
                $companyId,
                fn (): bool => match ($step) {
                    OnboardingStep::CompanyInfo => $this->checkCompanyInfo($company),
                    OnboardingStep::TaxConfig => $this->checkTaxConfig($company),
                    OnboardingStep::PaymentMethods => $this->checkPaymentMethods($companyId),
                    OnboardingStep::PaymentRepositories => $this->checkPaymentRepositories($companyId),
                    OnboardingStep::PosTerminal => $this->checkPosTerminal($companyId),
                    OnboardingStep::FirstProduct => $this->checkFirstProduct($companyId),
                    OnboardingStep::ProductOptions => $this->checkProductOptions($company),
                },
            );

            $result[] = [
                'step' => $step->value,
                'label' => $step->label(),
                'completed' => $completed ?? false,
                'required' => $step->isRequired(),
                'settings_path' => $step->settingsPath(),
                'degraded' => $completed === null,
            ];
        }

        return $result;
    }

    /**
     * Run one checklist probe, logging and returning null on ANY Throwable
     * instead of letting it escape and 500 the page.
     *
     * null therefore means "could not be determined", which the caller renders
     * as `completed: false, degraded: true` — distinct from a probe that ran
     * fine and reported `false`.
     *
     * @template T
     *
     * @param  \Closure(): T  $probe
     * @return T|null
     */
    private function safely(string $stepKey, string $companyId, \Closure $probe): mixed
    {
        try {
            return $probe();
        } catch (\Throwable $e) {
            Log::warning('Onboarding checklist step degraded', [
                'step' => $stepKey,
                'company_id' => $companyId,
                'exception' => $e::class,
                'error' => $e->getMessage(),
            ]);

            return null;
        }
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

    private function checkProductOptions(?Company $company): bool
    {
        if ($company === null) {
            return false;
        }

        return ProductAttribute::query()
            ->where('tenant_id', $company->tenant_id)
            ->where('is_variant_axis', true)
            ->exists();
    }
}
