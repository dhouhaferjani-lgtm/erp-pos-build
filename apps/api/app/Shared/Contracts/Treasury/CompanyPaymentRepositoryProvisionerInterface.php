<?php

declare(strict_types=1);

namespace App\Shared\Contracts\Treasury;

/**
 * Every company — not just the tenant's first — is born with its day-one
 * treasury: one cash register (`CASH-01`) and one safe (`SAFE-01`), both at a
 * ZERO balance, with no bank identity and no movements (DPA lane H-3 owner
 * ruling 2026-08-09). Money only ever enters through the opening-balance
 * document lane (`AccountingOpeningService`), never through provisioning.
 *
 * Lives in `Shared/Contracts` because the callers are outside Treasury: the
 * Company module provisions on company create, the Tenant module's
 * registration path provisions through `PaymentRepositorySeeder`. Module
 * boundaries are sacred (CLAUDE.md rule 6) — neither may reach into Treasury's
 * models.
 *
 * The signature is SCALAR-ONLY on purpose. The shared kernel may not depend on
 * any module tier: typing this `Company` / `Location` puts two
 * `SharedContracts -> ModuleDomain` edges into deptrac. This mirrors the
 * sibling {@see LocationCashRegisterProvisionerInterface}, which is the only
 * other provisioning port exposed here and is already scalar-typed. (G-12's
 * `UnitsProvisioningService` is module-typed but is NOT exposed through a
 * Shared contract at all — it is injected as a concrete module service — so it
 * sets no precedent for this boundary.)
 */
interface CompanyPaymentRepositoryProvisionerInterface
{
    /**
     * Create the company's day-one cash register and safe if they are missing.
     *
     * Idempotent: an existing `CASH-01` / `SAFE-01` is left exactly as it is —
     * including an operator-repointed GL account — and a partially provisioned
     * company gains only the code it lacks. Never mints a duplicate.
     *
     * `$defaultLocationId` is nullable because the caller may not have one:
     * `PaymentRepositorySeeder` runs on the registration path where the
     * location table can legitimately still be empty. When null the
     * implementation resolves the company's own default/active/POS location,
     * and a NULL `location_id` is still the tender resolver's tier 2 — i.e.
     * today's behaviour, never a failure.
     *
     * Failure-contained: a missing cash-purpose account or an insert failure is
     * logged and swallowed, so company creation never fails on treasury
     * provisioning.
     */
    public function provisionForCompany(string $tenantId, string $companyId, ?string $defaultLocationId = null): void;
}
