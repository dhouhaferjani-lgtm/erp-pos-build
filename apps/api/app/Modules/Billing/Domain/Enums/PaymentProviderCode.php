<?php

declare(strict_types=1);

namespace App\Modules\Billing\Domain\Enums;

/**
 * Supported payment provider codes.
 *
 * Each provider represents a different payment gateway or method.
 * Providers are auto-enabled when their required environment variables are set.
 */
enum PaymentProviderCode: string
{
    // International providers
    case Stripe = 'stripe';
    case PayPal = 'paypal';

    // European providers
    case Klarna = 'klarna';
    case SepaTransfer = 'sepa_transfer';

    // Tunisia providers
    case Flouci = 'flouci';
    case ClickToPay = 'click_to_pay';
    case Konnect = 'konnect';

    // Manual/Offline
    case Manual = 'manual';
    case BankTransfer = 'bank_transfer';
    case Cash = 'cash';
    case Check = 'check';

    /**
     * Get human-readable label.
     */
    public function label(): string
    {
        return match ($this) {
            self::Stripe => 'Stripe',
            self::PayPal => 'PayPal',
            self::Klarna => 'Klarna',
            self::SepaTransfer => 'SEPA Transfer',
            self::Flouci => 'Flouci',
            self::ClickToPay => 'Click to Pay',
            self::Konnect => 'Konnect',
            self::Manual => 'Manual Payment',
            self::BankTransfer => 'Bank Transfer',
            self::Cash => 'Cash',
            self::Check => 'Check',
        };
    }

    /**
     * Get supported countries for this provider.
     *
     * @return array<string>
     */
    public function supportedCountries(): array
    {
        return match ($this) {
            self::Stripe => ['FR', 'DE', 'IT', 'ES', 'NL', 'BE', 'AT', 'PT', 'IE', 'GB', 'CH', 'US'],
            self::PayPal => ['FR', 'DE', 'IT', 'ES', 'NL', 'BE', 'AT', 'PT', 'IE', 'GB', 'CH', 'US', 'TN'],
            self::Klarna => ['FR', 'DE', 'IT', 'ES', 'NL', 'BE', 'AT', 'SE', 'FI', 'NO', 'DK', 'GB'],
            self::SepaTransfer => ['FR', 'DE', 'IT', 'ES', 'NL', 'BE', 'AT', 'PT', 'IE', 'FI', 'GR', 'LU'],
            self::Flouci => ['TN'],
            self::ClickToPay => ['TN'],
            self::Konnect => ['TN'],
            self::Manual, self::BankTransfer, self::Cash, self::Check => ['*'], // All countries
        };
    }

    /**
     * Check if this provider supports the given country.
     */
    public function supportsCountry(string $countryCode): bool
    {
        $countries = $this->supportedCountries();

        return in_array('*', $countries, true) || in_array(strtoupper($countryCode), $countries, true);
    }

    /**
     * Check if this provider supports automatic recurring billing.
     */
    public function supportsRecurring(): bool
    {
        return match ($this) {
            self::Stripe, self::PayPal, self::Klarna, self::SepaTransfer, self::Konnect => true,
            self::Flouci, self::ClickToPay, self::Manual, self::BankTransfer, self::Cash, self::Check => false,
        };
    }

    /**
     * Check if this provider requires manual admin confirmation.
     */
    public function requiresManualConfirmation(): bool
    {
        return match ($this) {
            self::Manual, self::BankTransfer, self::Cash, self::Check => true,
            default => false,
        };
    }

    /**
     * Get required environment variables to enable this provider.
     *
     * @return array<string>
     */
    public function requiredEnvVars(): array
    {
        return match ($this) {
            self::Stripe => ['STRIPE_KEY', 'STRIPE_SECRET'],
            self::PayPal => ['PAYPAL_CLIENT_ID', 'PAYPAL_SECRET'],
            self::Klarna => ['KLARNA_USERNAME', 'KLARNA_PASSWORD'],
            self::SepaTransfer => ['SEPA_CREDITOR_ID'],
            self::Flouci => ['FLOUCI_APP_TOKEN', 'FLOUCI_APP_SECRET'],
            self::ClickToPay => ['CLICKTOPAY_MERCHANT_ID', 'CLICKTOPAY_SECRET'],
            self::Konnect => ['KONNECT_API_KEY'],
            self::Manual, self::BankTransfer, self::Cash, self::Check => [], // Always available
        };
    }

    /**
     * Check if this provider is configured (all required env vars set).
     * Uses config values that are set from env vars in config/services.php.
     */
    public function isConfigured(): bool
    {
        return match ($this) {
            self::Stripe => ! empty(config('services.stripe.key')) && ! empty(config('services.stripe.secret')),
            self::PayPal => ! empty(config('services.paypal.client_id')) && ! empty(config('services.paypal.secret')),
            self::Klarna => ! empty(config('services.klarna.username')) && ! empty(config('services.klarna.password')),
            self::SepaTransfer => ! empty(config('services.sepa.creditor_id')),
            self::Flouci => ! empty(config('services.flouci.app_token')) && ! empty(config('services.flouci.app_secret')),
            self::ClickToPay => ! empty(config('services.clicktopay.merchant_id')) && ! empty(config('services.clicktopay.secret')),
            self::Konnect => ! empty(config('services.konnect.api_key')),
            self::Manual, self::BankTransfer, self::Cash, self::Check => true, // Always available
        };
    }

    /**
     * Get all providers that are currently configured and available.
     *
     * @return array<self>
     */
    public static function configured(): array
    {
        return array_filter(
            self::cases(),
            fn (self $provider): bool => $provider->isConfigured()
        );
    }

    /**
     * Get all providers available for a specific country.
     *
     * @return array<self>
     */
    public static function forCountry(string $countryCode): array
    {
        return array_filter(
            self::configured(),
            fn (self $provider): bool => $provider->supportsCountry($countryCode)
        );
    }
}
