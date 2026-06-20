<?php

declare(strict_types=1);

namespace Database\Seeders\Contracts;

use Illuminate\Console\Command;

/**
 * Contract for locale-specific chart-of-accounts seeders.
 *
 * Every COA seeder returned by {@see \Database\Seeders\ParapharmacySeeder::localeChartOfAccountsSeeder()}
 * (and its subclasses) must implement this interface so the caller can be
 * typed correctly without asserting a concrete class.
 *
 * Implementors must also extend {@see \Illuminate\Database\Seeder} (which
 * provides the `setCommand` implementation). The method is declared here
 * so PHPStan can verify the full call-site contract.
 *
 * Note: TunisiaChartOfAccountsSeeder must implement this interface when it is
 * wired in the Tunisia locale subclass (deferred to that task).
 */
interface ChartOfAccountsSeederContract
{
    /**
     * Seed the chart of accounts for the given company.
     *
     * @param  string       $companyId  UUID of the company to seed accounts for.
     * @param  string|null  $tenantId   UUID of the tenant (for backward compatibility).
     */
    public function run(string $companyId, ?string $tenantId = null): void;

    /**
     * Set the console command instance (provided by {@see \Illuminate\Database\Seeder}).
     *
     * Declared here so callers can invoke it through the contract without an
     * additional cast to the concrete seeder or the base class.
     *
     * @return static
     */
    public function setCommand(Command $command): static;
}
