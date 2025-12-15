# AutoERP Permission Matrix

> **Complete permission audit and enforcement documentation**
> Last Updated: December 2025

---

## Permission Architecture

### Permission Naming Convention

Format: `{module}.{action}`

- **module**: Lowercase module name (products, documents, treasury, etc.)
- **action**: Standard CRUD operation or specific action

### Standard Actions

| Action | Purpose | Example |
|--------|---------|---------|
| `view` | List and view details | `products.view` |
| `create` | Create new records | `invoices.create` |
| `update` | Modify draft/unlocked records | `quotes.update` |
| `delete` | Delete draft records | `orders.delete` |
| `manage` | Full CRUD operations | `pricing.manage` |
| `post` | Post/finalize fiscal documents | `invoices.post` |
| `cancel` | Cancel posted documents | `invoices.cancel` |

---

## Complete Permission List

### Products & Catalog

| Permission | Description | Endpoints |
|------------|-------------|-----------|
| `products.view` | View product catalog | GET /products, /products/{id} |
| `products.create` | Create new products | POST /products |
| `products.update` | Update products | PATCH /products/{id} |
| `products.delete` | Delete products | DELETE /products/{id} |

### Vehicles

| Permission | Description | Endpoints |
|------------|-------------|-----------|
| `vehicles.view` | View vehicle records | GET /vehicles, /vehicles/{id} |
| `vehicles.create` | Register new vehicles | POST /vehicles |
| `vehicles.update` | Update vehicle info | PATCH /vehicles/{id} |
| `vehicles.delete` | Delete vehicles | DELETE /vehicles/{id} |

### Partners (Customers/Suppliers)

| Permission | Description | Endpoints |
|------------|-------------|-----------|
| `partners.view` | View partners | GET /partners, /partners/{id} |
| `partners.create` | Create new partners | POST /partners |
| `partners.update` | Update partner info | PATCH /partners/{id} |
| `partners.delete` | Delete partners | DELETE /partners/{id} |

### Documents - Quotes

| Permission | Description | Endpoints |
|------------|-------------|-----------|
| `quotes.view` | View quotes | GET /quotes, /quotes/{id} |
| `quotes.create` | Create quotes | POST /quotes |
| `quotes.update` | Update draft quotes | PATCH /quotes/{id} |
| `quotes.delete` | Delete draft quotes | DELETE /quotes/{id} |
| `quotes.confirm` | Confirm quotes | POST /quotes/{id}/confirm |
| `quotes.convert` | Convert to order | POST /quotes/{id}/convert-to-order |

### Documents - Sales Orders

| Permission | Description | Endpoints |
|------------|-------------|-----------|
| `orders.view` | View orders | GET /orders, /orders/{id} |
| `orders.create` | Create orders | POST /orders |
| `orders.update` | Update draft orders | PATCH /orders/{id} |
| `orders.delete` | Delete draft orders | DELETE /orders/{id} |
| `orders.confirm` | Confirm orders | POST /orders/{id}/confirm |

### Documents - Invoices

| Permission | Description | Endpoints |
|------------|-------------|-----------|
| `invoices.view` | View invoices | GET /invoices, /invoices/{id} |
| `invoices.create` | Create invoices | POST /invoices, /orders/{id}/convert-to-invoice |
| `invoices.update` | Update draft invoices | PATCH /invoices/{id} |
| `invoices.delete` | Delete draft invoices | DELETE /invoices/{id} |
| `invoices.post` | Post invoices (fiscal) | POST /invoices/{id}/post |
| `invoices.cancel` | Cancel posted invoices | POST /invoices/{id}/cancel |

### Documents - Credit Notes

| Permission | Description | Endpoints |
|------------|-------------|-----------|
| `credit-notes.view` | View credit notes | GET /credit-notes, /credit-notes/{id} |
| `credit-notes.create` | Create credit notes | POST /credit-notes, /invoices/{id}/credit-note |
| `credit-notes.post` | Post credit notes | POST /credit-notes/{id}/post |
| `credit-notes.cancel` | Cancel credit notes | POST /credit-notes/{id}/cancel |

### Documents - Purchase Orders

