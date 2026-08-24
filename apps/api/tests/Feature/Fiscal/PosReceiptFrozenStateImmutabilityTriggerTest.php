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
 * Session B lane Q-6 — `prevent_receipt_modification()` whitelist-with-ELSE-RAISE.
 *
 * Before this lane the UPDATE arm branched only on
 * `OLD.fiscal_status = 'pending_seal'` and `= 'fiscalized'`. The four other
 * values the column CHECK admits — `voided`, `pending_sync`, `synced`,
 * `sync_failed` — fell straight through every guard to the bare
 * `RETURN NEW`, so a voided fiscal receipt could be un-voided and have its
 * `fiscal_hash` / `receipt_number` / totals / `chain_sequence` rewritten at
 * the DB layer. The trigger is the only backstop; there is no
 * application-layer state machine above it.
 *
 * PG-only — the trigger does not exist on SQLite (every migration that
 * defines it guards on `getDriverName() !== 'pgsql'`), so this suite MUST
 * run under `phpunit-pgsql.xml` and SKIPS on the default SQLite config:
 *
 *   php artisan test -c phpunit-pgsql.xml --filter=PosReceiptFrozenStateImmutabilityTriggerTest
 *
 * Coverage:
 *  (a) the four frozen states reject every non-no-op UPDATE,
 *  (b) a strict no-op UPDATE on a frozen row is permitted,
 *  (c) the carried-forward `sealed_hash_algorithm` backfill allowance on a
 *      frozen row survives — and may not smuggle another column,
 *  (d) an out-of-whitelist `fiscal_status` hits the ELSE RAISE instead of
 *      falling through,
 *  (e) every pre-existing legal branch (`pending_seal` self-transition,
 *      `pending_seal -> fiscalized`, `fiscalized -> voided` with its
 *      immutable-field diff check, the FK-cleanup branch, the
 *      `sealed_hash_algorithm` backfill branch, the `fiscalized` catch-all
 *      raise, the `pending_seal` narrow raise and the DELETE arm) behaves
 *      exactly as it did before — TRUE regression pins, one per branch.
 */
