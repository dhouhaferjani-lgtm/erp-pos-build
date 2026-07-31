<?php

declare(strict_types=1);

namespace Tests\Feature\Fiscal;

use App\Modules\Company\Domain\Company;
use App\Modules\Company\Domain\Location;
use App\Modules\Company\Services\CompanyContext;
use App\Modules\POS\Domain\Enums\FiscalStatus;
use App\Modules\POS\Domain\Receipt;
use App\Modules\POS\Domain\Terminal;
use App\Modules\Tenant\Domain\Tenant;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * PG trigger regression test for the v3-refund-chain-integration spec §6.2
 * one-time `sealed_hash_algorithm` NULL->value transition on
 * `prevent_receipt_modification()`.
 *
 * PG-only — the trigger doesn't exist on SQLite (the migration guards on
 * `getDriverName() !== 'pgsql'`), so this suite MUST run under
 * `phpunit-pgsql.xml`:
 *
 *   php artisan test -c phpunit-pgsql.xml --filter=PosReceiptImmutabilityTriggerSealedHashAlgorithmTest
 *
 * (a) the new transition succeeds exactly once per row,
 * (b) a second attempt to change an already-set `sealed_hash_algorithm` is
 *     rejected (SQLSTATE 23000, `integrity_constraint_violation`),
 * (c) every pre-existing immutability case (changing `total`, deleting a
 *     fiscalized row) still fails exactly as it does today — a TRUE
 *     regression test, not merely a new-behavior test.
 */
final class PosReceiptImmutabilityTriggerSealedHashAlgorithmTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private Company $company;

    private Location $location;

    private Terminal $terminal;

    protected function setUp(): void
    {
        parent::setUp();

        if (DB::connection()->getDriverName() !== 'pgsql') {
            $this->markTestSkipped('sealed_hash_algorithm backfill-transition trigger is PG-only; run via phpunit-pgsql.xml.');
        }

        app(CompanyContext::class)->clear();

        $this->tenant = Tenant::factory()->create();
        $this->company = Company::factory()->create([
            'tenant_id' => $this->tenant->id,
        ]);
        // ReceiptFactory::definition() defaults `location_id`/`terminal_id`
        // to UNOVERRIDDEN `Location::factory()`/`Terminal::factory()`, whose
        // own definitions default `company_id`/`tenant_id` to null or an
        // unrelated `Company::factory()` with no tenant_id at all — every
        // Receipt::factory()->create() call in this file MUST override both
        // explicitly or it silently creates orphan tenant/company/location/
        // terminal rows unrelated to this test's fixture (and, since
        // LocationFactory's nested Company::factory() never sets tenant_id,
        // a NOT NULL violation on `companies.tenant_id`/`pos_terminals.tenant_id`).
        $this->location = Location::factory()->create([
            'company_id' => $this->company->id,
        ]);
        $this->terminal = Terminal::factory()->create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'location_id' => $this->location->id,
        ]);
    }

    private function fiscalizedReceipt(): Receipt
    {
        /** @var Receipt $receipt */
        $receipt = Receipt::factory()->create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'location_id' => $this->location->id,
            'terminal_id' => $this->terminal->id,
            'fiscal_status' => 'fiscalized',
            'sealed_hash_algorithm' => null,
        ]);

        return $receipt;
    }

    // (a) the NULL -> value transition succeeds exactly once per row.

    public function test_null_to_value_transition_on_sealed_hash_algorithm_succeeds(): void
    {
        $receipt = $this->fiscalizedReceipt();

        $receipt->sealed_hash_algorithm = 'legacy_pipe_v1';
        $receipt->save();

        $fresh = $receipt->fresh();
        self::assertNotNull($fresh);
        self::assertSame('legacy_pipe_v1', $fresh->sealed_hash_algorithm);
    }

    // (b) a SECOND attempt to change an already-set value is rejected.

    public function test_second_attempt_to_change_already_set_sealed_hash_algorithm_is_rejected(): void
    {
        $receipt = $this->fiscalizedReceipt();
        $receipt->sealed_hash_algorithm = 'legacy_pipe_v1';
        $receipt->save();

        $this->expectException(QueryException::class);
        $this->expectExceptionMessageMatches('/fiscally sealed and cannot be modified/');

        $receipt->sealed_hash_algorithm = 'canonical_json_v3';
        $receipt->save();
    }

    public function test_second_attempt_to_change_already_set_sealed_hash_algorithm_back_to_null_is_rejected(): void
    {
        $receipt = $this->fiscalizedReceipt();
        $receipt->sealed_hash_algorithm = 'canonical_json_v3';
        $receipt->save();

        $this->expectException(QueryException::class);

        $receipt->sealed_hash_algorithm = null;
        $receipt->save();
    }

    // The one-time transition may NOT smuggle a change to any other
    // fiscal-immutable column alongside it.

    public function test_transition_combined_with_a_total_change_is_rejected(): void
    {
        $receipt = $this->fiscalizedReceipt();

        $this->expectException(QueryException::class);

        $receipt->sealed_hash_algorithm = 'legacy_pipe_v1';
        $receipt->total = bcadd((string) $receipt->total, '1.000', 3);
        $receipt->save();
    }

    // (c) pre-existing immutability cases still fail exactly as today —
    // TRUE regression, not merely new-behavior coverage.

    public function test_changing_total_on_a_fiscalized_receipt_is_still_rejected(): void
    {
        $receipt = $this->fiscalizedReceipt();

        $this->expectException(QueryException::class);
        $this->expectExceptionMessageMatches('/fiscally sealed and cannot be modified/');

        $receipt->total = bcadd((string) $receipt->total, '1.000', 3);
        $receipt->save();
    }

    public function test_deleting_a_fiscalized_receipt_is_still_rejected(): void
    {
        $receipt = $this->fiscalizedReceipt();

        $this->expectException(QueryException::class);
        $this->expectExceptionMessageMatches('/Cannot delete fiscally sealed receipt/');

        $receipt->delete();
    }

    public function test_pending_seal_to_fiscalized_transition_still_succeeds(): void
    {
        /** @var Receipt $receipt */
        $receipt = Receipt::factory()->pendingSeal()->create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'location_id' => $this->location->id,
            'terminal_id' => $this->terminal->id,
        ]);

        $receipt->fiscal_status = FiscalStatus::Fiscalized;
        $receipt->fiscal_hash = str_repeat('c', 64);
        $receipt->chain_sequence = 1;
        $receipt->save();

        $fresh = $receipt->fresh();
        self::assertNotNull($fresh);
        self::assertSame(FiscalStatus::Fiscalized, $fresh->fiscal_status);
    }

    public function test_fiscalized_to_voided_transition_still_succeeds(): void
    {
        $receipt = $this->fiscalizedReceipt();

        $receipt->fiscal_status = FiscalStatus::Voided;
        $receipt->save();

        $fresh = $receipt->fresh();
        self::assertNotNull($fresh);
        self::assertSame(FiscalStatus::Voided, $fresh->fiscal_status);
    }
}