| Permission | Description | Endpoints |
|------------|-------------|-----------|
| `purchase-orders.view` | View POs | GET /purchase-orders, /purchase-orders/{id} |
| `purchase-orders.create` | Create POs | POST /purchase-orders |
| `purchase-orders.update` | Update draft POs | PATCH /purchase-orders/{id} |
| `purchase-orders.delete` | Delete draft POs | DELETE /purchase-orders/{id} |
| `purchase-orders.confirm` | Confirm POs | POST /purchase-orders/{id}/confirm |
| `purchase-orders.receive` | Receive goods | POST /purchase-orders/{id}/receive |

### Documents - Delivery Notes

| Permission | Description | Endpoints |
|------------|-------------|-----------|
| `deliveries.view` | View delivery notes | GET /delivery-notes, /delivery-notes/{id} |
| `deliveries.create` | Create deliveries | POST /delivery-notes |
| `deliveries.confirm` | Confirm deliveries | POST /delivery-notes/{id}/confirm |

### General Documents

| Permission | Description | Endpoints |
|------------|-------------|-----------|
| `documents.view` | View all document types | GET /documents, /documents/{id} |

### Treasury - Payment Methods

| Permission | Description | Endpoints |
|------------|-------------|-----------|
| `treasury.view` | View payment methods | GET /payment-methods, /payment-methods/{id} |
| `treasury.manage` | Manage payment methods | POST/PATCH /payment-methods |

### Treasury - Repositories

| Permission | Description | Endpoints |
|------------|-------------|-----------|
| `repositories.view` | View repositories | GET /payment-repositories, /payment-repositories/{id} |
| `repositories.manage` | Manage repositories | POST/PATCH /payment-repositories |

### Treasury - Instruments

| Permission | Description | Endpoints |
|------------|-------------|-----------|
| `instruments.view` | View instruments | GET /payment-instruments, /payment-instruments/{id} |
| `instruments.create` | Create instruments | POST /payment-instruments |
| `instruments.transfer` | Transfer instruments | POST /payment-instruments/{id}/transfer |
| `instruments.clear` | Clear/bounce instruments | POST /payment-instruments/{id}/clear, /bounce |

### Treasury - Payments

| Permission | Description | Endpoints |
|------------|-------------|-----------|
| `payments.view` | View payments | GET /payments, /payments/{id} |
| `payments.create` | Create payments | POST /payments, /documents/{id}/split-payment |
| `payments.refund` | Refund payments | POST /payments/{id}/refund |
| `payments.reverse` | Reverse payments | POST /payments/{id}/reverse |

### Inventory

| Permission | Description | Endpoints |
|------------|-------------|-----------|
| `inventory.view` | View stock levels | GET /inventory, /inventory/movements |
| `inventory.adjust` | Adjust stock | POST /inventory/adjust, /reserve, /release |
| `inventory.receive` | Receive stock | POST /inventory/receive |
| `inventory.transfer` | Transfer stock | POST /inventory/transfer |

### Accounting

| Permission | Description | Endpoints |
|------------|-------------|-----------|
| `accounts.view` | View chart of accounts | GET /accounts, /accounts/{id} |
| `accounts.manage` | Manage accounts | POST/PATCH /accounts |
| `journal.view` | View journal entries | GET /journal-entries, /journal-entries/{id} |
| `journal.create` | Create entries | POST /journal-entries |
| `journal.post` | Post entries | POST /journal-entries/{id}/post |

### Pricing

| Permission | Description | Endpoints |
|------------|-------------|-----------|
| `pricing.view` | View price lists | GET /price-lists, /pricing/get-price |
| `pricing.manage` | Manage pricing | POST/PATCH/DELETE /price-lists |

---

## Role-Based Permission Templates

### 1. Administrator (Full Access)

