Create a new backend module named "$ARGUMENTS" following AutoERP's hexagonal architecture.

## Steps

1. **Create directory structure** under `apps/api/app/Modules/{ModuleName}/`:
   - `Domain/Entities/` (or models at `Domain/` root — e.g., `Domain/Payment.php`)
   - `Domain/ValueObjects/`
   - `Domain/Events/`
   - `Domain/Services/`
   - `Domain/Enums/`
   - `Domain/Repositories/` (interfaces only)
   - `Application/DTOs/`
   - `Application/Services/`
   - `Infrastructure/Repositories/` (Eloquent repository implementations)
   - `Presentation/Controllers/`
   - `Presentation/Requests/`
   - `Presentation/Resources/`
   - `Providers/`

2. **Create ServiceProvider** at `Providers/{ModuleName}ServiceProvider.php`:
   - Bind repository interfaces to Eloquent implementations
   - Register in `apps/api/bootstrap/providers.php`

3. **Create routes.php** with the EXACT middleware pattern:
   ```php
   use App\Modules\Identity\Presentation\Middleware\SetPermissionsTeam;
   use Illuminate\Support\Facades\Route;

   Route::prefix('api/v1')->middleware(['api', 'auth:sanctum', SetPermissionsTeam::class])->group(function () {
       // CRUD routes here
   });
   ```

4. **Create Controller** with constructor injection:
   ```php
   public function __construct(
       private readonly CompanyContext $companyContext,
       private readonly YourService $service,
   ) {}
   ```

5. **Create FormRequest** classes with `authorize()` using Spatie permissions and `rules()`.

6. **Create DTOs** with `#[TypeScript]` attribute for type generation (primary pattern). Use JsonResource classes for response formatting when needed.
   ```php
   use Spatie\TypeScriptTransformer\Attributes\TypeScript;

   #[TypeScript]
   class YourEntityData extends Data
   {
       public function __construct(
           public readonly int $id,
           public readonly string $name,
           // ...
       ) {}
   }
   ```

7. **Add permissions** to `database/seeders/RolesAndPermissionsSeeder.php`:
   - Standard CRUD: `{resource}.view`, `{resource}.create`, `{resource}.update`, `{resource}.delete`
   - Domain-specific actions as needed: `{resource}.post`, `{resource}.cancel`, `{resource}.void`, `{resource}.manage`

## Constraints

- All dependencies via constructor injection (never `app()` helper)
- No `mixed` types — use DTOs for JSONB columns
- Enums for all status/type columns
- Domain layer has zero infrastructure dependencies
- Read `.claude/context/architecture.md` for full architecture reference
