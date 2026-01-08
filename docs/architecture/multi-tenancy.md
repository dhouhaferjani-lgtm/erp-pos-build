# Multi-Tenancy & Multi-Company Architecture

## Overview

AutoERP implements a **two-tier isolation model**:
1. **Tenant Level** - Database schema-based isolation
2. **Company Level** - Row-level scoping within tenant

This architecture supports:
- Multiple independent tenants (SaaS)
- Multiple companies per tenant (holding companies, franchises)
- Strict data isolation
- Company switching for users

## Architecture Diagram

```
┌─────────────────────────────────────────────────────────┐
│                    TENANT ISOLATION                     │
├─────────────────────────────────────────────────────────┤
│  Schema: tenant_acme                                    │
│  ┌───────────────────────────────────────────────────┐  │
│  │  COMPANY A            COMPANY B                   │  │
│  │  (Main Office)        (Branch Office)             │  │
│  │  ┌───────────┐        ┌───────────┐               │  │
│  │  │ Products  │        │ Products  │               │  │
│  │  │ Partners  │        │ Partners  │               │  │
│  │  │ Documents │        │ Documents │               │  │
│  │  │ Stock     │        │ Stock     │               │  │
│  │  └───────────┘        └───────────┘               │  │
│  │                                                    │  │
│  │  User 1: Access to both Company A & B             │  │
│  │  User 2: Access only to Company A                 │  │
│  └───────────────────────────────────────────────────┘  │
└─────────────────────────────────────────────────────────┘

┌─────────────────────────────────────────────────────────┐
│  Schema: tenant_garage42                                │
│  └─ Completely isolated from tenant_acme                │
└─────────────────────────────────────────────────────────┘
```

## Tenant Isolation

### Database Schema Pattern

Each tenant gets their own PostgreSQL schema:

```sql
-- Tenant registry (public schema)
CREATE TABLE public.tenants (
    id UUID PRIMARY KEY,
    name VARCHAR(255) NOT NULL,
    slug VARCHAR(100) UNIQUE NOT NULL,
    status VARCHAR(50) NOT NULL,
    plan VARCHAR(50) NOT NULL,
    created_at TIMESTAMP NOT NULL,
    updated_at TIMESTAMP NOT NULL
);

-- Per-tenant schemas
CREATE SCHEMA tenant_acme;
CREATE SCHEMA tenant_garage42;

-- Tables exist in each tenant schema
CREATE TABLE tenant_acme.companies (...);
CREATE TABLE tenant_acme.products (...);
CREATE TABLE tenant_acme.documents (...);

CREATE TABLE tenant_garage42.companies (...);
CREATE TABLE tenant_garage42.products (...);
CREATE TABLE tenant_garage42.documents (...);
```

### Benefits of Schema-Based Isolation

1. **Security** - Database-level isolation, impossible to leak data
2. **Performance** - Indexes are tenant-specific
3. **Backup/Restore** - Per-tenant backup strategy
4. **Migration** - Can move large tenants to dedicated databases

### Tenant Model

```php
namespace App\Modules\Tenant\Domain;

use App\Modules\Tenant\Domain\Enums\TenantStatus;
use App\Modules\Tenant\Domain\Enums\SubscriptionPlan;
use Illuminate\Database\Eloquent\Model;

class Tenant extends Model
{
    protected $connection = 'public';  // Stored in public schema

    protected $fillable = [
        'name',
        'slug',
        'status',
        'plan',
    ];

    protected $casts = [
        'status' => TenantStatus::class,
        'plan' => SubscriptionPlan::class,
    ];

    public function companies()
    {
        return $this->hasMany(Company::class);
    }

    public function users()
    {
        return $this->hasMany(User::class);
    }
}
```

### Tenant Context Middleware