```php
[
    // Products
    'products.view', 'products.create', 'products.update', 'products.delete',

    // Partners
    'partners.view', 'partners.create', 'partners.update', 'partners.delete',

    // Vehicles
    'vehicles.view', 'vehicles.create', 'vehicles.update', 'vehicles.delete',

    // Documents - All
    'documents.view',
    'quotes.view', 'quotes.create', 'quotes.update', 'quotes.delete', 'quotes.confirm', 'quotes.convert',
    'orders.view', 'orders.create', 'orders.update', 'orders.delete', 'orders.confirm',
    'invoices.view', 'invoices.create', 'invoices.update', 'invoices.delete', 'invoices.post', 'invoices.cancel',
    'credit-notes.view', 'credit-notes.create', 'credit-notes.post', 'credit-notes.cancel',
    'purchase-orders.view', 'purchase-orders.create', 'purchase-orders.update', 'purchase-orders.delete',
    'purchase-orders.confirm', 'purchase-orders.receive',
    'deliveries.view', 'deliveries.create', 'deliveries.confirm',

    // Treasury
    'treasury.view', 'treasury.manage',
    'repositories.view', 'repositories.manage',
    'instruments.view', 'instruments.create', 'instruments.transfer', 'instruments.clear',
    'payments.view', 'payments.create', 'payments.refund', 'payments.reverse',

    // Inventory
    'inventory.view', 'inventory.adjust', 'inventory.receive', 'inventory.transfer',

    // Accounting
    'accounts.view', 'accounts.manage',
    'journal.view', 'journal.create', 'journal.post',

    // Pricing
    'pricing.view', 'pricing.manage',
]
```

### 2. Sales Manager

```php
[
    // Products (read-only)
    'products.view',

    // Partners (full access)
    'partners.view', 'partners.create', 'partners.update',

    // Vehicles (full access)
    'vehicles.view', 'vehicles.create', 'vehicles.update',

    // Documents - Sales cycle
    'documents.view',
    'quotes.view', 'quotes.create', 'quotes.update', 'quotes.delete', 'quotes.confirm', 'quotes.convert',
    'orders.view', 'orders.create', 'orders.update', 'orders.delete', 'orders.confirm',
    'invoices.view', 'invoices.create', 'invoices.update', 'invoices.delete', 'invoices.post',
    'credit-notes.view', 'credit-notes.create', 'credit-notes.post',
    'deliveries.view', 'deliveries.create', 'deliveries.confirm',

    // Treasury (view payments)
    'payments.view',

    // Inventory (view only)
    'inventory.view',

    // Pricing (view only)
    'pricing.view',
]
```

### 3. Accountant

```php
[
    // Partners (read-only)
    'partners.view',

    // Documents (read-only + post)
    'documents.view',
    'invoices.view', 'invoices.post', 'invoices.cancel',
    'credit-notes.view', 'credit-notes.post', 'credit-notes.cancel',

    // Treasury (full access)
    'treasury.view', 'treasury.manage',
    'repositories.view', 'repositories.manage',
    'instruments.view', 'instruments.create', 'instruments.transfer', 'instruments.clear',
    'payments.view', 'payments.create', 'payments.refund', 'payments.reverse',

    // Accounting (full access)
    'accounts.view', 'accounts.manage',
    'journal.view', 'journal.create', 'journal.post',

    // Pricing (read-only)
    'pricing.view',
]
```

### 4. Sales Rep

```php
[
    // Products (read-only)
    'products.view',

    // Partners (view + create)
    'partners.view', 'partners.create',

    // Vehicles (view + create)
    'vehicles.view', 'vehicles.create',

    // Documents - Limited
    'quotes.view', 'quotes.create', 'quotes.update', 'quotes.delete',
    'orders.view',
    'invoices.view',

    // Inventory (view only)
    'inventory.view',

    // Pricing (view only)
    'pricing.view',
]
```

### 5. Warehouse Manager

```php
[
    // Products (read-only)
    'products.view',

    // Purchase Orders (receive only)
    'purchase-orders.view', 'purchase-orders.receive',

    // Deliveries (full access)
    'deliveries.view', 'deliveries.create', 'deliveries.confirm',

    // Inventory (full access)
    'inventory.view', 'inventory.adjust', 'inventory.receive', 'inventory.transfer',
]
```

### 6. Receptionist

```php
[
    // Partners (create)
    'partners.view', 'partners.create',

    // Vehicles (create)
    'vehicles.view', 'vehicles.create',

    // Quotes (view only)
    'quotes.view',

    // Payments (view only)
    'payments.view',
]
```

