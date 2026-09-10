<?php

declare(strict_types=1);

namespace Tests\Architecture;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\Architecture\Support\TenantOnlyUniqueBaseline;
use Tests\Architecture\Support\TenantOnlyUniqueIndex;
use Tests\Architecture\Support\TenantOnlyUniqueIndexScanner;
use Tests\Architecture\Support\TenantOnlyUniqueRatchetChecker;
use Tests\TestCase;

/**
 * Operator-editable catalogue rows are company-owned: a code, SKU, number, or
 * name key that is unique tenant-wide prevents company B from creating the same
 * value company A already owns. This live-schema ratchet freezes those legacy
 * keys until their migrations add company_id.
 *
 * Every table carrying a qualifying unique is classified below. Catalogue
 * tables are company-owned; excluded tables are tenant-global by nature for
 * the reviewed reason recorded beside each table.
 */
final class TenantOnlyUniqueOnCatalogueTablesRatchetTest extends TestCase
{
    use RefreshDatabase;

    public const CATALOGUE_TABLES = [
        'products',
        'product_variants',
        'partners',
        'units',
        'unit_categories',
        'payment_methods',
        'payment_repositories',
        'accounts',
        'tax_configurations',
        'categories',
        'brands',
        'product_attributes',
        'vehicles',
        'loyalty_members',
        'pos_terminals',
        'locations',
        'documents',
        'composite_item_variants',
        'location_nodes',
        'loyalty_tiers',
        'modifiers',
        'price_lists',
        'product_attribute_values',
    ];

