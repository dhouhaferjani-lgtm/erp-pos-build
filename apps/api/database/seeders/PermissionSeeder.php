<?php

declare(strict_types=1);

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

class PermissionSeeder extends Seeder
{
    /**
     * Seed all application permissions and default roles
     */
    public function run(): void
    {
        // Reset cached roles and permissions
        app()[\Spatie\Permission\PermissionRegistrar::class]->forgetCachedPermissions();

        // Define all permissions
        $permissions = [
            // Products & Catalog
            'products.view',
            'products.create',
            'products.update',
            'products.delete',

            // Partners (Customers/Suppliers)
            'partners.view',
            'partners.create',
            'partners.update',
            'partners.delete',

            // Vehicles
            'vehicles.view',
            'vehicles.create',
            'vehicles.update',
            'vehicles.delete',

            // Documents - General
            'documents.view',

            // Documents - Quotes
            'quotes.view',
            'quotes.create',
            'quotes.update',
            'quotes.delete',
            'quotes.confirm',
            'quotes.convert',

            // Documents - Sales Orders
            'orders.view',
            'orders.create',
            'orders.update',
            'orders.delete',
            'orders.confirm',

            // Documents - Invoices
            'invoices.view',
            'invoices.create',
            'invoices.update',
            'invoices.delete',
            'invoices.post',
            'invoices.cancel',

            // Documents - Credit Notes
            'credit-notes.view',
            'credit-notes.create',
            'credit-notes.post',
            'credit-notes.cancel',

            // Documents - Purchase Orders
            'purchase-orders.view',
            'purchase-orders.create',
            'purchase-orders.update',
            'purchase-orders.delete',
            'purchase-orders.confirm',
            'purchase-orders.receive',

            // Documents - Delivery Notes
            'deliveries.view',
            'deliveries.create',
            'deliveries.confirm',

            // Treasury - Payment Methods
            'treasury.view',
            'treasury.manage',

            // Treasury - Repositories
            'repositories.view',
            'repositories.manage',

            // Treasury - Instruments
            'instruments.view',
            'instruments.create',
            'instruments.transfer',
            'instruments.clear',

            // Treasury - Payments
            'payments.view',
            'payments.create',
            'payments.refund',
            'payments.reverse',

            // Inventory
            'inventory.view',
            'inventory.adjust',
            'inventory.receive',
            'inventory.transfer',

            // Accounting
            'accounts.view',
            'accounts.manage',
            'journal.view',
            'journal.create',
            'journal.post',

            // Pricing
            'pricing.view',
            'pricing.manage',

            // Pricing - Margin & Cost Controls
            'pricing.sell_below_target_margin',
            'pricing.sell_below_minimum_margin',
            'pricing.sell_below_cost',
            'pricing.view_cost_prices',
            'pricing.manage_pricing_rules',
        ];

        // Create all permissions for 'sanctum' guard (API authentication)
        foreach ($permissions as $permission) {
            Permission::firstOrCreate([
                'name' => $permission,
                'guard_name' => 'sanctum',
            ]);
        }

        // Create default roles

        // 1. Administrator (Full Access)
        $admin = Role::firstOrCreate(['name' => 'Administrator', 'guard_name' => 'sanctum']);
        $admin->syncPermissions(Permission::where('guard_name', 'sanctum')->get());

        // 2. Sales Manager
        $salesManager = Role::firstOrCreate(['name' => 'Sales Manager', 'guard_name' => 'sanctum']);
        $salesManager->syncPermissions([
            'products.view',
            'partners.view', 'partners.create', 'partners.update',
            'vehicles.view', 'vehicles.create', 'vehicles.update',
            'documents.view',
            'quotes.view', 'quotes.create', 'quotes.update', 'quotes.delete', 'quotes.confirm', 'quotes.convert',
            'orders.view', 'orders.create', 'orders.update', 'orders.delete', 'orders.confirm',
            'invoices.view', 'invoices.create', 'invoices.update', 'invoices.delete', 'invoices.post',
            'credit-notes.view', 'credit-notes.create', 'credit-notes.post',
            'deliveries.view', 'deliveries.create', 'deliveries.confirm',
            'payments.view',
            'inventory.view',
            'pricing.view',
        ]);

        // 3. Accountant
        $accountant = Role::firstOrCreate(['name' => 'Accountant', 'guard_name' => 'sanctum']);
        $accountant->syncPermissions([
            'partners.view',
            'documents.view',
            'invoices.view', 'invoices.post', 'invoices.cancel',
            'credit-notes.view', 'credit-notes.post', 'credit-notes.cancel',
            'treasury.view', 'treasury.manage',
            'repositories.view', 'repositories.manage',
            'instruments.view', 'instruments.create', 'instruments.transfer', 'instruments.clear',
            'payments.view', 'payments.create', 'payments.refund', 'payments.reverse',
            'accounts.view', 'accounts.manage',
            'journal.view', 'journal.create', 'journal.post',
            'pricing.view',
        ]);

        // 4. Sales Rep
        $salesRep = Role::firstOrCreate(['name' => 'Sales Rep', 'guard_name' => 'sanctum']);
        $salesRep->syncPermissions([
            'products.view',
            'partners.view', 'partners.create',
            'vehicles.view', 'vehicles.create',
            'quotes.view', 'quotes.create', 'quotes.update', 'quotes.delete',
            'orders.view',
            'invoices.view',
            'inventory.view',
            'pricing.view',
        ]);

        // 5. Warehouse Manager
        $warehouseManager = Role::firstOrCreate(['name' => 'Warehouse Manager', 'guard_name' => 'sanctum']);
        $warehouseManager->syncPermissions([
            'products.view',
            'purchase-orders.view', 'purchase-orders.receive',
            'deliveries.view', 'deliveries.create', 'deliveries.confirm',
            'inventory.view', 'inventory.adjust', 'inventory.receive', 'inventory.transfer',
        ]);

        // 6. Receptionist
        $receptionist = Role::firstOrCreate(['name' => 'Receptionist', 'guard_name' => 'sanctum']);
        $receptionist->syncPermissions([
            'partners.view', 'partners.create',
            'vehicles.view', 'vehicles.create',
            'quotes.view',
            'payments.view',
        ]);

        $this->command->info('Permissions and roles seeded successfully!');
    }
}
