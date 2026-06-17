<?php

declare(strict_types=1);

namespace App\Modules\Tenant\Domain\Enums;

enum OnboardingStep: string
{
    case CompanyInfo = 'company_info';
    case TaxConfig = 'tax_config';
    case PaymentMethods = 'payment_methods';
    case PaymentRepositories = 'payment_repositories';
    case PosTerminal = 'pos_terminal';
    case FirstProduct = 'first_product';
    case ProductOptions = 'product_options';

    public function isRequired(): bool
    {
        return match ($this) {
            self::CompanyInfo, self::TaxConfig, self::PaymentMethods, self::PaymentRepositories => true,
            self::PosTerminal, self::FirstProduct, self::ProductOptions => false,
        };
    }

    public function label(): string
    {
        return match ($this) {
            self::CompanyInfo => 'Company Information',
            self::TaxConfig => 'Tax Configuration',
            self::PaymentMethods => 'Payment Methods',
            self::PaymentRepositories => 'Payment Repositories',
            self::PosTerminal => 'POS Terminal',
            self::FirstProduct => 'First Product',
            self::ProductOptions => 'Set up product options',
        };
    }

    public function settingsPath(): string
    {
        return match ($this) {
            self::CompanyInfo => '/settings/company',
            self::TaxConfig => '/settings/tax',
            self::PaymentMethods => '/treasury/payment-methods',
            self::PaymentRepositories => '/treasury/repositories',
            self::PosTerminal => '/pos/terminals',
            self::FirstProduct => '/inventory/products',
            self::ProductOptions => '/catalog/attributes',
        };
    }
}