    public const EXCLUDED_TABLES = [
        'admin_audit_logs' => 'The unique mirror id prevents duplicate security-audit projection, not duplicate catalogue data.',
        'admin_template_accounts' => 'Template child keys are scoped by their platform-owned chart template.',
        'admin_templates' => 'Bootstrap templates are platform authority shared by all tenants and companies.',
        'audit_events' => 'The unique mirror id prevents duplicate security-audit projection, not duplicate catalogue data.',
        'automotive_product_metadata' => 'This is a one-to-one projection owned by its company-scoped product.',
        'bank_reconciliation_items' => 'Reconciliation-to-payment uniqueness is transactional relationship state.',
        'bank_statement_line_allocations' => 'Statement-line allocation uniqueness is transactional relationship state.',
        'bank_statement_lines' => 'Line numbers and fingerprints deduplicate rows within a bank statement or repository.',
        'bank_statement_match_executions' => 'The action key is an idempotency key for a match execution.',
        'bank_statements' => 'Repository file hashes deduplicate imported transaction statements.',
        'banks' => 'Bank reference data is shared tenant-wide across companies.',
        'billing_invoices' => 'Subscription invoice numbers and provider ids are platform billing identities.',
        'billing_payments' => 'Provider payment ids are platform billing identities.',
        'buyer_seller_mappings' => 'The mapping key identifies one cross-company trading relationship.',
        'central_identities' => 'Central login identities are tenant-global and may reach multiple companies.',
        'certification_product' => 'This pivot is unique within its product-to-certification relationship.',
        'certification_translations' => 'Translations are unique within their platform certification and locale.',
        'certifications' => 'Certification slugs are platform reference taxonomy shared across companies.',
        'channel_orders' => 'External order ids are unique within their integration channel.',
        'channel_product_mappings' => 'Channel mappings are unique relationship projections, not catalogue identities.',
        'channel_sync_operations' => 'The unique key is an integration idempotency key.',
        'companies' => 'A company is itself the company-scope boundary and cannot carry company_id ownership.',
        'composite_item_modifier_groups' => 'This pivot is unique within its composite-item relationship.',
        'country_document_settings' => 'Country settings are platform reference data shared by companies.',
        'country_inventory_settings' => 'Country settings are platform reference data shared by companies.',
        'country_payment_settings' => 'Country settings are platform reference data shared by companies.',
        'country_pricing_regulations' => 'Country regulations are platform reference data shared by companies.',
        'country_template_assignments' => 'Country-to-template assignments are platform reference data.',
        'coupon_usages' => 'Coupon-to-receipt uniqueness is transactional redemption state.',
        'document_lines' => 'Line numbers are unique within their company-owned document.',
        'document_sequences' => 'Numbering state is scoped by its sequence dimensions rather than catalogue ownership.',
        'document_vehicle_contexts' => 'This is a one-to-one extension owned by its company-scoped document.',
        'domains' => 'Hostnames identify a tenant globally and cannot be company-owned.',
        'email_verification_tokens' => 'Verification tokens are globally unique security credentials.',
        'enrichment_results' => 'Versions are unique within an enrichment tracking operation.',
        'expense_metadata' => 'The unique keys prevent duplicate expense settlement and instrument linkage.',
        'fiscal_event_projections' => 'Projection keys enforce exactly-once handling per fiscal event and projector.',
        'fiscal_events' => 'Source-event keys deduplicate immutable fiscal facts.',
        'fiscal_periods' => 'Period numbers are unique within their fiscal year.',
        'fiscal_refund_compensations' => 'This is a one-to-one projection owned by its fiscal event.',
        'fraud_alerts' => 'The key deduplicates an alert type within one Z-report fact.',
        'goods_receipt_lines' => 'Movement links enforce one ledger movement per receipt line.',
        'grouped_write_offs' => 'The unique key is an idempotency key for one grouped write-off operation.',
        'health_claim_product' => 'This pivot is unique within its product-to-claim relationship.',
        'health_claim_translations' => 'Translations are unique within their platform health claim and locale.',
        'health_claims' => 'Health-claim slugs are platform reference taxonomy shared across companies.',
        'impersonation_grant_events' => 'Sequences and hashes identify immutable support-access audit events.',
        'impersonation_session_events' => 'Sequences and hashes identify immutable support-access audit events.',
        'impersonation_sessions' => 'A personal access token may own only one support impersonation session.',
        'income_metadata' => 'The unique key is an income idempotency key.',
        'ingredient_translations' => 'Translations are unique within their platform ingredient and locale.',
        'ingredients' => 'Ingredient slugs are platform reference taxonomy shared across companies.',
        'instrument_events' => 'The action key is an idempotency key for an instrument event.',
        'instrument_remittance_lines' => 'An instrument may occur once within a remittance.',
        'inventory_batch_stock' => 'Batch-by-location stock is a projection, not operator catalogue data.',
        'inventory_counter_metrics' => 'The key identifies one user metrics window.',
        'inventory_counting_assignments' => 'Count numbers are unique within an inventory count.',
        'inventory_counting_items' => 'Product and location keys identify projection rows within one count.',
        'journal_entries' => 'Not an operator catalogue key — but NOT clean either: unique(tenant_id, entry_number) is tenant-wide while AccountingOpeningService::generateEntryNumber() mints OB-{year}-{seq} from a COMPANY-scoped max, so company B\'s first opening batch re-mints OB-2026-000001 and dies on 23505 (same class as the documents numbering fix; ledger accounting follow-up — treasury gate r2-N1). The chain sequence itself is already per company (uniq_je_company_chain_sequence).',
        'key_component_product' => 'This pivot is unique within its product-to-component relationship.',
        'key_component_translations' => 'Translations are unique within their platform component and locale.',
        'loyalty_enrollments' => 'A member enrolls once within a loyalty program.',
        'loyalty_registry' => 'Entity types are tenant-global loyalty integration identities.',
        'loyalty_transactions' => 'Loyalty transaction uniqueness is immutable ledger state.',
        'media_assets' => 'Media blobs are tenant-wide assets whose owners are represented by attachments.',
        'media_attachments' => 'Attachment uniqueness identifies one owner-to-asset relationship, not an operator catalogue key.',
        'media_renditions' => 'Rendition uniqueness is derived from its media asset and transform.',
        'menu_category_items' => 'This pivot is unique within its menu-category relationship.',
        'onboarding_checklists' => 'Onboarding progress is tenant workflow state rather than company-owned catalogue data.',
        'opening_balance_batches' => 'The import reference is an idempotency key for one opening batch type.',
        'parapharmacy_product_metadata' => 'This is a one-to-one projection owned by its company-scoped product.',
        'partner_price_lists' => 'This pivot is unique within its partner-to-price-list relationship.',
        'party_contacts' => 'Contact links and the primary marker are scoped by their company-owned party.',
        'payment_instruments' => 'The unique key is a treasury idempotency key.',
        'permissions' => 'Permissions define the tenant-wide authorization namespace.',
        'personal_access_tokens' => 'Access-token hashes are globally unique security credentials.',
        'plans' => 'Plan codes are platform billing reference data shared by tenants.',
        'pos_account_charge_receipts' => 'This is a one-to-one fiscal-event projection.',
        'pos_account_payment_receipts' => 'This is a one-to-one fiscal-event projection.',
        'pos_cash_drawer_operations' => 'The unique key is a cash-drawer operation idempotency key.',
        'pos_customer_aliases' => 'Customer aliases map tenant-wide POS identities and are not operator catalogue rows.',
        'pos_deposit_receipts' => 'This is a one-to-one fiscal-event projection.',
        'pos_grandtotal_events' => 'Sequence numbers identify immutable events within a terminal stream.',
        'pos_receipts' => 'Idempotency, fiscal-event, and terminal-sequence keys identify immutable receipts.',
        'pos_shifts' => 'Shift numbers and the open-shift guard are scoped by a terminal.',
        'pos_z_report_counts' => 'Payment-method totals are unique within a Z report.',
        'pos_z_reports' => 'Fiscal-event, shift, and terminal sequence keys identify immutable Z reports.',
        'pos_z_session_events' => 'This is a one-to-one fiscal-event projection.',
        'price_list_items' => 'Price tiers are unique within their company-owned price list and product.',
        'product_complements' => 'This pivot is unique within its company-scoped product relationship.',
        'product_equivalents' => 'This pivot is unique within its company-scoped product relationship.',
        'product_ingredient' => 'This pivot is unique within its product-to-ingredient relationship.',
        'product_key_components' => 'Component slugs are platform reference taxonomy shared across companies.',
        'product_placements' => 'Product-by-location placement is a projection, not an independent catalogue.',
        'product_routine' => 'This pivot is unique within its product-to-routine relationship.',
        'product_skin_suitability' => 'This pivot is unique within its product-to-skin-type relationship.',
        'product_variant_attribute_values' => 'Variant attributes are unique within their company-owned variant.',
        'promotion_usages' => 'Promotion-to-receipt uniqueness is transactional redemption state.',
        'recipes' => 'An active recipe is uniquely owned by its company-scoped composite item.',
        'replenishment_capture_receipts' => 'The unique client request UUID is an idempotency key.',
        'repository_adjustments' => 'Movement and shift links prevent duplicate treasury adjustments.',
        'repository_movements' => 'Idempotency and ordinal keys identify immutable repository ledger movements.',
        'return_note_metadata' => 'This is a one-to-one extension owned by its company-scoped document.',
        'roles' => 'Roles define the tenant-wide authorization namespace.',
        'scheduling_appointment_reminders' => 'A reminder schedule is unique within its appointment and channel.',
        'stock_adjustment_lines' => 'Product keys and movement links are scoped by one stock adjustment.',
        'stock_adjustments' => 'A correction may have only one live correcting adjustment.',
        'stock_levels' => 'Stock levels are a product-by-location projection, not an operator catalogue.',
        'stock_movements' => 'Reference and reversal keys enforce idempotency in the stock ledger.',
        'stock_transfer_line_batch_allocations' => 'Batch allocation is unique within one transfer line.',
        'stock_transfer_lines' => 'Product keys are unique within one stock transfer.',
        'stock_transfer_receipts' => 'Sequence numbers are unique within one company-owned stock transfer; the number and idempotency keys already carry company_id.',
        'stock_transfer_receipt_lines' => 'Transfer-line and movement links are unique within one receipt.',
        'stock_transfer_receipt_line_lots' => 'Batch-allocation and movement links are unique within one receipt line.',
        'stored_events' => 'Aggregate versions identify immutable event-store positions.',
        'super_admins' => 'Super-admin email is a platform-global security identity.',
        'supplier_goods_return_note_lines' => 'Movement links enforce one stock movement per return line.',
        'supplier_goods_return_notes' => 'The credit-note link enforces one fiscal document per return.',
        'tenant_signing_keys' => 'Signing-key identity is tenant-global security material.',
        'tenant_subscriptions' => 'Provider subscription ids are platform billing identities.',
        'tenants' => 'Tenant slugs are platform-global account identities.',
        'users' => 'User identities are tenant-global and may belong to multiple companies.',
        'vat_period_breakdowns' => 'Tax-rate breakdowns are unique within their VAT period and direction.',
        'vehicle_ownership_history' => 'The partial key enforces one current owner per vehicle.',
        'voucher_ledger' => 'The partial key enforces one immutable void event per voucher.',
        'vouchers' => 'Voucher codes identify issued financial instruments, not reusable catalogue rows.',
        'withholding_tax_rules' => 'Country withholding rules are platform reference data shared by companies.',
        'workshop_technician_time_entries' => 'The partial key enforces one open time entry per technician.',
        'workshop_work_order_assignments' => 'The partial key enforces one active lead per work order.',
    ];

