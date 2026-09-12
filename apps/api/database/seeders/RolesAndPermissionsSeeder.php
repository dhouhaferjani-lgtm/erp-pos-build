<?php

declare(strict_types=1);

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

class RolesAndPermissionsSeeder extends Seeder
{
    public function __construct(private readonly PermissionRegistrar $permissionRegistrar) {}

    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        // Reset cached roles and permissions
        $this->permissionRegistrar->forgetCachedPermissions();

        // Create permissions per module
        $this->createPermissions();

        // Create roles and assign permissions
        $this->createRoles();
    }

    /**
     * Create all permissions organized by module.
     */
    private function createPermissions(): void
    {
        $permissions = self::permissionNames();

        foreach ($permissions as $permission) {
            Permission::firstOrCreate(['name' => $permission, 'guard_name' => 'sanctum']);
        }

        $this->command->info('Created '.count($permissions).' permissions');
    }

    /**
     * @return list<string>
     */
    public static function permissionNames(): array
    {
        return [
            // Partner/Customer Management
            'partners.view',
            'partners.create',
            'partners.update',
            'partners.delete',

            // Product/Catalog Management
            'products.view',
            'products.create',
            'products.update',
            'products.delete',
            'products.import',
            'enrichment.view',
            'enrichment.review',
            'enrichment.submit',

            // Composite Items / Catalog
            'composite-items.view',
            'composite-items.create',
            'composite-items.update',
            'composite-items.delete',
            'composite-items.manage-recipes',
            'modifier-groups.view',
            'modifier-groups.manage',

            // Product variants & attributes (T2)
            'catalog.attributes.view',
            'catalog.attributes.create',
            'catalog.attributes.update',
            'catalog.attributes.delete',
            'catalog.variants.view',
            'catalog.variants.create',
            'catalog.variants.update',
            'catalog.variants.delete',
            'catalog.labels.print',

            // Pricing (price lists, pricing rules, partner pricing, margin check)
            'pricing.view',
            'pricing.manage',
            'pricing.view_cost_prices',
            'pricing.sell_below_target_margin',
            'pricing.sell_below_minimum_margin',
            'pricing.sell_below_cost',

            // Workshop Service Bundles (automotive menu pricing)
            'workshop-bundles.view',
            'workshop-bundles.manage',

            // Menu Management
            'menus.view',
            'menus.manage',

            // Promotions
            'promotions.view',
            'promotions.manage',

            // Coupons
            'coupons.view',
            'coupons.manage',

            // Vehicle Management
            'vehicles.view',
            'vehicles.create',
            'vehicles.update',
            'vehicles.delete',
            'vehicles.manage_ownership',
            'vehicles.log_mileage',

            // Sales Documents (Quotes, Orders, Invoices)
            'documents.view',  // Unified document view
            'documents.update',  // Media attachments + the coarse gate on /documents/auto-save (which also demands the per-type *.create)
            // R2-F4 (owner ruling c4) — create and post a CORRECTING ENTRY
            // against a posted document. Deliberately its own admin-tier
            // permission and NOT granted to any non-admin role below: a
            // correcting entry writes arbitrary general-ledger legs, which is
            // strictly more powerful than cancelling a document, and it also
            // EXPOSES chart-of-accounts detail on read.
            'documents.correct',
            'quotes.view',
            'quotes.create',
            'quotes.update',
            'quotes.delete',
            'quotes.convert',

            'orders.view',
            'orders.create',
            'orders.update',
            'orders.delete',
            'orders.confirm',

            // Purchase Orders
            'purchase-orders.view',
            'purchase-orders.create',
            'purchase-orders.update',
            'purchase-orders.delete',
            'purchase-orders.confirm',
            'purchase-orders.receive',
            'goods-receipt.edit-price',
            'goods-receipt.receive-expired',
            'goods-receipt.create-standalone',
            'supplier-invoices.create-pending',
            'supplier-invoices.link-receipts',
            'supplier-invoices.approve-invoice-first',
            // F-W2-14 / DEV-QA-027-028: dedicated coarse gate for the supplier-invoice
            // mutation surface (create / re-match / post). Replaces the generic
            // documents.update the routes previously used — a cashier holds
            // documents.update but must NOT be able to create or post supplier invoices.
            'supplier-invoices.manage',
            'document-ingestions.view',
            'document-ingestions.create',
            'document-ingestions.commit',
            'document-ingestions.reject',

            // Purchase Quote Requests / RFQ
            'purchase-quote-requests.view',
            'purchase-quote-requests.create',
            'purchase-quote-requests.update',
            'purchase-quote-requests.convert',
            'purchase-quote-requests.delete',

            'invoices.view',
            'invoices.create',
            'invoices.update',
            'invoices.delete',
            'invoices.post',
            'invoices.cancel',
            'invoices.print',

            'credit-notes.view',
            'credit-notes.create',
            'credit-notes.post',

            // Inventory Management
            'inventory.view',
            'inventory.adjust',
            'inventory.transfer',
            'inventory.receive',

            // Units of Measure (read by product/document/stock editors)
            'uom.view',
            'uom.create',
            'uom.edit',
            'uom.delete',
            'units.manage',

            // Stock Transfer (document-based, lifecycle-tracked)
            'inventory.transfers.view',
            'inventory.transfers.create',
            'inventory.transfers.complete',
            'inventory.transfers.cancel',
            'inventory.transfers.reconcile',
            'inventory.transfers.close',

            // Stock Adjustment (document-based manual correction, DPA V7 / D9).
            // The SAP step split: authoring and posting are separately
            // authorized, so `post_immediately: true` is a two-leg check.
            'inventory.adjustments.view',
            'inventory.adjustments.create',
            'inventory.adjustments.post',
            'inventory.adjustments.cancel',

            'deliveries.view',
            'deliveries.create',
            'deliveries.edit',
            'deliveries.delete',
            'deliveries.confirm',

            // Expenses
            'expenses.view',
            'expenses.create',
            'expenses.update',
            'expenses.delete',
            'expenses.post',
            'expenses.pay',

            'expense-categories.view',
            'expense-categories.create',
            'expense-categories.update',
            'expense-categories.delete',

            'expense-recurrences.view',
            'expense-recurrences.create',
            'expense-recurrences.update',
            'expense-recurrences.delete',
            'expenses.export',

            // Income
            'income.view',
            'income.create',
            'income.update',
            'income.delete',
            'income.post',

            // Treasury/Payments
            'payments.view',
            'payments.create',
            // F-W2-14 residual (a) — the AP half of `POST /payments`.
            //
            // `payments.create` is held by `cashier` (and `operator`) because a
            // till operator must be able to take a CUSTOMER payment. The very
            // same endpoint settles a SUPPLIER invoice, which moves cash OUT of
            // a repository and debits 401 — a different act, measured as a live
            // hole on the browser run (`W2-PERM-6..11`, cashier paid a supplier
            // 201 with a cash movement out).
            //
            // Nothing in the existing catalogue expresses "may send money to a
            // supplier": `payments.allocate` gates the invoice-side auto-alloc
            // route (Document routes.php `documents.allocate-payment`) and
            // `expenses.pay` is the expense flow. Hence a dedicated, coarse
            // gate, enforced at the request-authorization layer on the AP branch
            // ONLY, so customer-side payments by a cashier are untouched.
            'payments.pay-supplier',
            'payments.allocate',
            'payments.void',
            'payments.refund',
            // payments.reverse: deliberately admin-only (owner decision, ratified 2026-07-10).
            // The reverse endpoint currently moves no cash and posts no GL — it's a
            // bookkeeping-level undo — so it stays restricted to admin until it gets
            // spine treatment (see payments.refund grants below for the money-moving flow).
            'payments.reverse',

            'instruments.view',
            'instruments.create',
            'instruments.update',
            'instruments.transfer',
            'instruments.clear',
            'instruments.bounce',
            'instruments.remit',
            'instruments.cancel',
            'instruments.clear-outbound',
            'instruments.cancel-outbound',

            'repositories.view',
            'repositories.manage',

            'treasury.view',
            'treasury.manage',
            'treasury.adjust',
            'treasury.transfer',

            'bank-statements.view',
            'bank-statements.import',
            'bank-statements.reconcile',
            'bank-statements.reopen',

            // Accounting
            'journal.view',
            'journal.create',
            'journal.post',

            'accounts.view',
            'accounts.manage',

            'ledger.view',  // General ledger access

            // Fiscal-period lifecycle (Session B lane Q-10). The nightly
            // auto-lock drives fiscal periods Open -> Closed -> Locked; this
            // gates the ONLY in-product edge back (Closed -> Open). Naming
            // mirrors the sibling `bank-statements.reopen` above. Held by
            // `admin` (via Permission::all()) and `accountant` only — the role
            // set of `reports.manage`, the VAT-period generate/close/reopen/file
            // family, which the 2026-08-06 gate finding I-1 ruling deliberately
            // removed from `manager` because reversing a period close is
            // financial-lifecycle mutation, not day-to-day operations.
            // Locked periods stay terminal: the service refuses them.
            'fiscal-periods.reopen',

            // The MANUAL close (Session B2 lane C-24 (i)), the other half of the
            // pair: Q-10's reopen left a corrected period — and its fiscal year —
            // Open forever, because the nightly auto-lock now steps around it.
            // Same role set as the reopen (accountant + admin, manager excluded),
            // but its OWN permission: settling a period and reversing a settlement
            // are different acts, and a tenant-custom role must be able to grant
            // the first without the second.
            'fiscal-periods.close',

            // DEPRECATED (W-6 D5, 2026-08-05 owner ruling "Option B split") —
            // reports.view no longer gates any route as of this release; the
            // reports/* endpoints now check reports.financial or
            // reports.operational per report. Kept seeded (admin retains it
            // via Permission::all()) for one release so any tenant-custom
            // role referencing it does not break; slated for removal next
            // release.
            'reports.view',
            'reports.financial',
            'reports.operational',
            'reports.manage',  // VAT period management
            'dashboard.owner',

            // Workshop — Work Orders (Spec B)
            'work-orders.view',
            'work-orders.create',
            'work-orders.update',
            'work-orders.approve',
            'work-orders.assign',
            'work-orders.transition',
            'work-orders.cancel',
            'work-orders.complete',
            'work-orders.view_financials',

            // Workshop — Technicians (Spec C)
            'workshop.technicians.view',
            'workshop.technicians.manage',
            'workshop.technicians.view_pay',
            'workshop.technicians.view_pii',
            'workshop.technicians.approve_time_off',
            'workshop.technicians.adjust_time_entries',
            // Authoring permissions added by Phase A.4 (technician authoring UI).
            'workshop.technicians.manage_certifications',
            'workshop.technicians.manage_time_off',
            'workshop.technicians.manage_time_entries',

            // Workshop — Payroll export (Phase A.4)
            'workshop.payroll.view',
            'workshop.payroll.generate',

            // Scheduling — Bays + Appointments (Spec D / Plan D)
            'scheduling.bays.view',
            'scheduling.bays.manage',
            'scheduling.appointments.view',
            'scheduling.appointments.create',
            'scheduling.appointments.update',
            'scheduling.appointments.cancel',
            'scheduling.appointments.convert',

            // User & Tenant Management
            'users.view',
            'users.create',
            'users.update',
            'users.delete',
            'users.assign-roles',
            'users.manage_location_access',

            'roles.view',
            'roles.manage',

            // POS
            'pos.manage_terminals',
            'pos.operate_terminal',
            'pos.audit_sync',
            'pos.manage_shifts',
            'pos.manage_tables',
            'pos.view_reports',
            'pos.generate_z_report',
            'pos.void_receipts',
            'pos.view_receipts',
            'pos.process_returns',
            'pos.close_shift_with_variance',
            'pos.approve_credit_limit_override',
            'pos.approve_account_status_override',
            'pos.approve_discount_limit_override',
            'pos.approve_tender_tolerance_override',
            'pos.approve_void_or_return_override',
            'pos.approve_cash_drawer_control',
            'pos.configure_cash_count',
            'pos.tolerance.apply',
            'pos.view_cross_location_stock',

            // Replenishment requests
            'replenishment.view',
            'replenishment.create',
            'replenishment.process',

            // POS Orders
            'pos_orders.view',
            'pos_orders.create',
            'pos_orders.update',
            'pos_orders.delete',

            // POS Held Orders
            'pos_held_orders.view',
            'pos_held_orders.create',
            'pos_held_orders.delete',

            // Batch/Expiry Management
            'batches.view',
            'batches.create',
            'batches.update',
            'batches.delete',
            'batches.recall',
            'batches.write-off',
            'batches.traceability',

            // Withholding Certificates
            'withholding.view',
            'withholding.create',
            'withholding.update',
            'withholding.delete',

            // Withholding Tax Rules (Admin) — gates Taxation/routes.php:38-45
            // (index/show/store/update/deactivate/destroy). Same class of
            // defect as taxation.tax_configurations.manage below: was
            // defined only in the dead central-bootstrap PermissionSeeder
            // (never called by DatabaseSeeder), never in this per-tenant
            // seeder, so it was never created on any real tenant DB — no
            // user, not even admin, could ever satisfy
            // CreateWithholdingRuleRequest/UpdateWithholdingRuleRequest's
            // authorize() check, and `deactivate`/`destroy` (which had NO
            // authorization check at all) were reachable by any
            // authenticated tenant user.
            // docs/superpowers/tickets/2026-08-03-w5a-withholding-defects.md #3
            'taxation.withholding_rules.manage',

            // Taxation - Tax Configurations (W-X, 2026-08-06): create/edit/
            // delete/reorder tax rates, types and stamp duties. Was defined
            // in the central-bootstrap PermissionSeeder but never in this
            // per-tenant seeder, so it was never created on any real tenant
            // DB — taxation.tax_configurations.manage was dead for every
            // role including admin. Matches the route guard string exactly:
            // Taxation/routes.php:23-31 (store/update/destroy/reorder).
            // docs/superpowers/tickets/2026-08-05-wx-tax-config-unmanageable.md
            'taxation.tax_configurations.manage',

            // Loyalty
            'loyalty.view',
            'loyalty.manage',
            'loyalty.enroll', // narrow enrollment right — cashiers enroll by default, owner 2026-07-05

            // CRM / Contacts
            'contacts.view',
            'contacts.create',
            'contacts.update',
            'contacts.delete',

            // Marketplace
            'marketplace.browse',
            'marketplace.order',
            'marketplace.view_orders',
            'marketplace.admin',

            // Catalog Cart
            'catalog_cart.view',
            'catalog_cart.create',
            'catalog_cart.convert_po',
            'catalog_cart.convert_so',
            'catalog_cart.marketplace_checkout',
            'catalog_cart.manage_all',

            // Compliance / NF525
            'compliance.export_jet',
            'compliance.verify_chains',
            'compliance.view_reprint_log',

            // Fiscal Event Engine (Phase 1) — operator-only privileged ops on
            // the immutable fiscal-event ledger.
            //
            // - `fiscal.events.resolve_quarantine` — gates
            //   `fiscal:enqueue-resolved-event-projections` (spec §15.2) and
            //   the in-app parse-failure resolution action (Task 24). The
            //   atomic UPDATE writes the corrected payload + flips
            //   payload_parse_status `failed → parsed` + flips
            //   integrity_status `quarantined → verified`, which the Task 8
            //   immutability trigger gates strictly.
            //
            // - `fiscal.events.verify_chain` — gates the
            //   `fiscal:verify-event-chain` command (spec §15.1) added in
            //   Task 31; pre-registered here so Task 24's seeder edit
            //   doesn't need a follow-up bump.
            //   ROUND-2 NOTE (Task 24 Opus F4 P2): deliberate scope
            //   expansion — KEPT per round-2 disposition. CLAUDE.md
            //   rule 4 ("One Task at a Time — No Scope Creep") favors
            //   atomic edits, but the cost of adding the permission
            //   twice (here in Task 24 + again in Task 31 with a
            //   permission-name finalization risk) is worse than the
            //   cost of pre-registering once. The name
            //   `fiscal.events.verify_chain` is locked by spec §15.1
            //   and consumed by Task 31 only.
            'fiscal.events.resolve_quarantine',
            'fiscal.events.verify_chain',
            // v3-refund-chain-integration spec §5.2/§17 — the refund
            // write-off compensation endpoint + dead-lettered-projections
            // read surface. Mirrors 'pos.process_returns' (:327) — who
            // administers POS corrections also administers refund
            // dead-letter compensation.
            'fiscal.refunds.manage_dead_letters',

            // Compliance / Fraud Detection
            'fraud-settings.view',
            'fraud-settings.update',
            'fraud-alerts.view',
            'fraud-alerts.manage',

            // POS — Refund flow (spec §3.8)
            'pos.search_customer_recent_purchases',
            'pos.search_customer_full_history',
            'pos.search_customer_cross_company',
            'pos.refund_above_threshold',
            'pos.refund_no_receipt',
            'pos.refund_extend_daily_cap',
            'pos.issue_goodwill_voucher',
            'pos.issue_goodwill_voucher_high_value',
            'pos.void_voucher',
            'pos.extend_voucher_expiry',
            'pos.transfer_voucher',
            'pos.redeem_voucher',
            'pos.refund_destination_override',
            'pos.refund_voucher_to_cash',
            'pos.fiscal_schema_cutover',
            'pos.rotate_qr_signing_key',

            // System
            'settings.view',
            'settings.update',
            'settings.fiscal.update',
            'settings.manage',
            'audit.view',
            'imports.manage',
            'support-access.view',
            'support-access.manage',
        ];
    }

    /**
     * Create roles and assign permissions.
     */
    private function createRoles(): void
    {
        foreach (self::rolePermissionGrants() as $roleName => $permissions) {
            $role = Role::firstOrCreate(['name' => $roleName, 'guard_name' => 'sanctum']);
            $role->syncPermissions($roleName === 'admin' ? Permission::all() : $permissions);
            $this->command->info(
                $roleName === 'admin' ? 'Created role: admin (all permissions)' : 'Created role: '.$roleName,
            );
        }
    }

    /**
     * @return array<string, list<string>>
     */
    public static function rolePermissionGrants(): array
    {
        return [
            // Admin - Full access (includes all POS permissions)
            'admin' => self::permissionNames(),

            // Manager - Operations management
            'manager' => [
                'partners.view', 'partners.create', 'partners.update',
                'products.view', 'products.create', 'products.update', 'products.import',
                'enrichment.view', 'enrichment.review', 'enrichment.submit',
                'vehicles.view', 'vehicles.create', 'vehicles.update',
                'vehicles.manage_ownership', 'vehicles.log_mileage',
                'documents.view', 'documents.update',
                'quotes.view', 'quotes.create', 'quotes.update', 'quotes.convert',
                'orders.view', 'orders.create', 'orders.update', 'orders.confirm',
                'purchase-orders.view', 'purchase-orders.create', 'purchase-orders.update', 'purchase-orders.confirm', 'purchase-orders.receive', 'goods-receipt.edit-price', 'goods-receipt.receive-expired', 'goods-receipt.create-standalone',
                'supplier-invoices.create-pending', 'supplier-invoices.link-receipts', 'supplier-invoices.approve-invoice-first', 'supplier-invoices.manage',
                'document-ingestions.view', 'document-ingestions.create', 'document-ingestions.commit', 'document-ingestions.reject',
                'purchase-quote-requests.view', 'purchase-quote-requests.create', 'purchase-quote-requests.update', 'purchase-quote-requests.convert', 'purchase-quote-requests.delete',
                'invoices.view', 'invoices.create', 'invoices.update', 'invoices.post', 'invoices.print',
                'credit-notes.view', 'credit-notes.create', 'credit-notes.post',
                'inventory.view', 'inventory.adjust', 'inventory.transfer', 'inventory.receive',
                'uom.view', 'uom.create', 'uom.edit', 'uom.delete', 'units.manage',
                'inventory.transfers.view', 'inventory.transfers.create', 'inventory.transfers.complete', 'inventory.transfers.cancel', 'inventory.transfers.reconcile', 'inventory.transfers.close',
                'inventory.adjustments.view', 'inventory.adjustments.create', 'inventory.adjustments.post', 'inventory.adjustments.cancel',
                'deliveries.view', 'deliveries.create', 'deliveries.edit', 'deliveries.delete', 'deliveries.confirm',
                'expenses.view', 'expenses.create', 'expenses.update', 'expenses.post', 'expenses.pay',
                'expense-categories.view', 'expense-categories.create', 'expense-categories.update', 'expense-categories.delete',
                'expense-recurrences.view', 'expense-recurrences.create', 'expense-recurrences.update', 'expense-recurrences.delete', 'expenses.export',
                'income.view', 'income.create', 'income.update', 'income.post',
                'payments.view', 'payments.create', 'payments.pay-supplier', 'payments.allocate', 'payments.refund',
                'instruments.view', 'instruments.create', 'instruments.update', 'instruments.transfer', 'instruments.clear', 'instruments.bounce', 'instruments.remit', 'instruments.cancel',
                'repositories.view',
                'treasury.view', 'treasury.adjust', 'treasury.transfer',
                'journal.view',
                'accounts.view', 'accounts.manage',
                // W-6 D5 owner ruling (2026-08-05, "Option B split"): manager
                // gets reports.operational ONLY — no reports.financial
                // (P&L/trial balance/balance sheet) and no ledger.view.
                // Gate finding I-1 (2026-08-06 review, orchestrator ruling):
                // reports.manage (VAT period generate/close/reopen/file) is
                // ALSO dropped here — it is financial-lifecycle mutation, and
                // keeping it while removing reports.financial let a manager
                // file a VAT declaration to the tax authority that it could
                // not read back (POST .../file was 200-reachable, GET
                // /vat/periods was 403). Accountant keeps reports.manage.
                'reports.operational', 'dashboard.owner',
                'work-orders.view', 'work-orders.create', 'work-orders.update',
                'work-orders.approve', 'work-orders.assign', 'work-orders.transition',
                'work-orders.cancel', 'work-orders.complete', 'work-orders.view_financials',
                'workshop.technicians.view', 'workshop.technicians.manage',
                'workshop.technicians.view_pay', 'workshop.technicians.view_pii',
                'workshop.technicians.approve_time_off', 'workshop.technicians.adjust_time_entries',
                'workshop.technicians.manage_certifications',
                'workshop.technicians.manage_time_off',
                'workshop.technicians.manage_time_entries',
                'workshop.payroll.view', 'workshop.payroll.generate',
                'scheduling.bays.view', 'scheduling.bays.manage',
                'scheduling.appointments.view', 'scheduling.appointments.create',
                'scheduling.appointments.update', 'scheduling.appointments.cancel',
                'scheduling.appointments.convert',
                'users.view',
                'pos.manage_terminals', 'pos.operate_terminal', 'pos.audit_sync', 'pos.manage_shifts', 'pos.manage_tables',
                'pos.view_reports', 'pos.generate_z_report', 'pos.void_receipts', 'pos.view_receipts', 'pos.process_returns',
                'fiscal.refunds.manage_dead_letters',
                'pos.close_shift_with_variance',
                'pos.approve_credit_limit_override', 'pos.approve_account_status_override',
                'pos.approve_discount_limit_override', 'pos.approve_tender_tolerance_override',
                'pos.approve_void_or_return_override', 'pos.approve_cash_drawer_control',
                'pos.configure_cash_count', 'pos.tolerance.apply', 'pos.view_cross_location_stock',
                'replenishment.view', 'replenishment.create', 'replenishment.process',
                'pos_orders.view', 'pos_orders.create', 'pos_orders.update', 'pos_orders.delete',
                'pos_held_orders.view', 'pos_held_orders.create', 'pos_held_orders.delete',
                'batches.view', 'batches.create', 'batches.update', 'batches.delete',
                'batches.recall', 'batches.write-off', 'batches.traceability',
                'withholding.view',
                'settings.view', 'settings.manage',
                'composite-items.view', 'composite-items.create', 'composite-items.update', 'composite-items.delete', 'composite-items.manage-recipes',
                'modifier-groups.view', 'modifier-groups.manage',
                'catalog.attributes.view', 'catalog.attributes.create', 'catalog.attributes.update', 'catalog.attributes.delete',
                'catalog.variants.view', 'catalog.variants.create', 'catalog.variants.update', 'catalog.variants.delete',
                'catalog.labels.print',
                'workshop-bundles.view', 'workshop-bundles.manage',
                'pricing.view', 'pricing.manage',
                'pricing.view_cost_prices', 'pricing.sell_below_target_margin', 'pricing.sell_below_minimum_margin', 'pricing.sell_below_cost',
                'menus.view', 'menus.manage',
                'promotions.view', 'promotions.manage',
                'coupons.view', 'coupons.manage',
                'loyalty.view', 'loyalty.manage', 'loyalty.enroll',
                'contacts.view', 'contacts.create', 'contacts.update', 'contacts.delete',
                'marketplace.browse', 'marketplace.order', 'marketplace.view_orders',
                'catalog_cart.view', 'catalog_cart.create', 'catalog_cart.convert_po', 'catalog_cart.convert_so',
                'catalog_cart.marketplace_checkout',
                'compliance.export_jet', 'compliance.verify_chains', 'compliance.view_reprint_log',
                'fraud-settings.view', 'fraud-settings.update',
                'fraud-alerts.view', 'fraud-alerts.manage',
                // POS — Refund flow (spec §3.8)
                'pos.search_customer_recent_purchases',
                'pos.search_customer_full_history',
                'pos.refund_above_threshold',
                'pos.refund_no_receipt',
                'pos.refund_extend_daily_cap',
                'pos.issue_goodwill_voucher',
                'pos.void_voucher',
                'pos.extend_voucher_expiry',
                'pos.redeem_voucher',
                'pos.refund_destination_override',
                'pos.refund_voucher_to_cash',
            ],

            // Cashier - Point of sale operations
            'cashier' => [
                'partners.view', 'partners.create',
                'products.view',
                'vehicles.view',
                'documents.view', 'documents.update',
                'quotes.view', 'quotes.create',
                'orders.view',
                'invoices.view', 'invoices.create', 'invoices.print',
                'expenses.view', 'expenses.create',
                'expense-categories.view',
                'expense-recurrences.view',
                'income.view', 'income.create',
                'inventory.view',
                'uom.view',
                'deliveries.view',
                'payments.view', 'payments.create',
                'instruments.view', 'instruments.create',
                'work-orders.view',
                'pos.operate_terminal', 'pos.audit_sync', 'pos.manage_shifts', 'pos.generate_z_report', 'pos.view_receipts',
                'pos.tolerance.apply',
                'loyalty.enroll',
                'pos_orders.view', 'pos_orders.create', 'pos_orders.update',
                'pos_held_orders.view', 'pos_held_orders.create', 'pos_held_orders.delete',
                'batches.view',
                'composite-items.view',
                'modifier-groups.view',
                'menus.view',
                'contacts.view', 'contacts.create',
                'marketplace.browse',
                'catalog_cart.view',
                // POS — Refund flow (spec §3.8)
                'pos.search_customer_recent_purchases',
                'pos.redeem_voucher',
            ],

            // Viewer - Read-only access
            'viewer' => [
                'partners.view',
                'products.view',
                'vehicles.view',
                'documents.view',
                'quotes.view',
                'orders.view',
                'purchase-orders.view',
                'purchase-quote-requests.view',
                'invoices.view',
                'credit-notes.view',
                'expenses.view',
                'expense-categories.view',
                'expense-recurrences.view',
                'income.view',
                'inventory.view',
                'uom.view',
                'deliveries.view',
                'payments.view',
                'instruments.view',
                'repositories.view',
                'journal.view',
                'accounts.view',
                // W-6 D5 owner ruling (2026-08-05, "Option B split"): viewer
                // gets NO finance-report permissions at all (neither
                // reports.financial nor reports.operational, nor ledger.view).
                'work-orders.view',
                'settings.view',
                'composite-items.view',
                'modifier-groups.view',
                'menus.view',
                'promotions.view',
                'coupons.view',
                'loyalty.view',
                'pricing.view',
                'marketplace.browse',
                'catalog_cart.view',
            ],

            // Technician - Workshop operations
            'technician' => [
                'partners.view',
                'products.view',
                'vehicles.view',
                'vehicles.log_mileage',
                'inventory.view',
                'uom.view',
                'work-orders.view', 'work-orders.update', 'work-orders.complete',
                // Technicians see their own profile (list + show), but NOT pay nor PII —
                // those are admin/manager scope; PII masking is enforced at DTO layer.
                'workshop.technicians.view',
                // Technicians may self-log time entries. The controller enforces
                // "own entries only" for non-admin callers. They cannot edit/delete
                // past entries once they're linked to a locked work-order.
                'workshop.technicians.manage_time_entries',
                'workshop-bundles.view',
                // Technicians can view bays + their own upcoming appointments
                // (list / show only — no create / update / cancel).
                'scheduling.bays.view',
                'scheduling.appointments.view',
                // Technicians cannot approve/cancel WOs nor see financials (those are
                // manager/accountant scope); DTO-level redaction enforces the latter.
            ],

            // Operator - Standard operations staff
            'operator' => [
                'partners.view', 'partners.create', 'partners.update',
                'products.view',
                'vehicles.view', 'vehicles.create', 'vehicles.update',
                'vehicles.manage_ownership', 'vehicles.log_mileage',
                'documents.view', 'documents.update',
                'quotes.view', 'quotes.create', 'quotes.update',
                'orders.view', 'orders.create', 'orders.update',
                'purchase-orders.view', 'purchase-orders.create', 'purchase-orders.update',
                'purchase-quote-requests.view',
                'invoices.view', 'invoices.create', 'invoices.print',
                'expenses.view', 'expenses.create', 'expenses.update',
                'expense-categories.view',
                'expense-recurrences.view',
                'income.view', 'income.create', 'income.update',
                'inventory.view',
                'uom.view',
                'deliveries.view', 'deliveries.create', 'deliveries.edit',
                'payments.view', 'payments.create',
                'work-orders.view', 'work-orders.create', 'work-orders.update',
                'work-orders.transition', 'work-orders.complete',
                'workshop.technicians.view',
                'workshop-bundles.view', 'workshop-bundles.manage',
                // Scheduling: operators run the bay/calendar + appointment flow
                // except manage-bay + convert (reserved for manager/admin).
                'scheduling.bays.view',
                'scheduling.appointments.view', 'scheduling.appointments.create',
                'scheduling.appointments.update', 'scheduling.appointments.cancel',
                'marketplace.browse',
                'catalog_cart.view', 'catalog_cart.create',
                'replenishment.view', 'replenishment.create',
            ],

            // Accountant - Financial operations
            'accountant' => [
                'partners.view',
                'documents.view', 'documents.update',
                'invoices.view', 'invoices.post',
                'supplier-invoices.create-pending', 'supplier-invoices.link-receipts', 'supplier-invoices.approve-invoice-first', 'supplier-invoices.manage',
                'document-ingestions.view', 'document-ingestions.create', 'document-ingestions.commit', 'document-ingestions.reject',
                'credit-notes.view', 'credit-notes.post',
                'expenses.view', 'expenses.create', 'expenses.update', 'expenses.delete', 'expenses.post', 'expenses.pay',
                'expense-categories.view', 'expense-categories.create', 'expense-categories.update', 'expense-categories.delete',
                'expense-recurrences.view', 'expense-recurrences.create', 'expense-recurrences.update', 'expense-recurrences.delete', 'expenses.export',
                'income.view', 'income.create', 'income.update', 'income.delete', 'income.post',
                'payments.view', 'payments.create', 'payments.pay-supplier', 'payments.allocate', 'payments.refund',
                'instruments.view', 'instruments.update', 'instruments.transfer', 'instruments.clear', 'instruments.bounce', 'instruments.remit', 'instruments.cancel', 'instruments.clear-outbound', 'instruments.cancel-outbound',
                'repositories.view', 'repositories.manage',
                'treasury.view', 'treasury.manage', 'treasury.adjust', 'treasury.transfer',
                'bank-statements.view', 'bank-statements.import', 'bank-statements.reconcile',
                'journal.view', 'journal.create', 'journal.post',
                'accounts.view', 'accounts.manage',
                'ledger.view',
                'reports.financial', 'reports.operational', 'reports.manage',
                // Session B lane Q-10 (c): the Closed -> Open fiscal-period edge,
                // and Session B2 lane C-24 (i): the manual Open -> Closed edge that
                // makes a reopen finishable. Same rationale as reports.manage above —
                // accountant holds both, manager deliberately holds neither
                // (2026-08-06 gate finding I-1).
                'fiscal-periods.reopen',
                'fiscal-periods.close',
                'taxation.tax_configurations.manage',
                'taxation.withholding_rules.manage',
                'withholding.view', 'withholding.create', 'withholding.update',
                // Accountant read access ruled 2026-08-12 (OI-1a + receipts spec):
                // deliveries.view (DN consolidation billing queue — minimal slice
                // pre-landed by the parent to clear the DN lane's F-1 gate) plus
                // the POS receipt-reporting pair delivered by the receipts wave.
                'deliveries.view',
                'pos.view_receipts', 'pos.view_reports',
                'audit.view',
                'compliance.export_jet', 'compliance.verify_chains', 'compliance.view_reprint_log',
                // Accountant has read-only audit access to fraud detection.
                'fraud-settings.view', 'fraud-alerts.view',
                'work-orders.view', 'work-orders.view_financials',
                // Accountant can view scheduling for audit / cancellation reporting.
                'scheduling.bays.view', 'scheduling.appointments.view',
            ],
        ];
    }
}
