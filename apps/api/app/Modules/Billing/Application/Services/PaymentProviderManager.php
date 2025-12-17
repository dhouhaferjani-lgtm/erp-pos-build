<?php

declare(strict_types=1);

namespace App\Modules\Billing\Application\Services;

use App\Modules\Billing\Domain\Contracts\PaymentProviderInterface;
use App\Modules\Billing\Domain\Enums\PaymentProviderCode;
use App\Modules\Billing\Infrastructure\Providers\ManualPaymentProvider;
use App\Modules\Billing\Infrastructure\Providers\StripePaymentProvider;
use InvalidArgumentException;

/**
 * Manages payment providers with auto-detection based on configuration.
 */
final class PaymentProviderManager
{
    /**
     * @var array<string, PaymentProviderInterface>
     */
    private array $providers = [];

    /**
     * @var array<string, class-string<PaymentProviderInterface>>
     */
    private array $providerClasses = [];

    public function __construct()
    {
        $this->registerDefaultProviders();
    }

    /**
     * Register default payment providers.
     */
    private function registerDefaultProviders(): void
    {
        // Register provider classes (lazy instantiation)
        $this->providerClasses = [
            PaymentProviderCode::Stripe->value => StripePaymentProvider::class,
            PaymentProviderCode::Manual->value => ManualPaymentProvider::class,
            PaymentProviderCode::BankTransfer->value => ManualPaymentProvider::class,
            PaymentProviderCode::Cash->value => ManualPaymentProvider::class,
            PaymentProviderCode::Check->value => ManualPaymentProvider::class,
        ];
    }

    /**
     * Get a payment provider by code.
     */
    public function provider(PaymentProviderCode|string $code): PaymentProviderInterface
    {
        $codeValue = $code instanceof PaymentProviderCode ? $code->value : $code;

        // Return cached instance if exists
        if (isset($this->providers[$codeValue])) {
            return $this->providers[$codeValue];
        }

        // Check if provider class is registered
        if (! isset($this->providerClasses[$codeValue])) {
            throw new InvalidArgumentException("Unknown payment provider: {$codeValue}");
        }

        // Instantiate provider
        $providerClass = $this->providerClasses[$codeValue];
        $provider = $this->createProvider($codeValue, $providerClass);

        // Cache instance
        $this->providers[$codeValue] = $provider;

        return $provider;
    }

    /**
     * Create a provider instance.
     *
     * @param  class-string<PaymentProviderInterface>  $class
     */
    private function createProvider(string $code, string $class): PaymentProviderInterface
    {
        // For manual providers, pass the specific type
        if ($class === ManualPaymentProvider::class) {
            return new ManualPaymentProvider(
                PaymentProviderCode::from($code)
            );
        }

        return new $class;
    }

    /**
     * Get all configured (available) providers.
     *
     * @return array<PaymentProviderInterface>
     */
    public function getConfiguredProviders(): array
    {
        $configured = [];

        foreach (PaymentProviderCode::configured() as $code) {
            $configured[] = $this->provider($code);
        }

        return $configured;
    }

    /**
     * Get all available online payment providers.
     *
     * @return array<PaymentProviderInterface>
     */
    public function getOnlineProviders(): array
    {
        $online = [];

        foreach ($this->getConfiguredProviders() as $provider) {
            if (! $this->isManualProvider($provider->getCode())) {
                $online[] = $provider;
            }
        }

        return $online;
    }

    /**
     * Get all available manual/offline providers.
     *
     * @return array<PaymentProviderInterface>
     */
    public function getManualProviders(): array
    {
        $manual = [];

        foreach ($this->getConfiguredProviders() as $provider) {
            if ($this->isManualProvider($provider->getCode())) {
                $manual[] = $provider;
            }
        }

        return $manual;
    }

    /**
     * Check if a provider is manual/offline.
     */
    public function isManualProvider(PaymentProviderCode $code): bool
    {
        return in_array($code, [
            PaymentProviderCode::Manual,
            PaymentProviderCode::BankTransfer,
            PaymentProviderCode::Cash,
            PaymentProviderCode::Check,
        ], true);
    }

    /**
     * Get the best available online payment provider.
     */
    public function getDefaultOnlineProvider(): ?PaymentProviderInterface
    {
        $online = $this->getOnlineProviders();

        if (empty($online)) {
            return null;
        }

        // Prefer Stripe if available
        foreach ($online as $provider) {
            if ($provider->getCode() === PaymentProviderCode::Stripe) {
                return $provider;
            }
        }

        // Otherwise return first available
        return $online[0];
    }

    /**
     * Get providers that support a specific currency.
     *
     * @return array<PaymentProviderInterface>
     */
    public function getProvidersForCurrency(string $currency): array
    {
        $matching = [];

        foreach ($this->getConfiguredProviders() as $provider) {
            if ($provider->supportsCurrency($currency)) {
                $matching[] = $provider;
            }
        }

        return $matching;
    }

    /**
     * Get a summary of all provider statuses.
     *
     * @return array<string, array{name: string, available: bool, configured: bool}>
     */
    public function getProviderStatus(): array
    {
        $status = [];

        foreach (PaymentProviderCode::cases() as $code) {
            $configured = $code->isConfigured();

            $status[$code->value] = [
                'name' => $code->label(),
                'available' => $configured,
                'configured' => $configured,
                'type' => $this->isManualProvider($code) ? 'manual' : 'online',
            ];
        }

        return $status;
    }

    /**
     * Register a custom payment provider.
     *
     * @param  class-string<PaymentProviderInterface>  $class
     */
    public function extend(string $code, string $class): void
    {
        $this->providerClasses[$code] = $class;

        // Clear cache for this provider
        unset($this->providers[$code]);
    }
}