    private const BASELINE_RELATIVE = 'tests/Architecture/baselines/tenant-only-unique-baseline.json';

    /** Coverage residual: the census fixture exercises the Tunisia chart template only (FR/generic seeders unguarded in CI). */
    private const LEGACY_ENTRY_CEILING = 11;

    /**
     * Waived entries are pinned too: a contributor cannot silence growth in-diff by adding a
     * `waiver` string — any new waiver also needs this constant raised, which the reviewer sees.
     */
    private const WAIVED_ENTRY_CEILING = 9;

    /**
     * The company-owned tables the rule is about (convention 09) — pinned so nobody trims the set to
     * make a violation disappear. Tables may be ADDED to CATALOGUE_TABLES; these may never leave it.
     */
    private const PINNED_CATALOGUE_TABLES = ['products', 'product_variants', 'partners', 'units', 'unit_categories', 'payment_methods', 'payment_repositories', 'accounts', 'tax_configurations', 'categories', 'brands', 'product_attributes', 'locations', 'pos_terminals', 'documents'];

    protected function setUp(): void
    {
        parent::setUp();

        if (DB::getDriverName() !== 'pgsql') {
            $this->markTestSkipped('PG-only ratchet — gated by the backend-test-pgsql lane');
        }
    }

    #[Test]
    public function live_tenant_only_unique_indexes_match_the_reviewed_baseline(): void
    {
        $liveIndexes = (new TenantOnlyUniqueIndexScanner)->scan(
            DB::connection(),
            self::CATALOGUE_TABLES,
        );
        $baseline = TenantOnlyUniqueBaseline::fromFile(base_path(self::BASELINE_RELATIVE));
        $report = (new TenantOnlyUniqueRatchetChecker)->check($liveIndexes, $baseline);

        self::assertSame([], $report->violations(), $report->message());

        $legacyEntries = array_filter(
            $baseline,
            static fn ($entry): bool => $entry->waiver === null,
        );
        self::assertLessThanOrEqual(
            self::LEGACY_ENTRY_CEILING,
            count($legacyEntries),
            'Un-waived legacy baseline growth requires a reviewed raise of LEGACY_ENTRY_CEILING.',
        );
        $waivedEntries = array_filter(
            $baseline,
            static fn ($entry): bool => $entry->waiver !== null,
        );
        self::assertLessThanOrEqual(
            self::WAIVED_ENTRY_CEILING,
            count($waivedEntries),
            'A new waiver requires a reviewed raise of WAIVED_ENTRY_CEILING (waivers are for keys tenant-global by nature only).',
        );
    }

