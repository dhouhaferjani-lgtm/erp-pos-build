<?php

declare(strict_types=1);

namespace Tests\Feature\Partner;

use App\Modules\Company\Domain\Company;
use App\Modules\Company\Domain\Enums\CompanyStatus;
use App\Modules\Company\Domain\UserCompanyMembership;
use App\Modules\Company\Services\CompanyContext;
use App\Modules\Identity\Domain\Enums\UserStatus;
use App\Modules\Identity\Domain\User;
use App\Modules\Partner\Domain\Enums\PartnerType;
use App\Modules\Partner\Domain\Partner;
use App\Modules\Tenant\Domain\Enums\SubscriptionPlan;
use App\Modules\Tenant\Domain\Enums\TenantStatus;
use App\Modules\Tenant\Domain\Tenant;
use App\Shared\Contracts\Partner\PartnerReferenceSource;
use App\Shared\Partner\TableBackedPartnerReferenceSource;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * Lane R2-S — the partner delete guard across EVERY table that references a
 * partner, not just the three (`documents`, `payments`, `pos_receipts`)
 * BUG-007 shipped with.
 *
 * The gap this closes: a partner is SOFT-deleted, so no foreign key ever
 * fires — RESTRICT never refuses, SET NULL never nulls, CASCADE never
 * cascades. An Otospex partner with an open work order, or one holding an
 * unredeemed voucher, deleted cleanly and left those rows pointing at a row
 * that no longer surfaces anywhere in the product.
 *
 * WHY THE FIXTURES ARE RAW INSERTS. The subject under test is a COUNT over
 * `(table, partner column)` — nothing about the guard's behaviour depends on
 * the referencing row being a valid, fully-wired domain object, and building
 * twenty-one complete object graphs (a work order needs a vehicle, a POS
 * order needs a terminal and a shift, a journal line needs an entry and an
 * account, ...) would bury the assertion under fixture noise for no added
 * signal. So each row is inserted directly with only the columns its schema
 * requires, with foreign keys deferred (see `setUp()`).
 *
 * That would be a real weakness if nothing checked the table and column
 * names — but `PartnerReferenceSchemaContractTest` asserts every declared
 * table/column/soft-delete flag against the migrated schema, and
 * `test_the_covered_table_list_matches_what_the_tagged_sources_declare`
 * below fails the moment a module adds a table this test does not exercise.
 */
class DeletePartnerReferenceGuardTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private Company $company;

    private User $user;

    private Partner $partner;

    /**
     * Every table the delete guard is expected to block on.
     *
     * Kept explicit (rather than derived from the container) so a new table
     * cannot silently enter the guard without a test that exercises it —
     * `test_the_covered_table_list_matches_what_the_tagged_sources_declare`
     * is what ties this list back to the sources.
     *
     * @return array<string, array{string}>
     */
    public static function partnerReferenceTables(): array
    {
        $tables = [
            'documents',
            'payments',
            'payment_instruments',
            'pos_receipts',
            'pos_orders',
            'pos_customer_aliases',
            'journal_lines',
            'vouchers',
            'workshop_work_orders',
            'workshop_work_order_lines',
            'scheduling_appointments',
            'withholding_certificates',
            'sales_withholding_tracking',
            'promotion_usages',
            'coupon_usages',
            'vehicles',
            'vehicle_ownership_history',
            'loyalty_members',
            'expense_recurrence_templates',
            'buyer_seller_mappings',
            'platform_supplier_mappings',
        ];

        return array_combine(
            $tables,
            array_map(static fn (string $table): array => [$table], $tables),
        );
    }

    protected function setUp(): void
    {
        parent::setUp();

        // The fixtures below insert referencing rows without building their
        // parents (see the class docblock). SQLite enforces foreign keys
        // immediately, and `Schema::withoutForeignKeyConstraints()` toggles
        // `PRAGMA foreign_keys`, which SQLite IGNORES inside a transaction —
        // and `RefreshDatabase` wraps every test in one, so that helper is a
        // silent no-op here. `PRAGMA defer_foreign_keys` IS settable
        // mid-transaction: it postpones enforcement to the outermost COMMIT,
        // which `RefreshDatabase` never performs (it rolls back), and SQLite
        // clears the flag on that rollback. Scoped to sqlite so a Postgres
        // test run is unaffected.
        if (DB::connection()->getDriverName() === 'sqlite') {
            DB::statement('PRAGMA defer_foreign_keys = ON');
        }

        $this->tenant = Tenant::create([
            'name' => 'Guard Tenant',
            'slug' => 'guard-tenant',
            'status' => TenantStatus::Active,
            'plan' => SubscriptionPlan::Professional,
        ]);

        $this->company = Company::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Guard Company',
            'legal_name' => 'Guard Company LLC',
            'tax_id' => 'TAX789',
            'country_code' => 'FR',
            'locale' => 'fr_FR',
            'timezone' => 'Europe/Paris',
            'currency' => 'EUR',
            'status' => CompanyStatus::Active,
        ]);

        app(PermissionRegistrar::class)->setPermissionsTeamId($this->tenant->id);
        $this->seed(RolesAndPermissionsSeeder::class);

        $this->user = User::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Guard Admin',
            'email' => 'guard-admin@example.com',
            'password' => 'password123',
            'status' => UserStatus::Active,
        ]);
        $this->user->assignRole('admin');

        UserCompanyMembership::create([
            'user_id' => $this->user->id,
            'company_id' => $this->company->id,
            'role' => 'admin',
        ]);

        app(CompanyContext::class)->setCompanyId($this->company->id);

        $this->partner = Partner::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'name' => 'Referenced Partner',
            'type' => PartnerType::Both,
        ]);
    }

    #[Test]
    #[DataProvider('partnerReferenceTables')]
    public function a_lingering_reference_blocks_the_delete_with_a_per_table_count(string $table): void
    {
        $this->seedReference($table, $this->partner->id);

        $this->actingAs($this->user, 'sanctum')
            ->deleteJson("/api/v1/partners/{$this->partner->id}")
            ->assertStatus(409)
            ->assertJsonPath('error.code', 'PARTNER_HAS_DOCUMENTS')
            ->assertJsonPath("error.details.{$table}", 1);

        $this->assertDatabaseHas('partners', [
            'id' => $this->partner->id,
            'deleted_at' => null,
        ]);
    }

    #[Test]
    #[DataProvider('partnerReferenceTables')]
    public function a_reference_belonging_to_another_partner_never_blocks_the_delete(string $table): void
    {
        // The other half of every guard: no false positives. A count query
        // missing its `where partner_id = ?` (or matching on the wrong
        // column) would still block here.
        $otherPartner = Partner::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'name' => 'Unrelated Partner',
            'type' => PartnerType::Both,
        ]);

        $this->seedReference($table, $otherPartner->id);

        $this->actingAs($this->user, 'sanctum')
            ->deleteJson("/api/v1/partners/{$this->partner->id}")
            ->assertNoContent();

        $this->assertSoftDeleted('partners', ['id' => $this->partner->id]);
    }

    #[Test]
    public function a_partner_with_no_references_at_all_deletes_cleanly(): void
    {
        $this->actingAs($this->user, 'sanctum')
            ->deleteJson("/api/v1/partners/{$this->partner->id}")
            ->assertNoContent();

        $this->assertSoftDeleted('partners', ['id' => $this->partner->id]);
    }

    #[Test]
    public function every_covered_table_is_reported_at_once_and_zero_count_tables_are_omitted(): void
    {
        // The 409 envelope keeps per-table counts and omits tables with no
        // references (unchanged from BUG-007). With a reference in every
        // table, `error.details` must list all of them and nothing else.
        foreach (array_keys(self::partnerReferenceTables()) as $table) {
            $this->seedReference($table, $this->partner->id);
        }

        $response = $this->actingAs($this->user, 'sanctum')
            ->deleteJson("/api/v1/partners/{$this->partner->id}")
            ->assertStatus(409)
            ->assertJsonPath('error.code', 'PARTNER_HAS_DOCUMENTS');

        /** @var array<string, int> $details */
        $details = $response->json('error.details');

        $expected = array_fill_keys(array_keys(self::partnerReferenceTables()), 1);
        ksort($expected);
        ksort($details);

        $this->assertSame($expected, $details);
    }

    #[Test]
    public function soft_deleted_rows_never_block_the_delete(): void
    {
        // A soft-deleted row is already invisible; making the partner
        // invisible too orphans nothing. Exercised on every table the
        // sources flag as soft-deleting.
        $softDeleting = [];

        foreach ($this->taggedSources() as $source) {
            foreach ($source->tables() as $table) {
                if ($table->hasSoftDeletes) {
                    $softDeleting[] = $table->table;
                }
            }
        }

        $this->assertNotSame([], $softDeleting);

        foreach ($softDeleting as $table) {
            $this->seedReference($table, $this->partner->id, softDeleted: true);
        }

        $this->actingAs($this->user, 'sanctum')
            ->deleteJson("/api/v1/partners/{$this->partner->id}")
            ->assertNoContent();

        $this->assertSoftDeleted('partners', ['id' => $this->partner->id]);
    }

    #[Test]
    public function a_row_matching_on_two_partner_columns_is_counted_once(): void
    {
        // `vouchers` (partner_id + issued_to_partner_id) and
        // `buyer_seller_mappings` (buyer + seller) OR their columns inside a
        // single count. A per-column count would report 2 for one row and
        // overstate the block.
        $this->seedReference('vouchers', $this->partner->id);
        DB::table('vouchers')->update(['issued_to_partner_id' => $this->partner->id]);

        $this->seedReference('buyer_seller_mappings', $this->partner->id);
        DB::table('buyer_seller_mappings')->update(['seller_partner_id' => $this->partner->id]);

        $this->actingAs($this->user, 'sanctum')
            ->deleteJson("/api/v1/partners/{$this->partner->id}")
            ->assertStatus(409)
            ->assertJsonPath('error.details.vouchers', 1)
            ->assertJsonPath('error.details.buyer_seller_mappings', 1);
    }

    #[Test]
    public function the_covered_table_list_matches_what_the_tagged_sources_declare(): void
    {
        // The tie between this test class and the production wiring: a
        // module that tags a new table without adding it to
        // `partnerReferenceTables()` fails here, so no table can enter the
        // guard untested.
        $declared = [];

        foreach ($this->taggedSources() as $source) {
            foreach ($source->tables() as $table) {
                $declared[] = $table->table;
            }
        }

        $covered = array_keys(self::partnerReferenceTables());

        sort($declared);
        sort($covered);

        $this->assertSame($declared, $covered);
    }

    /**
     * @return list<TableBackedPartnerReferenceSource>
     */
    private function taggedSources(): array
    {
        $sources = [];

        foreach ($this->app->tagged(PartnerReferenceSource::class) as $source) {
            $this->assertInstanceOf(TableBackedPartnerReferenceSource::class, $source);
            $sources[] = $source;
        }

        return $sources;
    }

    /**
     * Insert exactly one row in `$table` that points at `$partnerId`.
     *
     * Only the columns the schema actually requires are supplied; everything
     * else is left to its migration default. Foreign keys are switched off
     * for the insert — see the class docblock.
     */
    private function seedReference(string $table, string $partnerId, bool $softDeleted = false): void
    {
        $row = ['id' => (string) Str::uuid()] + $this->referenceRow($table, $partnerId);

        if ($softDeleted) {
            $row['deleted_at'] = now();
        }

        DB::table($table)->insert($row);
    }

    /**
     * @return array<string, mixed>
     */
    private function referenceRow(string $table, string $partnerId): array
    {
        $tenantId = $this->tenant->id;
        $companyId = $this->company->id;
        $userId = $this->user->id;
        $uuid = static fn (): string => (string) Str::uuid();

        return match ($table) {
            'documents' => [
                'tenant_id' => $tenantId,
                'company_id' => $companyId,
                'partner_id' => $partnerId,
                'type' => 'invoice',
                'document_date' => now()->toDateString(),
                'document_number' => 'INV-'.Str::random(8),
            ],
            'payments' => [
                'tenant_id' => $tenantId,
                'company_id' => $companyId,
                'partner_id' => $partnerId,
                'amount' => '10.000',
                'payment_date' => now()->toDateString(),
                'payment_method_id' => $uuid(),
            ],
            'payment_instruments' => [
                'tenant_id' => $tenantId,
                'company_id' => $companyId,
                'partner_id' => $partnerId,
                'payment_method_id' => $uuid(),
                'reference' => 'CHQ-'.Str::random(6),
                'amount' => '10.000',
                'received_date' => now()->toDateString(),
            ],
            'pos_receipts' => [
                'tenant_id' => $tenantId,
                'company_id' => $companyId,
                'partner_id' => $partnerId,
                'location_id' => $uuid(),
                'terminal_id' => $uuid(),
                'receipt_number' => 'R-'.Str::random(6),
                'receipt_year' => (int) now()->year,
                'vat_breakdown_hash' => str_repeat('a', 64),
                'payment_methods_hash' => str_repeat('b', 64),
                'posted_at' => now(),
                'cashier_id' => $userId,
                'cashier_name' => 'Guard Admin',
                'subtotal' => '10.000',
                'tax_amount' => '0.000',
                'total' => '10.000',
            ],
            'pos_orders' => [
                'tenant_id' => $tenantId,
                'company_id' => $companyId,
                'partner_id' => $partnerId,
                'terminal_id' => $uuid(),
                'shift_id' => $uuid(),
                'order_number' => 'O-'.Str::random(6),
                'cashier_id' => $userId,
                'cashier_name' => 'Guard Admin',
                'opened_at' => now(),
            ],
            'pos_customer_aliases' => [
                'tenant_id' => $tenantId,
                'company_id' => $companyId,
                'server_partner_id' => $partnerId,
                'client_customer_uuid' => $uuid(),
            ],
            'journal_lines' => [
                'journal_entry_id' => $uuid(),
                'account_id' => $uuid(),
                'partner_id' => $partnerId,
            ],
            'vouchers' => [
                'tenant_id' => $tenantId,
                'company_id' => $companyId,
                'partner_id' => $partnerId,
                'code' => 'V-'.Str::random(10),
                'initial_balance' => '10.000',
                'current_balance' => '10.000',
                'currency' => 'EUR',
                'status' => 'issued',
                'redemption_mode' => 'bearer',
                'source' => 'refund',
                'issued_at' => now(),
                'issued_by_user_id' => $userId,
            ],
            'workshop_work_orders' => [
                'tenant_id' => $tenantId,
                'company_id' => $companyId,
                'customer_partner_id' => $partnerId,
                'work_order_number' => 'WO-'.Str::random(6),
                'type' => 'repair',
                'vehicle_id' => $uuid(),
                'opened_by_user_id' => $userId,
                'currency' => 'EUR',
            ],
            'workshop_work_order_lines' => [
                'tenant_id' => $tenantId,
                'core_deposit_partner_id' => $partnerId,
                'work_order_id' => $uuid(),
                'line_type' => 'part',
                'display_name' => 'Core deposit',
                'quantity' => '1.0000',
                'unit' => 'pc',
                'unit_price' => '10.000',
                'line_total_excl_tax' => '10.000',
                'line_total_tax' => '0.000',
                'line_total_incl_tax' => '10.000',
            ],
            'scheduling_appointments' => [
                'tenant_id' => $tenantId,
                'company_id' => $companyId,
                'customer_partner_id' => $partnerId,
                'location_id' => $uuid(),
                'appointment_number' => 'APT-'.Str::random(6),
                'appointment_type' => 'service',
                'scheduled_start' => now()->addDay(),
                'scheduled_end' => now()->addDay()->addHour(),
                'estimated_duration_minutes' => 60,
            ],
            'withholding_certificates' => [
                'tenant_id' => $tenantId,
                'company_id' => $companyId,
                'partner_id' => $partnerId,
                'certificate_number' => 'WHC-'.Str::random(6),
                'year' => (int) now()->year,
                'direction' => 'sales',
                'currency' => 'EUR',
                'gross_amount' => '100.000',
                'withholding_rate' => '1.00',
                'withholding_amount' => '1.000',
                'net_amount' => '99.000',
            ],
            'sales_withholding_tracking' => [
                'tenant_id' => $tenantId,
                'company_id' => $companyId,
                'customer_id' => $partnerId,
                'document_id' => $uuid(),
                'invoice_amount' => '100.000',
                'withholding_rate' => '1.00',
                'withholding_amount' => '1.000',
                'expected_receivable' => '99.000',
            ],
            'promotion_usages' => [
                'partner_id' => $partnerId,
                'promotion_id' => $uuid(),
                'receipt_id' => $uuid(),
                'discount_amount' => '1.000',
                'used_at' => now(),
            ],
            'coupon_usages' => [
                'partner_id' => $partnerId,
                'coupon_id' => $uuid(),
                'receipt_id' => $uuid(),
                'discount_amount' => '1.000',
                'used_at' => now(),
            ],
            'vehicles' => [
                'tenant_id' => $tenantId,
                'company_id' => $companyId,
                'partner_id' => $partnerId,
                'license_plate' => strtoupper(Str::random(7)),
                'brand' => 'Peugeot',
                'model' => '208',
            ],
            'vehicle_ownership_history' => [
                'tenant_id' => $tenantId,
                'company_id' => $companyId,
                'owner_partner_id' => $partnerId,
                'vehicle_id' => $uuid(),
                'acquired_at' => now()->subMonth(),
                'reason_code' => 'purchase',
            ],
            'loyalty_members' => [
                'tenant_id' => $tenantId,
                'customer_id' => $partnerId,
                'phone' => '+33'.random_int(100000000, 999999999),
                'enrollment_date' => now(),
            ],
            'expense_recurrence_templates' => [
                'tenant_id' => $tenantId,
                'company_id' => $companyId,
                'partner_id' => $partnerId,
                'name' => 'Monthly rent',
                'amount' => '100.000',
                'frequency' => 'monthly',
                'start_date' => now()->toDateString(),
                'next_due_date' => now()->addMonth()->toDateString(),
                'created_by' => $userId,
            ],
            'buyer_seller_mappings' => [
                'buyer_partner_id' => $partnerId,
                'seller_id' => $uuid(),
                'buyer_tenant_id' => $tenantId,
                'buyer_company_id' => $companyId,
            ],
            'platform_supplier_mappings' => [
                'tenant_id' => $tenantId,
                'company_id' => $companyId,
                'partner_id' => $partnerId,
                'platform_supplier_brand' => 'ACME',
            ],
            default => throw new \LogicException(
                "No fixture for `{$table}`. A module tagged a new PartnerReferenceSource table — "
                .'add it to partnerReferenceTables() and to referenceRow().'
            ),
        };
    }
}
