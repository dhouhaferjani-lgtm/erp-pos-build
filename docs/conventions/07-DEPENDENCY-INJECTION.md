# Dependency Injection Conventions

> **Last Updated:** 2026-01-05
> **Purpose:** Constructor injection is the ONLY acceptable DI pattern

## The Rule: Constructor Injection Always

**CRITICAL: All dependencies MUST be injected via constructor. Never use `app()` helper in controllers or services.**

---

## ✅ CORRECT Pattern

```php
class InvoiceController extends Controller
{
    public function __construct(
        private readonly CompanyContext $companyContext,
        private readonly TaxCalculationService $taxCalculationService,
    ) {}

    public function confirm(string $id): JsonResponse
    {
        $companyId = $this->companyContext->getCompanyId();
        $taxResult = $this->taxCalculationService->calculateDocumentTaxes($document);
        // ...
    }
}
```

**Why this works:**
- Laravel's service container auto-resolves dependencies
- Constructor signature documents all dependencies
- Easy to mock in tests
- Type-safe with IDE autocomplete

---

## ❌ WRONG Pattern (Service Locator Anti-Pattern)

```php
class InvoiceController extends Controller
{
    public function confirm(string $id): JsonResponse
    {
        // ❌ NO! Don't use app() helper
        $companyId = app(CompanyContext::class)->getCompanyId();
        $taxCalculationService = app(TaxCalculationService::class);
        // ...
    }
}
```

**Why this fails:**
- Hidden dependencies - not visible in constructor
- Hard to test - can't mock easily
- IDE can't help with autocomplete
- No type safety
- Service locator is an anti-pattern

---

## When to Inject

### Always Inject These:

**Context Services** (needed in most methods)
```php
public function __construct(
    private readonly CompanyContext $companyContext,
    private readonly LocationContext $locationContext,
) {}
```

**Business Logic Services** (domain/application services)
```php
public function __construct(
    private readonly DocumentNumberingService $numberingService,
    private readonly DocumentPostingService $postingService,
) {}
```

**Services Used in Multiple Methods**
```php
public function __construct(
    private readonly TaxCalculationService $taxCalculationService,
) {}
```

### Can Skip Constructor Only If:

- **Stateless utility** with no dependencies
- **Never use `app()`** - just don't inject anything if not needed
- Examples: Pure calculation functions, simple transformers

**❌ Still NEVER do this:**
```php
// Even for one-time use, DON'T do this:
$result = app(SomeService::class)->method(); // NO!
```

---

## Real Examples from Codebase

### Simple Controller (Context Only)
```php
// Used in 25+ controllers
class CategoryController extends Controller
{
    public function __construct(
        private readonly CompanyContext $companyContext
    ) {}
}
```

### Complex Controller (Multiple Services)
```php
// InvoiceController pattern
class InvoiceController extends Controller
{
    public function __construct(
        private readonly CompanyContext $companyContext,
        private readonly LocationContext $locationContext,
        private readonly DocumentNumberingService $numberingService,
        private readonly DocumentPostingService $postingService,
        private readonly DeliveryNoteService $deliveryNoteService,
        private readonly TaxCalculationService $taxCalculationService,
    ) {}
}
```

### Service with Dependencies
```php
// Domain/Application services follow same pattern
class GeneralLedgerService
{
    public function __construct(
        private readonly PartnerBalanceService $partnerBalanceService
    ) {}
}
```

---

## Adding New Dependencies

### Checklist

When adding a dependency to existing code:

1. **Add to constructor** (use `private readonly`)
   ```php
   public function __construct(
       private readonly CompanyContext $companyContext,
       private readonly NewService $newService, // ✅ ADD HERE
   ) {}
   ```

2. **Remove any `app()` calls**
   ```php
   // Before
   $result = app(NewService::class)->method();

   // After
   $result = $this->newService->method();
   ```

3. **Update tests** (if they instantiate controller directly)
   ```php
   $controller = new CategoryController(
       companyContext: $this->createMock(CompanyContext::class),
       newService: $this->createMock(NewService::class),
   );
   ```

4. **Never mix patterns** - if constructor exists, add there

---

## Common Mistakes

### Mistake 1: Using app() for convenience
```php
// ❌ WRONG - "just this once"
public function someMethod()
{
    $service = app(SomeService::class); // NO!
}
```

**Fix:** Add to constructor, always.

### Mistake 2: Lazy loading heavy services
```php
// ❌ WRONG - "it's only used in one place"
public function rareMethod()
{
    $heavy = app(HeavyService::class); // NO!
}
```

**Fix:** Constructor injection. Laravel only instantiates when controller is called.

### Mistake 3: Circular dependencies
If you hit a circular dependency, it's usually a design smell.

**Solutions:**
- Extract shared logic to a third service
- Use events to decouple
- Refactor the dependency graph

**Never solve it with `app()`** - that just hides the problem.

---

## Statistics (2026-01-05)

**Before Refactor:**
- Controllers using Constructor DI: 90.6% (58/64)
- Controllers using `app()`: 7.8% (5/64)

**After Refactor:**
- Controllers using Constructor DI: **100% (64/64)** ✅
- Controllers using `app()`: **0%** ✅

---

## Pre-Commit Checklist

Before committing any controller or service:

- [ ] All dependencies injected via constructor
- [ ] No `app(ClassName::class)` calls in the file
  (run: `grep "app(" YourController.php`)
- [ ] Constructor uses `private readonly` for all parameters
- [ ] Tests updated to mock dependencies (if needed)

---

## Exceptions (Rare)

**Only acceptable uses of `app()`:**

1. **Debugging/Testing** (temporary only, remove before commit)
2. **Circular Dependency** (rare, usually indicates design issue - refactor instead)
3. **Absolutely no other option** (document why in comment)

**Example of documented exception:**
```php
// TEMPORARY: Using app() due to circular dependency between
// DocumentService and TaxService. TODO: Refactor to use events.
// Issue: #1234
$taxService = app(TaxCalculationService::class);
```

---

## Related Conventions

- [03-AUTHORIZATION.md](./03-AUTHORIZATION.md) - Required middleware includes `SetPermissionsTeam` (injected service)
- [01-API-RESPONSES.md](./01-API-RESPONSES.md) - Controllers should inject services, not use models directly

---

## Further Reading

- [Laravel Service Container](https://laravel.com/docs/11.x/container)
- [Martin Fowler on Dependency Injection](https://martinfowler.com/articles/injection.html)
- [Service Locator is an Anti-Pattern](https://blog.ploeh.dk/2010/02/03/ServiceLocatorisanAnti-Pattern/)