final class PosReceiptFrozenStateImmutabilityTriggerTest extends TestCase
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
            $this->markTestSkipped('prevent_receipt_modification() is PG-only; run via phpunit-pgsql.xml.');
        }

        app(CompanyContext::class)->clear();

        $this->tenant = Tenant::factory()->create();
        $this->company = Company::factory()->create([
            'tenant_id' => $this->tenant->id,
        ]);
        // ReceiptFactory::definition() defaults `location_id`/`terminal_id`
        // to UNOVERRIDDEN factories whose own definitions default
        // `company_id`/`tenant_id` to null or an unrelated Company — every
        // create() below MUST override both explicitly.
        $this->location = Location::factory()->create([
            'company_id' => $this->company->id,
        ]);
        $this->terminal = Terminal::factory()->create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'location_id' => $this->location->id,
        ]);
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function fiscalizedReceipt(array $overrides = []): Receipt
    {
        /** @var Receipt $receipt */
        $receipt = Receipt::factory()->create(array_merge([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'location_id' => $this->location->id,
            'terminal_id' => $this->terminal->id,
            'fiscal_status' => FiscalStatus::Fiscalized->value,
            'sealed_hash_algorithm' => null,
        ], $overrides));

        return $receipt;
    }

    /**
     * A genuinely VOIDED row: sealed first, then moved across the one legal
     * `fiscalized -> voided` edge (the same shape
     * BackfillSealedHashAlgorithmCommandTest::voidReceipt() uses).
     * `voided_at`/`voided_by` must be non-null together per the
     * `pos_receipts_void_logic` CHECK.
     */
    private function voidedReceipt(): Receipt
    {
        $receipt = $this->fiscalizedReceipt();
        $voider = User::factory()->create(['tenant_id' => $this->tenant->id]);

        DB::table('pos_receipts')->where('id', $receipt->id)->update([
            'is_voided' => true,
            'fiscal_status' => FiscalStatus::Voided->value,
            'voided_at' => now(),
            'voided_by' => $voider->id,
        ]);

        /** @var Receipt $fresh */
        $fresh = $receipt->fresh();

        return $fresh;
    }

    /**
     * A row parked in one of the three device-sync states. No server code
     * writes these today (that is exactly why they were never covered), so
     * the row is created directly in the state.
     */
    private function receiptInState(string $fiscalStatus): Receipt
    {
        return $this->fiscalizedReceipt(['fiscal_status' => $fiscalStatus]);
    }

    /**
     * @return array<string, array{string}>
     */
    public static function frozenStateProvider(): array
    {
        return [
            'voided' => ['voided'],
            'pending_sync' => ['pending_sync'],
            'synced' => ['synced'],
            'sync_failed' => ['sync_failed'],
        ];
    }

    // =================================================================
    // (a) THE HOLE — every one of these UPDATEs SUCCEEDS today.
    // =================================================================

    public function test_un_voiding_a_voided_receipt_is_rejected(): void
    {
        $receipt = $this->voidedReceipt();

        $this->expectException(QueryException::class);
        $this->expectExceptionMessageMatches('/frozen/');

        DB::table('pos_receipts')->where('id', $receipt->id)->update([
            'fiscal_status' => FiscalStatus::Fiscalized->value,
            'is_voided' => false,
            'voided_at' => null,
            'voided_by' => null,
        ]);
    }

    public function test_rewriting_the_totals_of_a_voided_receipt_is_rejected(): void
    {
        $receipt = $this->voidedReceipt();

        $this->expectException(QueryException::class);
        $this->expectExceptionMessageMatches('/frozen/');

        // Kept internally consistent so `pos_receipts_totals` cannot be the
        // thing that rejects this — the rejection must come from the trigger.
        DB::table('pos_receipts')->where('id', $receipt->id)->update([
            'subtotal' => '1000.000',
            'tax_amount' => '190.000',
            'total' => '1190.000',
        ]);
    }

    public function test_rewriting_the_fiscal_identity_of_a_voided_receipt_is_rejected(): void
    {
        $receipt = $this->voidedReceipt();

        $this->expectException(QueryException::class);
        $this->expectExceptionMessageMatches('/frozen/');

        DB::table('pos_receipts')->where('id', $receipt->id)->update([
            'fiscal_hash' => str_repeat('f', 64),
            'receipt_number' => 'T001-C001-L01-POS01-'.date('Y').'-99999999',
            'chain_sequence' => 424242,
        ]);
    }

    public function test_walking_a_voided_receipt_back_to_pending_seal_is_rejected(): void
    {
        $receipt = $this->voidedReceipt();

        $this->expectException(QueryException::class);
        $this->expectExceptionMessageMatches('/frozen/');

        DB::table('pos_receipts')->where('id', $receipt->id)->update([
            'fiscal_status' => FiscalStatus::PendingSeal->value,
        ]);
    }

    /**
     * @dataProvider frozenStateProvider
     */
    public function test_a_frozen_receipt_cannot_have_its_total_rewritten(string $fiscalStatus): void
    {
        $receipt = $fiscalStatus === 'voided'
            ? $this->voidedReceipt()
            : $this->receiptInState($fiscalStatus);

        $this->expectException(QueryException::class);
        $this->expectExceptionMessageMatches('/frozen/');

        DB::table('pos_receipts')->where('id', $receipt->id)->update([
            'subtotal' => '2000.000',
            'tax_amount' => '380.000',
            'total' => '2380.000',
        ]);
    }

    /**
     * @dataProvider frozenStateProvider
     */
    public function test_a_frozen_receipt_cannot_be_moved_to_fiscalized(string $fiscalStatus): void
    {
        $receipt = $fiscalStatus === 'voided'
            ? $this->voidedReceipt()
            : $this->receiptInState($fiscalStatus);

        $this->expectException(QueryException::class);
        $this->expectExceptionMessageMatches('/frozen/');

        DB::table('pos_receipts')->where('id', $receipt->id)->update([
            'fiscal_status' => FiscalStatus::Fiscalized->value,
            'is_voided' => false,
            'voided_at' => null,
            'voided_by' => null,
        ]);
    }

    /**
     * PINNED DECISION — `updated_at`-only writes (Laravel `touch()`) on a
     * frozen row are REJECTED.
     *
     * "Strict no-op" is strict: EVERY column, `updated_at` included, must be
     * `IS NOT DISTINCT FROM` its old value. A frozen receipt is an archival
     * row; nothing in the application touches one (Eloquent `save()` on a
     * clean model issues no UPDATE statement at all, so ordinary model code
     * never reaches this), and the one live writer that does reach a frozen
     * row — the `sealed_hash_algorithm` backfill — has its own explicit
     * branch below, which is the only place an `updated_at` bump is allowed.
     */
    public function test_a_touch_style_updated_at_only_write_on_a_voided_receipt_is_rejected(): void
    {
        $receipt = $this->voidedReceipt();

        $this->expectException(QueryException::class);
        $this->expectExceptionMessageMatches('/frozen/');

        DB::table('pos_receipts')->where('id', $receipt->id)->update([
            'updated_at' => now()->addMinute(),
        ]);
    }

    public function test_the_sealed_hash_algorithm_backfill_on_a_voided_row_may_not_smuggle_another_column(): void
    {
        $receipt = $this->voidedReceipt();
        self::assertNull($receipt->sealed_hash_algorithm);

        $this->expectException(QueryException::class);
        $this->expectExceptionMessageMatches('/frozen/');

        DB::table('pos_receipts')->where('id', $receipt->id)->update([
            'sealed_hash_algorithm' => 'legacy_pipe_v1',
            'customer_name' => 'Smuggled Customer',
        ]);
    }

    public function test_a_second_sealed_hash_algorithm_write_on_a_voided_row_is_rejected(): void
    {
        $receipt = $this->voidedReceipt();

        DB::table('pos_receipts')->where('id', $receipt->id)->update([
            'sealed_hash_algorithm' => 'legacy_pipe_v1',
        ]);

        $this->expectException(QueryException::class);
        $this->expectExceptionMessageMatches('/frozen/');

        DB::table('pos_receipts')->where('id', $receipt->id)->update([
            'sealed_hash_algorithm' => 'canonical_json_v3',
        ]);
    }

    // =================================================================
    // (d) ELSE RAISE — a fiscal_status outside the whitelist must never
    // fall through to `RETURN NEW` again.
    // =================================================================

    public function test_an_out_of_whitelist_fiscal_status_hits_the_else_raise(): void
    {
        // The column CHECK admits exactly the six known values, so the only
        // way to exercise the ELSE arm is to admit a seventh — which is
        // precisely the future change this ELSE exists to catch. PG DDL is
        // transactional, so RefreshDatabase's rollback undoes this. The row
        // must be INSERTed in the unknown state (`fiscal_status` is
        // varchar(20), so keep the invented value short): UPDATEing an
        // existing row into it is itself — correctly — refused by the
        // `fiscalized` arm.
        DB::statement('ALTER TABLE pos_receipts DROP CONSTRAINT pos_receipts_fiscal_status_check');
        $id = $this->insertRawReceipt('quarantined', 66666666);

        $this->expectException(QueryException::class);
        $this->expectExceptionMessageMatches('/no UPDATE policy/');

        DB::table('pos_receipts')->where('id', $id)->update([
            'total' => '1.000',
            'subtotal' => '1.000',
            'tax_amount' => '0.000',
        ]);
    }

    /**
     * Raw INSERT (the trigger is `BEFORE UPDATE OR DELETE`, so INSERT is
     * untouched) — the only way to place a row in a state no legal
     * transition can reach.
     */
    private function insertRawReceipt(string $fiscalStatus, int $sequence): string
    {
        $id = (string) Str::uuid();

        DB::table('pos_receipts')->insert([
            'id' => $id,
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'location_id' => $this->location->id,
            'terminal_id' => $this->terminal->id,
            'receipt_number' => 'T001-C001-L01-POS01-'.date('Y').'-'.$sequence,
            'chain_sequence' => $sequence,
            'receipt_year' => (int) date('Y'),
            'fiscal_hash' => str_repeat('b', 64),
            'vat_breakdown_hash' => str_repeat('b', 64),
            'payment_methods_hash' => str_repeat('b', 64),
            'posted_at' => now(),
            'cashier_id' => User::factory()->create(['tenant_id' => $this->tenant->id])->id,
            'cashier_name' => 'Inserter',
            'subtotal' => '10.000',
            'tax_amount' => '1.900',
            'total' => '11.900',
            'currency' => 'EUR',
            'is_voided' => false,
            'fiscal_status' => $fiscalStatus,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $id;
    }

    // =================================================================
    // (b) + (c) The two allowances the frozen states KEEP.
    // =================================================================

    /**
     * @dataProvider frozenStateProvider
     */
    public function test_a_strict_no_op_update_on_a_frozen_receipt_is_permitted(string $fiscalStatus): void
    {
        $receipt = $fiscalStatus === 'voided'
            ? $this->voidedReceipt()
            : $this->receiptInState($fiscalStatus);

        // Every written value equals the stored one — the query builder
        // does not touch timestamps, so NEW is byte-identical to OLD.
        $affected = DB::table('pos_receipts')->where('id', $receipt->id)->update([
            'fiscal_status' => $fiscalStatus,
            'total' => $receipt->total,
        ]);

        self::assertSame(1, $affected);

        $fresh = $receipt->fresh();
        self::assertNotNull($fresh);
        self::assertSame($fiscalStatus, $fresh->fiscal_status->value);
    }

    /**
     * CARRIED-FORWARD ALLOWANCE — `BackfillSealedHashAlgorithmCommand`
     * walks an `is_voided`/`fiscal_status`-UNFILTERED query
     * (BackfillSealedHashAlgorithmCommand.php:176-179) and `save()`s the
     * discriminator onto every legacy row it can classify, VOIDED ROWS
     * INCLUDED — asserted today by
     * BackfillSealedHashAlgorithmCommandTest::test_a_voided_row_interspersed_does_not_break_discrimination_of_the_row_after_it.
     * Freezing the voided state without this branch would abort that
     * command mid-run on any legacy tenant. The write is the one-time
     * NULL -> value discriminator ONLY (plus Eloquent's `updated_at`);
     * every other column must be byte-identical.
     *
     * @dataProvider frozenStateProvider
     */
    public function test_the_one_time_sealed_hash_algorithm_backfill_is_still_permitted_on_a_frozen_row(string $fiscalStatus): void
    {
        $receipt = $fiscalStatus === 'voided'
            ? $this->voidedReceipt()
            : $this->receiptInState($fiscalStatus);

        self::assertNull($receipt->sealed_hash_algorithm);

        // Eloquent save(): the discriminator PLUS an `updated_at` bump —
        // the exact statement the backfill command issues.
        $receipt->sealed_hash_algorithm = 'legacy_pipe_v1';
        $receipt->save();

        $fresh = $receipt->fresh();
        self::assertNotNull($fresh);
        self::assertSame('legacy_pipe_v1', $fresh->sealed_hash_algorithm);
        self::assertSame($fiscalStatus, $fresh->fiscal_status->value);
    }

    // =================================================================
    // (e) REGRESSION PINS — one per carried-forward branch of the old body.
    // =================================================================

    public function test_pending_seal_to_fiscalized_still_succeeds(): void
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

    /**
     * The old body allowed ANY column edit on a `pending_seal` row as long
     * as the new status stayed `pending_seal` (it reached the bare
     * `RETURN NEW` at the bottom). That is a deliberate allowance — an
     * un-sealed receipt is still being built — and it is carried forward
     * verbatim.
     */
    public function test_pending_seal_self_transition_with_field_edits_still_succeeds(): void
    {
        /** @var Receipt $receipt */
        $receipt = Receipt::factory()->pendingSeal()->create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'location_id' => $this->location->id,
            'terminal_id' => $this->terminal->id,
        ]);

        $affected = DB::table('pos_receipts')->where('id', $receipt->id)->update([
            'fiscal_status' => FiscalStatus::PendingSeal->value,
            'subtotal' => '100.000',
            'tax_amount' => '19.000',
            'total' => '119.000',
            'customer_name' => 'Edited While Unsealed',
        ]);

        self::assertSame(1, $affected);
        $fresh = $receipt->fresh();
        self::assertNotNull($fresh);
        self::assertSame('Edited While Unsealed', $fresh->customer_name);
    }

    public function test_pending_seal_to_voided_is_still_rejected_with_its_own_message(): void
    {
        /** @var Receipt $receipt */
        $receipt = Receipt::factory()->pendingSeal()->create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'location_id' => $this->location->id,
            'terminal_id' => $this->terminal->id,
        ]);

        $this->expectException(QueryException::class);
        $this->expectExceptionMessageMatches('/can only transition to fiscalized/');

        DB::table('pos_receipts')->where('id', $receipt->id)->update([
            'fiscal_status' => FiscalStatus::Voided->value,
        ]);
    }

    public function test_fiscalized_to_voided_still_succeeds(): void
    {
        $receipt = $this->fiscalizedReceipt();

        $receipt->fiscal_status = FiscalStatus::Voided;
        $receipt->save();

        $fresh = $receipt->fresh();
        self::assertNotNull($fresh);
        self::assertSame(FiscalStatus::Voided, $fresh->fiscal_status);
    }

    public function test_voiding_while_rewriting_an_immutable_field_is_still_rejected(): void
    {
        $receipt = $this->fiscalizedReceipt();

        $this->expectException(QueryException::class);
        $this->expectExceptionMessageMatches('/Cannot modify immutable fields when voiding receipt/');

        DB::table('pos_receipts')->where('id', $receipt->id)->update([
            'fiscal_status' => FiscalStatus::Voided->value,
            'fiscal_hash' => str_repeat('a', 64),
        ]);
    }

    public function test_the_fk_cleanup_branch_still_succeeds(): void
    {
        $partner = Partner::factory()->create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
        ]);
        $receipt = $this->fiscalizedReceipt(['partner_id' => $partner->id]);

        $affected = DB::table('pos_receipts')->where('id', $receipt->id)->update([
            'partner_id' => null,
            'contact_id' => null,
        ]);

        self::assertSame(1, $affected);
        $fresh = $receipt->fresh();
        self::assertNotNull($fresh);
        self::assertNull($fresh->partner_id);
    }

    /**
     * F-1 (gate r1, Critical — folded into this lane).
     *
     * The FK-cleanup branch guarded 12 columns but NOT `fiscal_status` /
     * `is_voided` (unlike the §6.2 branch, which pins both per final-review
     * condition I-1). A caller could therefore piggyback a status downgrade
     * on a legitimate-looking customer-FK cleanup and walk a SEALED receipt
     * back to `pending_seal` — where the trigger allows unrestricted column
     * edits — then rewrite the totals, forge `fiscal_hash`, and re-seal.
     * The gate reproduced that full round trip with no exception at any
     * step; it is pre-existing across all four prior function bodies.
     *
     * This test walks the same round trip. STEP 1 must now raise, which
     * makes steps 2 and 3 unreachable.
     */
    public function test_a_status_downgrade_cannot_piggyback_on_the_fk_cleanup_branch(): void
    {
        $partner = Partner::factory()->create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
        ]);
        $receipt = $this->fiscalizedReceipt(['partner_id' => $partner->id]);

        $this->expectException(QueryException::class);
        $this->expectExceptionMessageMatches('/fiscally sealed and cannot be modified/');

        // STEP 1 — the bypass: a customer-FK cleanup carrying a status
        // downgrade. Everything else is byte-identical, so this matched the
        // FK-cleanup branch verbatim.
        // STEP 2 would have been: UPDATE ... SET total = '9999.000',
        //   subtotal = '9999.000', tax_amount = '0.000',
        //   fiscal_hash = repeat('e', 64)   -- allowed on a pending_seal row
        // STEP 3 would have been: UPDATE ... SET fiscal_status = 'fiscalized'
        //   -- re-sealed, with forged bytes and no trace.
        DB::table('pos_receipts')->where('id', $receipt->id)->update([
            'partner_id' => null,
            'contact_id' => null,
            'fiscal_status' => FiscalStatus::PendingSeal->value,
        ]);
    }

    /**
     * F-1 companion: the same branch must still refuse an `is_voided` flip
     * smuggled through a customer-FK cleanup (flipping the flag WITHOUT
     * `fiscal_status = 'voided'` never reaches the void branch, so it would
     * skip the void edge's immutable-field check entirely).
     */
    public function test_an_is_voided_flip_cannot_piggyback_on_the_fk_cleanup_branch(): void
    {
        $partner = Partner::factory()->create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
        ]);
        $voider = User::factory()->create(['tenant_id' => $this->tenant->id]);
        $receipt = $this->fiscalizedReceipt(['partner_id' => $partner->id]);

        $this->expectException(QueryException::class);
        $this->expectExceptionMessageMatches('/fiscally sealed and cannot be modified/');

        // voided_at/voided_by are set only to satisfy the
        // `pos_receipts_void_logic` CHECK, so the rejection can only come
        // from the trigger.
        DB::table('pos_receipts')->where('id', $receipt->id)->update([
            'partner_id' => null,
            'contact_id' => null,
            'is_voided' => true,
            'voided_at' => now(),
            'voided_by' => $voider->id,
        ]);
    }

    /**
     * F-2 (gate r1 — PARENT RULING: KEEP the freeze; pin the refusal).
     *
     * `pos_receipts.partner_id` carries `ON DELETE SET NULL`, so a HARD
     * delete of a partner makes PG itself issue an UPDATE against every
     * referencing receipt. On a frozen row the freeze refuses it and the
     * partner DELETE aborts. That is deliberate: a fiscal archival row must
     * fail closed, and a future hard-delete path must abort loudly rather
     * than silently mutate a voided receipt.
     *
     * Latent today — `Partner` and `Contact` both use `SoftDeletes` and no
     * `forceDelete()` call site in `app/` touches either — so this test
     * uses a raw DELETE to simulate the hard-delete path that does not yet
     * exist.
     */
    public function test_hard_deleting_a_partner_referenced_by_a_frozen_receipt_is_refused(): void
    {
        $partner = Partner::factory()->create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
        ]);
        // The partner is attached at INSERT (the trigger is BEFORE UPDATE OR
        // DELETE, and no branch permits NULL -> value on partner_id), then
        // the row crosses the legal void edge, which does not guard
        // partner_id.
        $receipt = $this->fiscalizedReceipt(['partner_id' => $partner->id]);
        $voider = User::factory()->create(['tenant_id' => $this->tenant->id]);
        DB::table('pos_receipts')->where('id', $receipt->id)->update([
            'is_voided' => true,
            'fiscal_status' => FiscalStatus::Voided->value,
            'voided_at' => now(),
            'voided_by' => $voider->id,
        ]);

        $this->expectException(QueryException::class);
        $this->expectExceptionMessageMatches('/frozen/');

        DB::table('partners')->where('id', $partner->id)->delete();
    }

    /**
     * F-1 safety / F-2 asymmetry, in its realest form: the FK-cleanup branch
     * exists for PG's own `ON DELETE SET NULL` cascade — no application code
     * nulls `pos_receipts.partner_id`/`contact_id` (grep: zero writers). The
     * two invariance lines added for F-1 must therefore NOT break the
     * cascade on a still-`fiscalized` receipt: the cascade only touches
     * `partner_id`, never `fiscal_status`/`is_voided`.
     *
     * Contrast `test_hard_deleting_a_partner_referenced_by_a_frozen_receipt_is_refused`:
     * same DELETE, frozen receipt, refused. The asymmetry is deliberate and
     * recorded in the migration docblock.
     */
    public function test_hard_deleting_a_partner_referenced_by_a_fiscalized_receipt_still_succeeds(): void
    {
        $partner = Partner::factory()->create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
        ]);
        $receipt = $this->fiscalizedReceipt(['partner_id' => $partner->id]);

        $deleted = DB::table('partners')->where('id', $partner->id)->delete();

        self::assertSame(1, $deleted);
        $fresh = $receipt->fresh();
        self::assertNotNull($fresh);
        self::assertNull($fresh->partner_id, 'the ON DELETE SET NULL cascade must still reach a fiscalized receipt');
        self::assertSame(FiscalStatus::Fiscalized, $fresh->fiscal_status);
    }

    public function test_the_sealed_hash_algorithm_branch_on_a_fiscalized_row_still_succeeds(): void
    {
        $receipt = $this->fiscalizedReceipt();

        $receipt->sealed_hash_algorithm = 'canonical_json_v3';
        $receipt->save();

        $fresh = $receipt->fresh();
        self::assertNotNull($fresh);
        self::assertSame('canonical_json_v3', $fresh->sealed_hash_algorithm);
    }

    /**
     * F-4 (gate r1, Minor — folded because the technique swap is a
     * three-line replacement of an 18-column enumeration and is provably
     * byte-compatible with the two green backfill suites).
     *
     * The `fiscalized` backfill branch enumerated 18 columns, so every
     * column NOT on that list — `previous_hash` above all, which is the
     * chain link the v3 verifier reads — stayed rewritable on every
     * pre-feature row (`sealed_hash_algorithm IS NULL` matches the entire
     * installed base). Replacing the enumeration with the frozen arm's
     * `to_jsonb - keys` comparison closes it by construction, and keeps
     * closing it for columns added to `pos_receipts` in future.
     */
    public function test_the_fiscalized_backfill_branch_cannot_smuggle_previous_hash(): void
    {
        $receipt = $this->fiscalizedReceipt(['previous_hash' => str_repeat('1', 64)]);

        $this->expectException(QueryException::class);
        $this->expectExceptionMessageMatches('/fiscally sealed and cannot be modified/');

        DB::table('pos_receipts')->where('id', $receipt->id)->update([
            'sealed_hash_algorithm' => 'legacy_pipe_v1',
            'previous_hash' => str_repeat('9', 64),
        ]);
    }

    /**
     * F-4 companion — the same swap must not close the transition it exists
     * to permit: the discriminator write PLUS Eloquent's `updated_at` bump,
     * which is exactly the statement
     * `BackfillSealedHashAlgorithmCommand` issues, still passes on a row
     * carrying a non-null `previous_hash`.
     */
    public function test_the_fiscalized_backfill_branch_still_permits_the_discriminator_write(): void
    {
        $receipt = $this->fiscalizedReceipt(['previous_hash' => str_repeat('1', 64)]);

        $receipt->sealed_hash_algorithm = 'legacy_pipe_v1';
        $receipt->save();

        $fresh = $receipt->fresh();
        self::assertNotNull($fresh);
        self::assertSame('legacy_pipe_v1', $fresh->sealed_hash_algorithm);
        self::assertSame(str_repeat('1', 64), $fresh->previous_hash);
    }

    public function test_changing_total_on_a_fiscalized_receipt_is_still_rejected(): void
    {
        $receipt = $this->fiscalizedReceipt();

        $this->expectException(QueryException::class);
        $this->expectExceptionMessageMatches('/fiscally sealed and cannot be modified/');

        DB::table('pos_receipts')->where('id', $receipt->id)->update([
            'subtotal' => '500.000',
            'tax_amount' => '95.000',
            'total' => '595.000',
        ]);
    }

    public function test_deleting_a_fiscalized_receipt_is_still_rejected(): void
    {
        $receipt = $this->fiscalizedReceipt();

        $this->expectException(QueryException::class);
        $this->expectExceptionMessageMatches('/Cannot delete fiscally sealed receipt/');

        $receipt->delete();
    }

    public function test_deleting_a_voided_receipt_is_still_rejected(): void
    {
        $receipt = $this->voidedReceipt();

        $this->expectException(QueryException::class);
        $this->expectExceptionMessageMatches('/Cannot delete fiscally sealed receipt/');

        $receipt->delete();
    }

    public function test_inserting_a_receipt_in_any_state_is_untouched_by_the_trigger(): void
    {
        // The trigger is BEFORE UPDATE OR DELETE only — INSERT must stay
        // unguarded (the whitelist rewrite must not accidentally widen the
        // trigger's event scope).
        $id = $this->insertRawReceipt(FiscalStatus::Voided->value, 777777);

        self::assertDatabaseHas('pos_receipts', ['id' => $id]);
    }
}