```php
namespace App\Modules\Tenant\Infrastructure\Middleware;

class SetTenantContext
{
    public function handle(Request $request, Closure $next)
    {
        $user = $request->user();

        if (!$user) {
            return response()->json(['error' => 'Unauthenticated'], 401);
        }

        // Set tenant schema for this request
        $tenant = $user->tenant;
        DB::connection()->setSchema("tenant_{$tenant->slug}");

        return $next($request);
    }
}
```

## Company Isolation

### Company Model

```php
namespace App\Modules\Company\Domain;

use App\Modules\Company\Domain\Enums\CompanyStatus;
use Illuminate\Database\Eloquent\Model;

class Company extends Model
{
    protected $fillable = [
        'tenant_id',
        'name',
        'legal_name',
        'tax_id',
        'country_code',
        'locale',
        'timezone',
        'currency',
        'status',
    ];

    protected $casts = [
        'status' => CompanyStatus::class,
    ];

    public function tenant()
    {
        return $this->belongsTo(Tenant::class);
    }

    public function locations()
    {
        return $this->hasMany(Location::class);
    }

    public function users()
    {
        return $this->belongsToMany(User::class, 'user_company_memberships')
            ->withPivot('role');
    }
}
```

### Company Scoping

All transactional data MUST have `company_id`:

```php
// Products table
Schema::create('products', function (Blueprint $table) {
    $table->uuid('id')->primary();
    $table->uuid('tenant_id');
    $table->uuid('company_id');  // ← Company scoping
    $table->string('code')->unique();
    $table->string('name');
    // ...

    $table->foreign('company_id')
        ->references('id')->on('companies')
        ->onDelete('cascade');
});

// Documents table
Schema::create('documents', function (Blueprint $table) {
    $table->uuid('id')->primary();
    $table->uuid('tenant_id');
    $table->uuid('company_id');  // ← Company scoping
    $table->string('type');
    $table->string('status');
    // ...
});

// Stock levels table
Schema::create('stock_levels', function (Blueprint $table) {
    $table->uuid('id')->primary();
    $table->uuid('tenant_id');
    $table->uuid('company_id');  // ← Company scoping
    $table->uuid('product_id');
    $table->uuid('location_id');
    // ...
});
```

### Company Context Service

```php
namespace App\Modules\Company\Services;

class CompanyContext
{
    private ?string $companyId = null;

    public function setCompanyId(string $companyId): void
    {
        $this->companyId = $companyId;
    }

    public function getCompanyId(): string
    {
        if (!$this->companyId) {
            throw new CompanyContextNotSetException();
        }

        return $this->companyId;
    }

    public function hasCompanyContext(): bool
    {
        return $this->companyId !== null;
    }

    public function getCompany(): Company
    {
        return Company::findOrFail($this->getCompanyId());
    }
}
```

### Company Context Middleware

```php
namespace App\Modules\Identity\Presentation\Middleware;

class SetPermissionsTeam
{
    public function __construct(
        private CompanyContext $companyContext,
    ) {}

    public function handle(Request $request, Closure $next)
    {
        $user = $request->user();

        if (!$user) {
            return response()->json(['error' => 'Unauthenticated'], 401);
        }

        // Get current company from header or session
        $companyId = $request->header('X-Company-Id')
            ?? session('current_company_id')
            ?? $user->defaultCompany?->id;

        if (!$companyId) {
            return response()->json(['error' => 'No company selected'], 400);
        }

        // Verify user has access to this company
        $membership = UserCompanyMembership::where('user_id', $user->id)
            ->where('company_id', $companyId)
            ->first();

        if (!$membership) {
            return response()->json(['error' => 'Access denied'], 403);
        }

        // Set company context for this request
        $this->companyContext->setCompanyId($companyId);

        // Set permissions team for Spatie permissions
        setPermissionsTeamId($user->tenant_id);

        return $next($request);
    }
}
```

## User-Company Relationships

### UserCompanyMembership Model

```php
namespace App\Modules\Company\Domain;

use Illuminate\Database\Eloquent\Model;

class UserCompanyMembership extends Model
{
    protected $fillable = [
        'user_id',
        'company_id',
        'role',
        'is_default',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function company()
    {
        return $this->belongsTo(Company::class);
    }
}
```