---

## Permission Enforcement Checklist

### Backend Enforcement (Laravel)

- [x] All routes protected with `auth:sanctum` middleware
- [x] All routes use `can:` permission checks
- [x] SetPermissionsTeam middleware applied to all module routes
- [x] Controllers validate permissions before operations
- [x] Service layer receives authenticated user context
- [x] Repository layer respects tenant isolation

### Frontend Enforcement (React)

- [x] Protected routes check authentication
- [x] Menu items filtered by permissions
- [x] Action buttons hidden based on permissions
- [x] Forms disable fields based on document status + permissions
- [x] API calls include authentication tokens

### Audit & Compliance

- [x] All fiscal operations logged
- [x] Permission changes tracked
- [x] Failed authorization attempts logged
- [x] User activity monitored

---

## Testing Permission Enforcement

### Unit Tests

```php
// Test permission denial
public function test_unauthorized_user_cannot_post_invoice()
{
    $user = User::factory()->create();
    $invoice = Invoice::factory()->create();

    $this->actingAs($user)
        ->post("/api/v1/invoices/{$invoice->id}/post")
        ->assertForbidden();
}

// Test permission grant
public function test_authorized_user_can_post_invoice()
{
    $user = User::factory()->create();
    $user->givePermissionTo('invoices.post');
    $invoice = Invoice::factory()->create(['status' => 'confirmed']);

    $this->actingAs($user)
        ->post("/api/v1/invoices/{$invoice->id}/post")
        ->assertSuccessful();
}
```

### Integration Tests

```typescript
// Frontend permission test
describe('Invoice posting', () => {
  it('shows post button for users with invoices.post permission', () => {
    const user = createUser({ permissions: ['invoices.post'] })
    render(<InvoiceDetailPage />, { user })
    expect(screen.getByRole('button', { name: /post/i })).toBeInTheDocument()
  })

  it('hides post button for users without permission', () => {
    const user = createUser({ permissions: ['invoices.view'] })
    render(<InvoiceDetailPage />, { user })
    expect(screen.queryByRole('button', { name: /post/i })).not.toBeInTheDocument()
  })
})
```

---

## Location-Based Access Control

### Implementation Plan

1. **Add location_id to users table**
   - Users assigned to specific locations (branches/warehouses)
   - Nullable for HQ/admin users

2. **Scope queries by location**
   ```php
   $documents = Document::when(
       $user->location_id,
       fn($q) => $q->where('location_id', $user->location_id)
   )->get();
   ```

3. **UI location selector**
   - Admins can switch locations
   - Regular users see only their location

4. **Inventory by location**
   - Stock movements restricted to assigned locations
   - Transfer requests require approval

---

## Permission Migration Strategy

### Seeding Permissions

```php
// database/seeders/PermissionSeeder.php
public function run()
{
    $permissions = [
        // Products
        'products.view', 'products.create', 'products.update', 'products.delete',

        // Add all permissions from matrix...
    ];

    foreach ($permissions as $permission) {
        Permission::firstOrCreate(['name' => $permission]);
    }

    // Create default roles
    $admin = Role::firstOrCreate(['name' => 'Administrator']);
    $admin->givePermissionTo(Permission::all());

    $salesManager = Role::firstOrCreate(['name' => 'Sales Manager']);
    $salesManager->givePermissionTo([
        'products.view',
        'partners.view', 'partners.create', 'partners.update',
        // ... sales manager permissions
    ]);
}
```

---

## Security Best Practices

1. **Never trust frontend**
   - Always validate permissions in controllers
   - Frontend visibility is UX, not security

2. **Fail closed**
   - Default deny if permission unclear
   - Explicit grants only

3. **Audit everything**
   - Log permission changes
   - Monitor failed auth attempts
   - Track sensitive operations

4. **Least privilege**
   - Users get minimum permissions needed
   - Temporary elevations logged

5. **Regular reviews**
   - Quarterly permission audit
   - Remove unused permissions
   - Update role templates

---

*Document Version: 1.0*
*Phase 3.9 - Advanced Permissions*
*AutoERP © 2025*
