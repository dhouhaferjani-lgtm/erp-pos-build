<?php

declare(strict_types=1);

namespace App\Modules\Tenant\Application\Services;

use App\Modules\Company\Domain\Company;
use App\Modules\Company\Domain\Enums\CompanyStatus;
use App\Modules\Company\Domain\Enums\MembershipRole;
use App\Modules\Company\Domain\Enums\MembershipStatus;
use App\Modules\Company\Domain\Location;
use App\Modules\Company\Domain\UserCompanyMembership;
use App\Modules\Identity\Domain\Device;
use App\Modules\Identity\Domain\User;
use App\Modules\Tenant\Domain\Domain;
use App\Modules\Tenant\Domain\Enums\SubscriptionPlan;
use App\Modules\Tenant\Domain\Enums\TenantStatus;
use App\Modules\Tenant\Domain\Tenant;
use Closure;
use DateTimeInterface;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Stancl\Tenancy\Jobs\CreateDatabase;
use Stancl\Tenancy\Jobs\MigrateDatabase;

/**
 * Database-per-tenant registration provisioning (T6 Phase 0b, deliverable 8).
 *
 * Register spans two databases under DB-per-tenant and cannot use a single
 * transaction: central rows (tenants, domains, central_identities, the trial
 * subscription) live in the central database; the user/company/location/roles
 * live in a per-tenant database that must be created + migrated first.
 *
 * Ordering (topology contract §9.1, eventual-consistency by design — no cross-DB
 * transaction is possible):
 *   1. central: tenants + domains row
 *   2. CreateDatabase + MigrateDatabase (the tenant database)
 *   3. tenancy()->initialize() → swap the default connection to the tenant DB
 *   4. tenant: user, company, location, membership, full init (roles + reference
 *      data + CoA + tax + payment); central: trial subscription + identity index
 *      (those models are pinned to the central connection)
 *   5. token (central PAT) + device (tenant), then revert tenancy
 *   6. on ANY failure after step 1: drop the tenant database + delete the central
 *      rows (compensation). `tenant:reconcile-identities` sweeps stragglers.
 *
 * The shared-DB compat path stays in AuthController (a single transaction); this
 * service is invoked only when tenancy_resolver.db_per_tenant is on.
 */
class TenantProvisioningService
{
    public function __construct(
        private readonly TenantInitializationService $tenantInitializationService,
        private readonly IdentityIndexService $identityIndexService,
    ) {}