### User Model Extensions

```php
namespace App\Modules\Identity\Domain;

class User extends Authenticatable
{
    public function tenant()
    {
        return $this->belongsTo(Tenant::class);
    }

    public function companies()
    {
        return $this->belongsToMany(Company::class, 'user_company_memberships')
            ->withPivot('role', 'is_default');
    }

    public function defaultCompany()
    {
        return $this->companies()
            ->wherePivot('is_default', true)
            ->first();
    }

    public function hasAccessToCompany(string $companyId): bool
    {
        return $this->companies()
            ->where('companies.id', $companyId)
            ->exists();
    }
}
```

## Company Switching

### Frontend Pattern

```typescript
// Store current company in Zustand
interface CompanyState {
  currentCompanyId: string | null;
  companies: Company[];
  switchCompany: (companyId: string) => void;
}

const useCompanyStore = create<CompanyState>((set) => ({
  currentCompanyId: null,
  companies: [],
  switchCompany: (companyId) => {
    // Update local state
    set({ currentCompanyId: companyId });

    // Store in localStorage
    localStorage.setItem('current_company_id', companyId);

    // Set header for all future requests
    api.defaults.headers.common['X-Company-Id'] = companyId;

    // Invalidate all cached queries
    queryClient.invalidateQueries();
  },
}));
```

### Backend Endpoint

```php
// Switch company endpoint
Route::post('/users/me/switch-company', [UserController::class, 'switchCompany'])
    ->middleware(['auth:sanctum', SetPermissionsTeam::class]);

// UserController
public function switchCompany(Request $request)
{
    $companyId = $request->input('company_id');

    // Verify access
    if (!$request->user()->hasAccessToCompany($companyId)) {
        return response()->json(['error' => 'Access denied'], 403);
    }

    // Update session
    session(['current_company_id' => $companyId]);

    // Update default company
    UserCompanyMembership::where('user_id', $request->user()->id)
        ->update(['is_default' => false]);

    UserCompanyMembership::where('user_id', $request->user()->id)
        ->where('company_id', $companyId)
        ->update(['is_default' => true]);

    return response()->json(['message' => 'Company switched successfully']);
}
```

## Query Scoping

### Automatic Scoping with Global Scopes

```php
namespace App\Modules\Product\Domain;

class Product extends Model
{
    protected static function booted()
    {
        static::addGlobalScope('company', function (Builder $builder) {
            $companyContext = app(CompanyContext::class);

            if ($companyContext->hasCompanyContext()) {
                $builder->where('company_id', $companyContext->getCompanyId());
            }
        });
    }
}
```

### Manual Scoping in Controllers

```php
class ProductController
{
    public function __construct(
        private CompanyContext $companyContext,
    ) {}

    public function index()
    {
        $products = Product::query()
            ->where('company_id', $this->companyContext->getCompanyId())
            ->paginate(50);

        return ProductResource::collection($products);
    }
}
```

## Data Isolation Testing

### Multi-Company Isolation Test

```php
class MultiCompanyIsolationSimpleTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;
    private Company $companyA;
    private Company $companyB;
    private User $userA;
    private User $userB;

    public function test_user_from_company_a_cannot_view_company_b_document(): void
    {
        // Create documents in different companies
        $documentA = Document::create([
            'company_id' => $this->companyA->id,
            'partner_id' => $this->partnerA->id,
            // ...
        ]);

        $documentB = Document::create([
            'company_id' => $this->companyB->id,
            'partner_id' => $this->partnerB->id,
            // ...
        ]);

        // User A tries to access Company B's document
        $response = $this->actingAs($this->userA)
            ->getJson("/api/v1/invoices/{$documentB->id}");

        // Should return 404 (not found) to prevent information leakage
        $this->assertContains($response->status(), [403, 404]);
    }

    public function test_user_can_view_their_own_company_document(): void
    {
        $documentA = Document::create([
            'company_id' => $this->companyA->id,
            // ...
        ]);

        $response = $this->actingAs($this->userA)
            ->getJson("/api/v1/invoices/{$documentA->id}");

        $response->assertStatus(200);
        $this->assertEquals($documentA->id, $response->json('data.id'));
    }
}
```

