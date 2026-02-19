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
        $permissions = [
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

            // Composite Items / Catalog
            'composite-items.view',
            'composite-items.create',
            'composite-items.update',
            'composite-items.delete',
            'composite-items.manage-recipes',
            'modifier-groups.view',
            'modifier-groups.manage',

            // Vehicle Management
            'vehicles.view',
            'vehicles.create',
            'vehicles.update',
            'vehicles.delete',

            // Sales Documents (Quotes, Orders, Invoices)
            'documents.view',  // Unified document view
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

            'deliveries.view',
            'deliveries.create',
            'deliveries.confirm',

            // Expenses
            'expenses.view',
            'expenses.create',
            'expenses.update',
            'expenses.delete',
            'expenses.post',

            'expense-categories.view',
            'expense-categories.create',
            'expense-categories.update',
            'expense-categories.delete',

            // Treasury/Payments
            'payments.view',
            'payments.create',
            'payments.allocate',
            'payments.void',

            'instruments.view',
            'instruments.create',
            'instruments.transfer',
            'instruments.clear',

            'repositories.view',
            'repositories.manage',

            'treasury.view',
            'treasury.manage',

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

            // Workshop
            'work-orders.view',
            'work-orders.create',
            'work-orders.update',
            'work-orders.complete',

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
            'pos.manage_shifts',
            'pos.view_reports',
            'pos.void_receipts',
            'pos.view_receipts',

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

            // System
            'settings.view',
            'settings.update',
            'settings.manage',
            'audit.view',
            'imports.manage',
        ];

        foreach ($permissions as $permission) {
            Permission::firstOrCreate(['name' => $permission, 'guard_name' => 'sanctum']);
        }

        $this->command->info('Created '.count($permissions).' permissions');
    }

    /**
     * Create roles and assign permissions.
     */
    private function createRoles(): void
    {
        // Admin - Full access (includes all POS permissions)
        $admin = Role::firstOrCreate(['name' => 'admin', 'guard_name' => 'sanctum']);
        $admin->syncPermissions(Permission::all());
        $this->command->info('Created role: admin (all permissions)');

        // Manager - Operations management
        $manager = Role::firstOrCreate(['name' => 'manager', 'guard_name' => 'sanctum']);
        $manager->syncPermissions([
            'partners.view', 'partners.create', 'partners.update',
            'products.view', 'products.create', 'products.update', 'products.import',
            'vehicles.view', 'vehicles.create', 'vehicles.update',
            'documents.view',
            'quotes.view', 'quotes.create', 'quotes.update', 'quotes.convert',
            'orders.view', 'orders.create', 'orders.update', 'orders.confirm',
            'purchase-orders.view', 'purchase-orders.create', 'purchase-orders.update', 'purchase-orders.confirm', 'purchase-orders.receive',
            'invoices.view', 'invoices.create', 'invoices.update', 'invoices.post', 'invoices.print',
            'credit-notes.view', 'credit-notes.create', 'credit-notes.post',
            'inventory.view', 'inventory.adjust', 'inventory.transfer', 'inventory.receive',
            'deliveries.view', 'deliveries.create', 'deliveries.confirm',
            'expenses.view', 'expenses.create', 'expenses.update', 'expenses.post',
            'expense-categories.view', 'expense-categories.create', 'expense-categories.update', 'expense-categories.delete',
            'payments.view', 'payments.create', 'payments.allocate',
            'instruments.view', 'instruments.create', 'instruments.transfer',
            'repositories.view',
            'treasury.view',
            'journal.view',
            'accounts.view', 'accounts.manage',
            'reports.financial', 'reports.operational',
            'work-orders.view', 'work-orders.create', 'work-orders.update', 'work-orders.complete',
            'users.view',
            'pos.manage_terminals', 'pos.operate_terminal', 'pos.manage_shifts',
            'pos.view_reports', 'pos.void_receipts', 'pos.view_receipts',
            'batches.view', 'batches.create', 'batches.update', 'batches.delete',
            'batches.recall', 'batches.write-off', 'batches.traceability',
            'withholding.view',
            'settings.view', 'settings.manage',
        ]);
        $this->command->info('Created role: manager');

        // Cashier - Point of sale operations
        $cashier = Role::firstOrCreate(['name' => 'cashier', 'guard_name' => 'sanctum']);
        $cashier->syncPermissions([
            'partners.view', 'partners.create',
            'products.view',
            'vehicles.view',
            'documents.view',
            'quotes.view', 'quotes.create',
            'orders.view',
            'invoices.view', 'invoices.create', 'invoices.print',
            'expenses.view', 'expenses.create',
            'expense-categories.view',
            'inventory.view',
            'deliveries.view',
            'payments.view', 'payments.create',
            'instruments.view', 'instruments.create',
            'work-orders.view',
            'pos.operate_terminal', 'pos.view_receipts',
            'batches.view',
        ]);
        $this->command->info('Created role: cashier');

        // Viewer - Read-only access
        $viewer = Role::firstOrCreate(['name' => 'viewer', 'guard_name' => 'sanctum']);
        $viewer->syncPermissions([
            'partners.view',
            'products.view',
            'vehicles.view',
            'documents.view',
            'quotes.view',
            'orders.view',
            'purchase-orders.view',
            'invoices.view',
            'credit-notes.view',
            'expenses.view',
            'expense-categories.view',
            'inventory.view',
            'deliveries.view',
            'payments.view',
            'instruments.view',
            'repositories.view',
            'journal.view',
            'accounts.view',
            'reports.operational',
            'work-orders.view',
            'settings.view',
        ]);
        $this->command->info('Created role: viewer');

        // Technician - Workshop operations
        $technician = Role::firstOrCreate(['name' => 'technician', 'guard_name' => 'sanctum']);
        $technician->syncPermissions([
            'partners.view',
            'products.view',
            'vehicles.view',
            'inventory.view',
            'work-orders.view', 'work-orders.update', 'work-orders.complete',
        ]);
        $this->command->info('Created role: technician');

        // Operator - Standard operations staff
        $operator = Role::firstOrCreate(['name' => 'operator', 'guard_name' => 'sanctum']);
        $operator->syncPermissions([
            'partners.view', 'partners.create', 'partners.update',
            'products.view',
            'vehicles.view', 'vehicles.create', 'vehicles.update',
            'documents.view',
            'quotes.view', 'quotes.create', 'quotes.update',
            'orders.view', 'orders.create', 'orders.update',
            'purchase-orders.view', 'purchase-orders.create', 'purchase-orders.update',
            'invoices.view', 'invoices.create', 'invoices.print',
            'expenses.view', 'expenses.create', 'expenses.update',
            'expense-categories.view',
            'inventory.view',
            'deliveries.view', 'deliveries.create',
            'payments.view', 'payments.create',
            'work-orders.view', 'work-orders.create', 'work-orders.update',
        ]);
        $this->command->info('Created role: operator');

        // Accountant - Financial operations
        $accountant = Role::firstOrCreate(['name' => 'accountant', 'guard_name' => 'sanctum']);
        $accountant->syncPermissions([
            'partners.view',
            'documents.view',
            'invoices.view', 'invoices.post',
            'credit-notes.view', 'credit-notes.post',
            'expenses.view', 'expenses.create', 'expenses.update', 'expenses.delete', 'expenses.post',
            'expense-categories.view', 'expense-categories.create', 'expense-categories.update', 'expense-categories.delete',
            'payments.view', 'payments.create', 'payments.allocate',
            'instruments.view', 'instruments.transfer', 'instruments.clear',
            'repositories.view', 'repositories.manage',
            'treasury.view', 'treasury.manage',
            'journal.view', 'journal.create', 'journal.post',
            'accounts.view', 'accounts.manage',
            'reports.financial',
            'withholding.view', 'withholding.create', 'withholding.update',
            'audit.view',
        ]);
        $this->command->info('Created role: accountant');
    }
}
