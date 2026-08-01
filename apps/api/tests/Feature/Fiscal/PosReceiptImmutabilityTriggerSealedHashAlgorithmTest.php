<?php

declare(strict_types=1);

namespace Tests\Feature\Fiscal;

use App\Modules\Company\Domain\Company;
use App\Modules\Company\Domain\Location;
use App\Modules\Company\Services\CompanyContext;
use App\Modules\Identity\Domain\User;
use App\Modules\Partner\Domain\Partner;
use App\Modules\POS\Domain\Enums\FiscalStatus;
use App\Modules\POS\Domain\Receipt;
use App\Modules\POS\Domain\Terminal;
use App\Modules\Tenant\Domain\Tenant;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
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

    // =================================================================
    // FINAL-REVIEW CONDITION 1 (I-1) — the §6.2 branch must guard the SAME
    // column set as its sibling (the FK-cleanup branch immediately above
    // it), so the NF525 immutability defense is not widened.
    //
    // Why these cases matter: the §6.2 entry condition
    // (`OLD.sealed_hash_algorithm IS NULL`) matches EVERY `pos_receipts`
    // row that existed before this feature. Without the guards below, a
    // single `UPDATE pos_receipts SET sealed_hash_algorithm = …,
    // customer_name = …, is_voided = true WHERE …` on any pre-feature
    // fiscalized row would pass the last line of defense for NF525
    // inalterability and leave the row silently disagreeing with its own
    // signed `canonical_bytes`.
    //
    // Every test below mutates a PRE-FEATURE row (sealed_hash_algorithm
    // IS NULL, i.e. the whole installed base) and must be REJECTED through
    // the new branch.
    // =================================================================

    public function test_transition_combined_with_a_customer_name_change_is_rejected(): void
    {
        $receipt = $this->fiscalizedReceipt();

        $this->expectException(QueryException::class);
        $this->expectExceptionMessageMatches('/fiscally sealed and cannot be modified/');

        $receipt->sealed_hash_algorithm = 'legacy_pipe_v1';
        $receipt->customer_name = 'Smuggled Customer';
        $receipt->save();
    }

    public function test_transition_combined_with_a_customer_identifier_change_is_rejected(): void
    {
        $receipt = $this->fiscalizedReceipt();

        $this->expectException(QueryException::class);
        $this->expectExceptionMessageMatches('/fiscally sealed and cannot be modified/');

        $receipt->sealed_hash_algorithm = 'legacy_pipe_v1';
        $receipt->customer_identifier = 'TN-999-SMUGGLED';
        $receipt->save();
    }

    public function test_transition_combined_with_a_partner_id_change_is_rejected(): void
    {
        $receipt = $this->fiscalizedReceipt();
        $partner = Partner::factory()->create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
        ]);

        $this->expectException(QueryException::class);
        $this->expectExceptionMessageMatches('/fiscally sealed and cannot be modified/');

        // NULL -> value. The sibling FK-cleanup branch only ever permits
        // the OPPOSITE direction (value -> NULL), so this can only ever be
        // matched by the §6.2 branch.
        $receipt->sealed_hash_algorithm = 'legacy_pipe_v1';
        $receipt->partner_id = $partner->id;
        $receipt->save();
    }

    public function test_transition_combined_with_a_contact_id_change_is_rejected(): void
    {
        $receipt = $this->fiscalizedReceipt();
        $contactId = (string) Str::uuid();
        DB::table('contacts')->insert([
            'id' => $contactId,
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'first_name' => 'Smuggled',
            'last_name' => 'Contact',
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->expectException(QueryException::class);
        $this->expectExceptionMessageMatches('/fiscally sealed and cannot be modified/');

        $receipt->sealed_hash_algorithm = 'legacy_pipe_v1';
        $receipt->contact_id = $contactId;
        $receipt->save();
    }

    public function test_transition_combined_with_a_fiscal_status_change_is_rejected(): void
    {
        $receipt = $this->fiscalizedReceipt();

        $this->expectException(QueryException::class);
        $this->expectExceptionMessageMatches('/fiscally sealed and cannot be modified/');

        // `fiscalized -> pending_seal` is matched by NO earlier branch, so
        // without `NEW.fiscal_status = OLD.fiscal_status` on the §6.2
        // branch this walks a sealed receipt back out of its sealed state.
        $receipt->sealed_hash_algorithm = 'legacy_pipe_v1';
        $receipt->fiscal_status = FiscalStatus::PendingSeal;
        $receipt->save();
    }

    public function test_transition_combined_with_an_is_voided_change_is_rejected(): void
    {
        $receipt = $this->fiscalizedReceipt();
        $voider = User::factory()->create(['tenant_id' => $this->tenant->id]);

        $this->expectException(QueryException::class);
        $this->expectExceptionMessageMatches('/fiscally sealed and cannot be modified/');

        // `fiscal_status` stays 'fiscalized', so the void branch (which
        // requires NEW.fiscal_status = 'voided') never matches — the row is
        // flipped to voided WITHOUT the void transition's own checks.
        // `voided_at` / `voided_by` are set only to satisfy the
        // `pos_receipts_void_logic` CHECK, so the rejection can only come
        // from the BEFORE-UPDATE trigger.
        $receipt->sealed_hash_algorithm = 'legacy_pipe_v1';
        $receipt->is_voided = true;
        $receipt->voided_at = now();
        $receipt->voided_by = $voider->id;
        $receipt->save();
    }

    public function test_the_permitted_transition_still_passes_on_a_pre_feature_row(): void
    {
        // Companion to the six rejections above: tightening the branch must
        // NOT close the one transition it exists to permit. A pre-feature
        // row (sealed_hash_algorithm IS NULL) still seals exactly once.
        $receipt = $this->fiscalizedReceipt();
        self::assertNull($receipt->sealed_hash_algorithm);

        $receipt->sealed_hash_algorithm = 'canonical_json_v3';
        $receipt->save();

        $fresh = $receipt->fresh();
        self::assertNotNull($fresh);
        self::assertSame('canonical_json_v3', $fresh->sealed_hash_algorithm);
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
