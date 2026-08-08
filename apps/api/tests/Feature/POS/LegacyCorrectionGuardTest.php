<?php

declare(strict_types=1);

namespace Tests\Feature\POS;

use App\Modules\Company\Domain\Company;
use App\Modules\Company\Domain\Location;
use App\Modules\POS\Application\Services\LegacyCorrectionGuard;
use App\Modules\POS\Domain\Exceptions\LegacyCorrectionRetiredException;
use App\Modules\POS\Domain\Terminal;
use App\Modules\Tenant\Domain\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * v3-refund-chain-integration spec §9.1/§9.3/§9.4 —
 * `LegacyCorrectionGuard::assertLegacyCorrectionAllowed()`.
 *
 * **Conditioned on ACKNOWLEDGEMENT, not raw fiscal_schema_version (§9.3).**
 * D1 defaults every terminal created post-merge to schema 3 at
 * provisioning time — gating on schema version alone would retire the
 * legacy path the instant a terminal is created, long before its device
 * has ever pulled/acknowledged the capability flag. These tests
 * explicitly prove the guard is decoupled from schema version: a v3
 * terminal that has NOT acknowledged is allowed through unchanged (the
 * v2-non-regression case generalizes to "any not-yet-acknowledged
 * terminal", proven at both schema 2 AND schema 3).
 */
final class LegacyCorrectionGuardTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private Company $company;

    private function terminal(int $fiscalSchemaVersion, ?\DateTimeInterface $acknowledgedAt): Terminal
    {
        $this->tenant = $this->tenant ?? Tenant::factory()->create();
        $this->company = $this->company ?? Company::factory()->create(['tenant_id' => $this->tenant->id]);
        $location = Location::factory()->create(['company_id' => $this->company->id]);

        return Terminal::factory()->create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'location_id' => $location->id,
            'fiscal_schema_version' => $fiscalSchemaVersion,
            'v4_refund_authoring_acknowledged_at' => $acknowledgedAt,
        ]);
    }

    // =================================================================
    // (a) v2-non-regression — fixture forces version 2 explicitly.
    // =================================================================

    public function test_v2_terminal_not_acknowledged_is_allowed(): void
    {
        $terminal = $this->terminal(fiscalSchemaVersion: 2, acknowledgedAt: null);

        $guard = new LegacyCorrectionGuard;
        $guard->assertLegacyCorrectionAllowed($terminal);

        $this->addToAssertionCount(1); // no exception == pass
    }

    // (b) A v3 (or v4-schema) terminal that has NOT acknowledged is ALSO
    // allowed — proving the guard does not key on raw schema version.

    public function test_v3_terminal_not_acknowledged_is_allowed(): void
    {
        $terminal = $this->terminal(fiscalSchemaVersion: 3, acknowledgedAt: null);

        $guard = new LegacyCorrectionGuard;
        $guard->assertLegacyCorrectionAllowed($terminal);

        $this->addToAssertionCount(1);
    }

    // (c) An ACKNOWLEDGED terminal is retired — regardless of schema
    // version (a v2 terminal that somehow acknowledged, defensive case,
    // must also be blocked; acknowledgement implies v4-capable in
    // practice, but the guard's own condition is acknowledgement alone).

    public function test_acknowledged_terminal_throws_legacy_correction_retired(): void
    {
        $terminal = $this->terminal(fiscalSchemaVersion: 3, acknowledgedAt: now());

        $guard = new LegacyCorrectionGuard;

        $this->expectException(LegacyCorrectionRetiredException::class);
        $guard->assertLegacyCorrectionAllowed($terminal);
    }

    // =================================================================
    // End-to-end coverage.
    //
    // DPA V9 (owner ruling D3): the two e2e cases that drove the guard
    // through `ReceiptVoidService` were removed with that service — the
    // legacy void endpoint is SUNSET (410 `LEGACY_VOID_RETIRED`). The
    // guard's SURVIVING caller is `ReceiptReturnService`, and its
    // end-to-end 409 + `LEGACY_CORRECTION_RETIRED` contract is pinned by
    // ReceiptReturnRefactorV3Test::
    // test_legacy_return_http_endpoint_returns_409_on_a_v4_acknowledged_terminal.
    // The (a)/(b)/(c) cases above remain the guard's own unit contract and
    // are unchanged by V9.
    // =================================================================
}