    #[Test]
    public function the_core_catalogue_tables_stay_in_scope(): void
    {
        self::assertSame(
            [],
            array_values(array_diff(self::PINNED_CATALOGUE_TABLES, self::CATALOGUE_TABLES)),
            'A pinned catalogue table was removed from CATALOGUE_TABLES — the rule is about these tables.',
        );
    }

    #[Test]
    public function every_table_with_a_qualifying_unique_is_explicitly_classified(): void
    {
        self::assertSame(
            [],
            array_values(array_intersect(self::CATALOGUE_TABLES, array_keys(self::EXCLUDED_TABLES))),
            'A table cannot be both company-owned catalogue data and excluded.',
        );
        foreach (self::EXCLUDED_TABLES as $table => $reason) {
            self::assertNotSame('', trim($reason), "Excluded table {$table} needs a reviewed reason.");
        }

        $violations = self::unclassifiedTableViolations(
            (new TenantOnlyUniqueIndexScanner)->scanAll(DB::connection()),
        );

        self::assertSame([], $violations, "\n".implode("\n", $violations));
    }

    /**
     * @param  list<TenantOnlyUniqueIndex>  $indexes
     * @return list<string>
     */
    public static function unclassifiedTableViolations(array $indexes): array
    {
        $classified = array_fill_keys(self::CATALOGUE_TABLES, true) + self::EXCLUDED_TABLES;
        $unclassified = [];

        foreach ($indexes as $index) {
            if (! isset($classified[$index->tableName])) {
                $unclassified[$index->tableName] = sprintf(
                    'classify table %s: catalogue (company-owned) or excluded (tenant-global, say why)',
                    $index->tableName,
                );
            }
        }

        ksort($unclassified);

        return array_values($unclassified);
    }
}
