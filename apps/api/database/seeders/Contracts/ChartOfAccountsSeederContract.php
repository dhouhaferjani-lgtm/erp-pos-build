<?php

declare(strict_types=1);

namespace Database\Seeders\Contracts;

use Database\Seeders\CountryDefaultsChartOfAccountsSeeder;
use Illuminate\Console\Command;
use Illuminate\Database\Seeder;

/**
 * Contract for locale-specific chart-of-accounts seeders.
 *
 * Frozen locale seeders implement this for historical replay. Active demo
 * provisioning uses {@see CountryDefaultsChartOfAccountsSeeder}, parameterized
 * by country and delegated to the activation-aware provisioning service.
 *
 * Implementors must also extend {@see Seeder} (which
 * provides the `setCommand` implementation). The method is declared here
 * so PHPStan can verify the full call-site contract.
 */
interface ChartOfAccountsSeederContract
{
    /**
     * Seed the chart of accounts for the given company.
     *
     * @param  string  $companyId  UUID of the company to seed accounts for.
     * @param  string|null  $tenantId  UUID of the tenant (for backward compatibility).
     */
    public function run(string $companyId, ?string $tenantId = null): void;

    /**
     * Set the console command instance (provided by {@see Seeder}).
     *
     * Declared here so callers can invoke it through the contract without an
     * additional cast to the concrete seeder or the base class.
     *
     * Note: no return-type hint here — Laravel's {@see Seeder::setCommand()}
     * does not declare one either, so adding `: static` would break PHP's
     * interface-vs-implementation covariance check at load time.
     *
     * @return $this
     */
    public function setCommand(Command $command);
}
