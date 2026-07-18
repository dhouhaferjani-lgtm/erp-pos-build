<?php

declare(strict_types=1);

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

class RolesAndPermissionsSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        // Reset cached roles and permissions
        app()[PermissionRegistrar::class]->forgetCachedPermissions();

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
            'documents.update',  // Document attachments (Media module)
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
            'goods-receipt.create-standalone',
            'supplier-invoices.create-pending',
            'supplier-invoices.link-receipts',
            'supplier-invoices.approve-invoice-first',
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

            // Stock Transfer (document-based, lifecycle-tracked)
            'inventory.transfers.view',
            'inventory.transfers.create',
            'inventory.transfers.complete',
            'inventory.transfers.cancel',

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

            'repositories.view',
            'repositories.manage',

            'treasury.view',
            'treasury.manage',
            'treasury.adjust',
            'treasury.transfer',

            // Accounting
            'journal.view',
            'journal.create',
            'journal.post',

            'accounts.view',
            'accounts.manage',

            'ledger.view',  // General ledger access

            'reports.view',  // All financial reports
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
            'settings.manage',
            'audit.view',
            'imports.manage',
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
                'purchase-orders.view', 'purchase-orders.create', 'purchase-orders.update', 'purchase-orders.confirm', 'purchase-orders.receive', 'goods-receipt.edit-price', 'goods-receipt.create-standalone',
                'supplier-invoices.create-pending', 'supplier-invoices.link-receipts', 'supplier-invoices.approve-invoice-first',
                'document-ingestions.view', 'document-ingestions.create', 'document-ingestions.commit', 'document-ingestions.reject',
                'purchase-quote-requests.view', 'purchase-quote-requests.create', 'purchase-quote-requests.update', 'purchase-quote-requests.convert', 'purchase-quote-requests.delete',
                'invoices.view', 'invoices.create', 'invoices.update', 'invoices.post', 'invoices.print',
                'credit-notes.view', 'credit-notes.create', 'credit-notes.post',
                'inventory.view', 'inventory.adjust', 'inventory.transfer', 'inventory.receive',
                'uom.view', 'uom.create', 'uom.edit', 'uom.delete',
                'inventory.transfers.view', 'inventory.transfers.create', 'inventory.transfers.complete', 'inventory.transfers.cancel',
                'deliveries.view', 'deliveries.create', 'deliveries.edit', 'deliveries.delete', 'deliveries.confirm',
                'expenses.view', 'expenses.create', 'expenses.update', 'expenses.post', 'expenses.pay',
                'expense-categories.view', 'expense-categories.create', 'expense-categories.update', 'expense-categories.delete',
                'expense-recurrences.view', 'expense-recurrences.create', 'expense-recurrences.update', 'expense-recurrences.delete', 'expenses.export',
                'income.view', 'income.create', 'income.update', 'income.post',
                'payments.view', 'payments.create', 'payments.allocate', 'payments.refund',
                'instruments.view', 'instruments.create', 'instruments.update', 'instruments.transfer', 'instruments.clear', 'instruments.bounce', 'instruments.remit', 'instruments.cancel',
                'repositories.view',
                'treasury.view', 'treasury.adjust', 'treasury.transfer',
                'journal.view',
                'accounts.view', 'accounts.manage',
                'reports.financial', 'reports.operational', 'reports.manage', 'dashboard.owner',
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
                'reports.operational',
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
                'supplier-invoices.create-pending', 'supplier-invoices.link-receipts', 'supplier-invoices.approve-invoice-first',
                'document-ingestions.view', 'document-ingestions.create', 'document-ingestions.commit', 'document-ingestions.reject',
                'credit-notes.view', 'credit-notes.post',
                'expenses.view', 'expenses.create', 'expenses.update', 'expenses.delete', 'expenses.post', 'expenses.pay',
                'expense-categories.view', 'expense-categories.create', 'expense-categories.update', 'expense-categories.delete',
                'expense-recurrences.view', 'expense-recurrences.create', 'expense-recurrences.update', 'expense-recurrences.delete', 'expenses.export',
                'income.view', 'income.create', 'income.update', 'income.delete', 'income.post',
                'payments.view', 'payments.create', 'payments.allocate', 'payments.refund',
                'instruments.view', 'instruments.update', 'instruments.transfer', 'instruments.clear', 'instruments.bounce', 'instruments.remit', 'instruments.cancel',
                'repositories.view', 'repositories.manage',
                'treasury.view', 'treasury.manage', 'treasury.adjust', 'treasury.transfer',
                'journal.view', 'journal.create', 'journal.post',
                'accounts.view', 'accounts.manage',
                'reports.financial', 'reports.manage',
                'withholding.view', 'withholding.create', 'withholding.update',
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
