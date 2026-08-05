<?php

declare(strict_types=1);

namespace Tests\Traits;

/**
 * Boots the application under test with `MARKETPLACE_ENABLED=true`.
 *
 * The Marketplace kill-switch (`config('marketplace.enabled')`, default FALSE)
 * is consumed at BOOT time — it gates `loadRoutesFrom()` in
 * MarketplaceServiceProvider, the Marketplace schedule registration, and the
 * `catalog-carts.marketplace-checkout` route in the Cart module. A runtime
 * `config([...])` call inside a test body therefore lands far too late: by then
 * the route collection is already built. The env var has to be present before
 * the framework loads `config/marketplace.php`, i.e. before the application is
 * created — which is what this override does.
 *
 * The variable is removed again the moment the application exists (the value is
 * already materialised inside the config repository at that point), so it can
 * never leak into another test class sharing the same PHP process — the
 * `finally` is load-bearing: a throw inside `refreshApplication()` (a bad
 * migration, a boot-time exception) would otherwise leave
 * `MARKETPLACE_ENABLED=true` set for every later class in the same process.
 */
trait EnablesMarketplaceModule
{
    protected function refreshApplication(): void
    {
        self::setMarketplaceEnabledEnv('true');

        try {
            parent::refreshApplication();
        } finally {
            self::clearMarketplaceEnabledEnv();
        }
    }

    private static function setMarketplaceEnabledEnv(string $value): void
    {
        putenv('MARKETPLACE_ENABLED='.$value);
        $_ENV['MARKETPLACE_ENABLED'] = $value;
        $_SERVER['MARKETPLACE_ENABLED'] = $value;
    }

    private static function clearMarketplaceEnabledEnv(): void
    {
        // Two-step removal: `putenv('KEY')` without `=` unsets on POSIX but is
        // unreliable elsewhere, so blank it first (mirrors the pattern in
        // tests/Feature/Seeders/ParapharmacySeederTest.php).
        putenv('MARKETPLACE_ENABLED=');
        putenv('MARKETPLACE_ENABLED');
        unset($_ENV['MARKETPLACE_ENABLED'], $_SERVER['MARKETPLACE_ENABLED']);
    }
}