    /**
     * @param  array<string, mixed>  $validated
     * @param  array<int, string>  $tokenAbilities
     * @param  (Closure(User): ?Device)|null  $deviceHandler  runs in tenant context
     * @return array{user: User, company: Company, token: string, device: ?Device}
     */
    public function provisionForRegistration(
        array $validated,
        ?DateTimeInterface $tokenExpiresAt,
        array $tokenAbilities,
        ?Closure $deviceHandler,
    ): array {
        $countryCode = strtoupper((string) $validated['country_code']);
        // Country defaults are looked up from the (tenant-scoped) countries table
        // in the shared-DB path; that table is not in the central DB, so fall back
        // to safe defaults here. The registration form normally supplies these.
        $timezone = (string) ($validated['timezone'] ?? 'UTC');
        $locale = (string) ($validated['locale'] ?? 'en');
        $currency = (string) ($validated['currency'] ?? 'EUR');
        $dateFormat = 'd/m/Y';

        // 1. Central: the tenant directory row.
        $tenant = Tenant::create([
            'name' => $validated['company_name'],
            'slug' => Str::slug($validated['company_name']).'-'.Str::random(6),
            'vertical' => $validated['vertical'],
            'enabled_extras' => [],
            'status' => TenantStatus::Active,
            'plan' => SubscriptionPlan::Trial,
            'country_code' => $countryCode,
            'currency_code' => $currency,
            'timezone' => $timezone,
            'locale' => $locale,
            'date_format' => $dateFormat,
            'settings' => [
                'timezone' => $timezone,
                'locale' => $locale,
                'date_format' => $dateFormat,
                'fiscal_year_start' => '01-01',
            ],
            'trial_ends_at' => now()->addDays(14),
            'subscription_ends_at' => null,
        ]);

        $databaseCreated = false;

        try {
            // 1b. Central: subdomain shortcut row.
            Domain::create([
                'tenant_id' => $tenant->id,
                'domain' => strtolower($tenant->slug).'.synerivia.tn',
                'is_primary' => true,
                'is_verified' => true,
            ]);

            // 2. Provision + migrate the per-tenant database.
            Bus::dispatchSync(new CreateDatabase($tenant));
            $databaseCreated = true;
            Bus::dispatchSync(new MigrateDatabase($tenant));

            // 3. Swap the default connection to the tenant database.
            tenancy()->initialize($tenant);

            // 4. Tenant-scoped records (the central-pinned models still write central).
            $user = User::create([
                'id' => Str::uuid()->toString(),
                'tenant_id' => $tenant->id,
                'name' => $validated['name'],
                'email' => $validated['email'],
                'password' => Hash::make((string) $validated['password']),
                'status' => 'active',
                'email_verified_at' => null,
                'preferences' => [],
            ]);

            $company = Company::create([
                'tenant_id' => $tenant->id,
                'name' => $validated['company_name'],
                'legal_name' => $validated['company_legal_name'] ?? $validated['company_name'],
                'country_code' => $countryCode,
                'tax_id' => $validated['tax_id'] ?? null,
                'phone' => $validated['phone'] ?? null,
                'currency' => $currency,
                'locale' => $locale,
                'timezone' => $timezone,
                'date_format' => $dateFormat,
                'fiscal_year_start_month' => 1,
                'status' => CompanyStatus::Active,
                'is_headquarters' => true,
                'address_street' => $validated['address_street'] ?? null,
                'address_city' => $validated['address_city'] ?? null,
                'address_postal_code' => $validated['address_postal_code'] ?? null,
                'address_state' => $validated['address_state'] ?? null,
                'email' => $validated['email'],
            ]);

            Location::create([
                'id' => Str::uuid()->toString(),
                'company_id' => $company->id,
                'name' => 'Main Location',
                'code' => 'MAIN',
                'type' => 'shop',
                'is_default' => true,
                'is_active' => true,
                'pos_enabled' => false,
                'address_street' => $company->address_street,
                'address_city' => $company->address_city,
                'address_postal_code' => $company->address_postal_code,
                'address_country' => $company->country_code,
                'phone' => $company->phone,
                'email' => $company->email,
            ]);

            UserCompanyMembership::create([
                'user_id' => $user->id,
                'company_id' => $company->id,
                'role' => MembershipRole::Owner,
                'is_primary' => true,
                'status' => MembershipStatus::Active,
                'accepted_at' => now(),
            ]);

            // Full per-tenant init (roles + reference data + CoA + tax + payment;
            // the trial subscription writes central via the pinned model).
            $this->tenantInitializationService->initializeForNewRegistration($tenant, $company, $user);

            // Central identity index (pinned to central) — recorded with the
            // now-known tenant-side user id.
            $this->identityIndexService->record($user->email, $tenant->id, $user->id);

            $device = $deviceHandler !== null ? $deviceHandler($user) : null;

            $token = $user->createToken(
                $validated['device_name'] ?? 'api-token',
                array_merge(['tenant:'.$tenant->id], $tokenAbilities),
                $tokenExpiresAt,
            );

            tenancy()->end();

            return [
                'user' => $user,
                'company' => $company,
                'token' => $token->plainTextToken,
                'device' => $device,
            ];
        } catch (\Throwable $e) {
            $this->compensate($tenant, $databaseCreated);

            throw $e;
        }
    }

    /**
     * Roll back a partially-provisioned tenant: revert tenancy, drop the tenant
     * database, then delete the central rows (children before the tenant row).
     */
    private function compensate(Tenant $tenant, bool $databaseCreated): void
    {
        if (tenancy()->initialized) {
            tenancy()->end();
        }

        if ($databaseCreated) {
            try {
                DB::purge('tenant');
                $tenant->database()->manager()->deleteDatabase($tenant);
            } catch (\Throwable) {
                // best-effort; reconcile sweeps stragglers
            }
        }

        DB::connection('central')->table('tenant_subscriptions')->where('tenant_id', $tenant->id)->delete();
        DB::connection('central')->table('central_identities')->where('tenant_id', $tenant->id)->delete();
        DB::connection('central')->table('domains')->where('tenant_id', $tenant->id)->delete();
        DB::connection('central')->table('tenants')->where('id', $tenant->id)->delete();
    }
}