## Location Management

### Location as Company Sub-Entity

```php
namespace App\Modules\Company\Domain;

class Location extends Model
{
    protected $fillable = [
        'company_id',
        'code',
        'name',
        'type',          // warehouse, shop, service_center
        'is_active',
        'is_default',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'is_default' => 'boolean',
    ];

    public function company()
    {
        return $this->belongsTo(Company::class);
    }

    public function stockLevels()
    {
        return $this->hasMany(StockLevel::class);
    }
}
```

### Stock Scoping by Location

```php
// Get stock for specific location
$stock = StockLevel::where('company_id', $companyContext->getCompanyId())
    ->where('location_id', $locationId)
    ->where('product_id', $productId)
    ->first();

// Get total stock across all company locations
$totalStock = StockLevel::where('company_id', $companyContext->getCompanyId())
    ->where('product_id', $productId)
    ->sum('quantity');
```

## Cross-Company Reporting (Future)

### Consolidated Reporting

For holding companies managing multiple entities:

```php
public function getConsolidatedSales(Tenant $tenant, string $period): array
{
    // Aggregate across all tenant companies
    return DB::table('documents')
        ->join('companies', 'documents.company_id', '=', 'companies.id')
        ->where('companies.tenant_id', $tenant->id)
        ->where('documents.type', 'invoice')
        ->where('documents.status', 'posted')
        ->whereBetween('documents.document_date', [$startDate, $endDate])
        ->groupBy('documents.company_id', 'companies.name')
        ->select([
            'companies.id',
            'companies.name',
            DB::raw('SUM(documents.total) as total_sales'),
            DB::raw('COUNT(*) as invoice_count'),
        ])
        ->get();
}
```

## Migration Considerations

### Adding Company Scoping to Existing Table

```php
Schema::table('existing_table', function (Blueprint $table) {
    // 1. Add nullable column first
    $table->uuid('company_id')->nullable()->after('tenant_id');

    // 2. Backfill data (migration script)
    // DB::update('UPDATE existing_table SET company_id = (...)');

    // 3. Make non-nullable
    $table->uuid('company_id')->nullable(false)->change();

    // 4. Add foreign key
    $table->foreign('company_id')
        ->references('id')->on('companies')
        ->onDelete('cascade');
});
```

## Best Practices

### 1. Always Validate Company Access

```php
// ✅ CORRECT
public function show(string $id)
{
    $document = Document::where('id', $id)
        ->where('company_id', $this->companyContext->getCompanyId())
        ->firstOrFail();  // 404 if not found or different company

    return new DocumentResource($document);
}

// ❌ WRONG - No company check
public function show(string $id)
{
    $document = Document::findOrFail($id);  // Could leak data!
    return new DocumentResource($document);
}
```

### 2. Use Company Context, Not Session

```php
// ✅ CORRECT - Injected dependency
public function __construct(
    private CompanyContext $companyContext,
) {}

// ❌ WRONG - Direct session access
$companyId = session('current_company_id');
```

### 3. Test Multi-Company Scenarios

Every feature test should include multi-company isolation test:

```php
public function test_prevents_access_to_other_company_products(): void
{
    $productB = Product::create(['company_id' => $this->companyB->id]);

    $response = $this->actingAs($this->userA)
        ->getJson("/api/v1/products/{$productB->id}");

    $this->assertContains($response->status(), [403, 404]);
}
```

## References

- See `docs/modules/architecture.md` for module structure
- See `docs/api/testing.md` for multi-company test patterns
- See CLAUDE.md for security requirements
