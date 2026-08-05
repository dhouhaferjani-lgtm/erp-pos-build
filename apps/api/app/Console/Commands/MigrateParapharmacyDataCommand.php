<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Console\TenantScopedCommand;
use App\Modules\Company\Services\CompanyContext;
use App\Modules\Product\Domain\Certification;
use App\Modules\Product\Domain\HealthClaim;
use App\Modules\Product\Domain\Ingredient;
use App\Modules\Product\Domain\KeyComponent;
use App\Modules\Product\Domain\ParapharmacyProductMetadata;
use App\Modules\Product\Domain\Product;
use App\Modules\Tenant\Domain\Tenant;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Tenant-isolation: cat-(a-per-tenant-iter) behind an EXPLICIT scope,
 * converted 2026-08-05 (cat-(b) wave 2).
 *
 * The annotation said the one-shot migration "walks the entire
 * ParapharmacyProductMetadata table". That table and every pivot it writes
 * (`product_ingredient`, `key_component_product`, `health_claim_product`,
 * `certification_product` and their `*_translations`) are TENANT tables, so
 * after the 2026-05-28 database-per-tenant flip the walk raised 42P01 on the
 * console's CENTRAL connection and migrated nothing.
 *
 * A JSONB→pivot migration that silently skips a tenant leaves that tenant's
 * parapharmacy data permanently un-normalized, so the scope must be named:
 * `--tenant=<uuid>` or `--all-tenants`.
 *
 * `parapharmacy_product_metadata` carries no `tenant_id` of its own — it
 * anchors on `product_id` — so the compatibility-mode predicate is a subquery
 * against the tenant's products. `--limit` is a PER-TENANT cap on the number of
 * metadata rows processed, which is the only reading that survives iteration.
 *
 * **The dictionary tables are NOT tenant-scopable (M7, 2026-08-05 wave-2
 * tenancy review).** `ingredients`, `key_components`, `health_claims`,
 * `certifications` and their `*_translations` carry no `tenant_id` column at
 * all (`database/migrations/tenant/2026_01_08_*`), so the `findOrCreate*`
 * helpers below read and write a database-wide dictionary. Under
 * database-per-tenant that database IS the tenant's, so the dictionary is
 * correctly per-tenant. In single-schema compatibility mode it is shared
 * fleet-wide and cannot be scoped without a schema change — the
 * compatibility-mode predicate above covers the metadata SELECTION only, never
 * the dictionary. Pre-existing; recorded so the claim is not read wider than
 * it is.
 *
 * The normalization LOGIC is untouched.
 */
final class MigrateParapharmacyDataCommand extends TenantScopedCommand
{
    protected $signature = 'parapharmacy:migrate-data
                          {--tenant= : Tenant UUID to migrate (required unless --all-tenants)}
                          {--all-tenants : Deliberate fleet-wide run over every reachable tenant}
                          {--dry-run : Run without making changes}
                          {--limit= : Limit number of products to migrate per tenant}';

    protected $description = 'Migrate parapharmacy JSONB data to normalized tables (per tenant)';

    private int $migratedCount = 0;

    private int $skippedCount = 0;

    private int $errorCount = 0;

    public function __construct(CompanyContext $companyContext)
    {
        parent::__construct($companyContext);
    }

    protected function executeCommand(): int
    {
        $dryRun = $this->option('dry-run') === true;
        $limitOption = $this->option('limit');
        $limit = is_string($limitOption) && $limitOption !== '' ? (int) $limitOption : null;

        $this->info('Starting parapharmacy data migration...');
        if ($dryRun) {
            $this->warn('DRY RUN MODE - No changes will be saved');
        }

        $exit = $this->forEachExplicitlySelectedTenant(
            $this->stringOption('tenant'),
            $this->option('all-tenants') === true,
            function (Tenant $tenant) use ($dryRun, $limit): int {
                // The metadata table has no tenant_id; it anchors on product_id.
                // Redundant under database-per-tenant, load-bearing in
                // single-schema compatibility mode.
                $query = ParapharmacyProductMetadata::query()
                    ->whereIn(
                        'product_id',
                        Product::query()->select('id')->where('tenant_id', $tenant->id),
                    )
                    ->where(fn ($inner) => $inner
                        ->whereNotNull('active_ingredients')
                        ->orWhereNotNull('key_components')
                        ->orWhereNotNull('health_claims')
                        ->orWhereNotNull('certifications'))
                    ->orderBy('product_id');

                // M1 (2026-08-05 wave-2 tenancy review): `--limit` was inert.
                // `->limit($n)` does not cap `count()` — SQL LIMIT on an
                // aggregate still returns the full count — and `chunk()`
                // overwrites limit/offset via `forPage()`, so the whole tenant
                // was processed whatever the operator asked for. The cap is now
                // applied where it can actually hold: on the number of rows the
                // chunk callback processes, with the reported total clamped to
                // match.
                $available = $query->count();
                $total = $limit !== null ? min($limit, $available) : $available;
                $this->info(sprintf(
                    'TENANT %s (%s): found %d product(s) with JSONB data to migrate',
                    $tenant->id,
                    $tenant->slug,
                    $total,
                ));

                $tenantExit = self::SUCCESS;
                $processed = 0;

                $query->chunk(100, function ($metadataRecords) use ($dryRun, $limit, &$tenantExit, &$processed) {
                    foreach ($metadataRecords as $metadata) {
                        if ($limit !== null && $processed >= $limit) {
                            return false;
                        }

                        $processed++;

                        try {
                            $this->migrateProduct($metadata, $dryRun);
                            $this->migratedCount++;
                        } catch (\Exception $e) {
                            $this->error("Error migrating product {$metadata->product_id}: {$e->getMessage()}");
                            $this->errorCount++;
                            $tenantExit = self::FAILURE;
                        }
                    }

                    return true;
                });

                return $tenantExit;
            },
        );

        $this->newLine();
        $this->info('Migration Summary:');
        $this->table(
            ['Status', 'Count'],
            [
                ['Migrated', $this->migratedCount],
                ['Skipped', $this->skippedCount],
                ['Errors', $this->errorCount],
            ]
        );

        if ($exit !== self::SUCCESS) {
            return $exit;
        }

        return $this->errorCount > 0 ? self::FAILURE : self::SUCCESS;
    }

    /**
     * M3 (2026-08-05 wave-2 tenancy review): `--dry-run` used to roll back by
     * THROWING inside `DB::transaction()`. The caller catches `\Exception` and
     * counts it, so a perfectly healthy dry run printed
     * "Error migrating product … Dry run - rolling back transaction" once per
     * row, incremented `errorCount` once per row, and — since the wave's
     * conversion — set that tenant's exit to FAILURE. The rollback is now
     * explicit: a dry run is the DESIGNED outcome, not an error.
     */
    private function migrateProduct(ParapharmacyProductMetadata $metadata, bool $dryRun): void
    {
        DB::beginTransaction();

        try {
            // Migrate active ingredients
            if ($metadata->active_ingredients && is_array($metadata->active_ingredients)) {
                $this->migrateIngredients($metadata, $metadata->active_ingredients, $dryRun);
            }

            // Migrate key components
            if ($metadata->key_components && is_array($metadata->key_components)) {
                $this->migrateKeyComponents($metadata, $metadata->key_components, $dryRun);
            }

            // Migrate health claims
            if ($metadata->health_claims && is_array($metadata->health_claims)) {
                $this->migrateHealthClaims($metadata, $metadata->health_claims, $dryRun);
            }

            // Migrate certifications
            if ($metadata->certifications && is_array($metadata->certifications)) {
                $this->migrateCertifications($metadata, $metadata->certifications, $dryRun);
            }
        } catch (\Throwable $e) {
            DB::rollBack();

            throw $e;
        }

        if ($dryRun) {
            DB::rollBack();

            return;
        }

        DB::commit();
    }

    private function migrateIngredients(ParapharmacyProductMetadata $metadata, array $ingredients, bool $dryRun): void
    {
        foreach ($ingredients as $index => $ingredientData) {
            $name = $ingredientData['name'] ?? null;
            $concentration = $ingredientData['concentration'] ?? null;

            if (! $name) {
                $this->warn("Skipping ingredient without name for product {$metadata->product_id}");

                continue;
            }

            // Try to find existing ingredient by name (case-insensitive)
            $ingredient = $this->findOrCreateIngredient($name, $dryRun);

            if (! $ingredient) {
                continue;
            }

            // Parse concentration
            [$concentrationNumeric, $concentrationUnit] = $this->parseConcentration($concentration);

            // Create pivot entry
            if (! $dryRun) {
                DB::table('product_ingredient')->updateOrInsert(
                    [
                        'product_id' => $metadata->product_id,
                        'ingredient_id' => $ingredient->id,
                    ],
                    [
                        'concentration' => $concentration,
                        'concentration_numeric' => $concentrationNumeric,
                        'concentration_unit' => $concentrationUnit,
                        'order' => $index,
                        'notes' => null,
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]
                );
            }

            $this->line("  → Migrated ingredient: {$name}");
        }
    }

    private function migrateKeyComponents(ParapharmacyProductMetadata $metadata, array $components, bool $dryRun): void
    {
        foreach ($components as $index => $componentName) {
            if (! is_string($componentName) || empty($componentName)) {
                continue;
            }

            // Try to find existing component by name
            $component = $this->findOrCreateKeyComponent($componentName, $dryRun);

            if (! $component) {
                continue;
            }

            // Create pivot entry
            if (! $dryRun) {
                DB::table('key_component_product')->updateOrInsert(
                    [
                        'product_id' => $metadata->product_id,
                        'component_id' => $component->id,
                    ],
                    [
                        'order' => $index,
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]
                );
            }

            $this->line("  → Migrated key component: {$componentName}");
        }
    }

    private function migrateHealthClaims(ParapharmacyProductMetadata $metadata, array $claims, bool $dryRun): void
    {
        foreach ($claims as $index => $claimText) {
            if (! is_string($claimText) || empty($claimText)) {
                continue;
            }

            // Try to find existing health claim by text
            $healthClaim = $this->findOrCreateHealthClaim($claimText, $dryRun);

            if (! $healthClaim) {
                continue;
            }

            // Create pivot entry
            if (! $dryRun) {
                DB::table('health_claim_product')->updateOrInsert(
                    [
                        'product_id' => $metadata->product_id,
                        'health_claim_id' => $healthClaim->id,
                    ],
                    [
                        'display_order' => $index,
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]
                );
            }

            $this->line("  → Migrated health claim: {$claimText}");
        }
    }

    private function migrateCertifications(ParapharmacyProductMetadata $metadata, array $certifications, bool $dryRun): void
    {
        foreach ($certifications as $certData) {
            $type = $certData['type'] ?? null;
            $code = $certData['code'] ?? null;

            if (! $type) {
                continue;
            }

            // Try to find existing certification by type
            $certification = $this->findOrCreateCertification($type, $dryRun);

            if (! $certification) {
                continue;
            }

            // Create pivot entry
            if (! $dryRun) {
                DB::table('certification_product')->updateOrInsert(
                    [
                        'product_id' => $metadata->product_id,
                        'certification_id' => $certification->id,
                    ],
                    [
                        'certification_code' => $code,
                        'issued_date' => null,
                        'expiry_date' => null,
                        'verification_url' => null,
                        'notes' => null,
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]
                );
            }

            $this->line("  → Migrated certification: {$type}");
        }
    }

    private function findOrCreateIngredient(string $name, bool $dryRun): ?Ingredient
    {
        // Try to find by translation first
        $translation = DB::table('ingredient_translations')
            ->whereRaw('LOWER(name) = ?', [strtolower($name)])
            ->first();

        if ($translation) {
            return Ingredient::find($translation->ingredient_id);
        }

        // Create new ingredient if not found (only if not dry run)
        if ($dryRun) {
            $this->line("  [DRY RUN] Would create ingredient: {$name}");

            return null;
        }

        $slug = Str::slug($name);

        // Check if slug already exists, make it unique if needed
        $counter = 1;
        $originalSlug = $slug;
        while (Ingredient::where('slug', $slug)->exists()) {
            $slug = "{$originalSlug}-{$counter}";
            $counter++;
        }

        $ingredient = Ingredient::create([
            'slug' => $slug,
            'cas_number' => null,
            'is_allergen' => false,
            'allergen_code' => null,
            'regulatory_status' => 'approved',
            'notes' => 'Auto-migrated from JSONB data',
        ]);

        // Create English translation
        DB::table('ingredient_translations')->insert([
            'id' => Str::uuid()->toString(),
            'ingredient_id' => $ingredient->id,
            'locale' => 'en',
            'name' => $name,
            'description' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->line("  [CREATED] New ingredient: {$name}");

        return $ingredient;
    }

    private function findOrCreateKeyComponent(string $name, bool $dryRun): ?KeyComponent
    {
        // Try to find by translation first
        $translation = DB::table('key_component_translations')
            ->whereRaw('LOWER(name) = ?', [strtolower($name)])
            ->first();

        if ($translation) {
            return KeyComponent::find($translation->component_id);
        }

        // Create new component if not found (only if not dry run)
        if ($dryRun) {
            $this->line("  [DRY RUN] Would create key component: {$name}");

            return null;
        }

        $slug = Str::slug($name);

        // Check if slug already exists, make it unique if needed
        $counter = 1;
        $originalSlug = $slug;
        while (KeyComponent::where('slug', $slug)->exists()) {
            $slug = "{$originalSlug}-{$counter}";
            $counter++;
        }

        $component = KeyComponent::create([
            'slug' => $slug,
            'is_allergen' => false,
        ]);

        // Create English translation
        DB::table('key_component_translations')->insert([
            'id' => Str::uuid()->toString(),
            'component_id' => $component->id,
            'locale' => 'en',
            'name' => $name,
            'description' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->line("  [CREATED] New key component: {$name}");

        return $component;
    }

    private function findOrCreateHealthClaim(string $claimText, bool $dryRun): ?HealthClaim
    {
        // Try to find by translation first
        $translation = DB::table('health_claim_translations')
            ->whereRaw('LOWER(claim) = ?', [strtolower($claimText)])
            ->first();

        if ($translation) {
            return HealthClaim::find($translation->health_claim_id);
        }

        // Create new health claim if not found (only if not dry run)
        if ($dryRun) {
            $this->line("  [DRY RUN] Would create health claim: {$claimText}");

            return null;
        }

        $slug = Str::slug(Str::limit($claimText, 100));

        // Check if slug already exists, make it unique if needed
        $counter = 1;
        $originalSlug = $slug;
        while (HealthClaim::where('slug', $slug)->exists()) {
            $slug = "{$originalSlug}-{$counter}";
            $counter++;
        }

        $healthClaim = HealthClaim::create([
            'claim_type' => 'function',
            'slug' => $slug,
            'regulatory_status' => 'approved',
            'efsa_reference' => null,
            'fda_reference' => null,
            'country_restrictions' => null,
            'requires_disclaimer' => false,
        ]);

        // Create English translation
        DB::table('health_claim_translations')->insert([
            'id' => Str::uuid()->toString(),
            'health_claim_id' => $healthClaim->id,
            'locale' => 'en',
            'claim' => $claimText,
            'disclaimer_text' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->line("  [CREATED] New health claim: {$claimText}");

        return $healthClaim;
    }

    private function findOrCreateCertification(string $type, bool $dryRun): ?Certification
    {
        // Try to find by slug (type converted to slug)
        $slug = Str::slug($type);
        $certification = Certification::where('slug', $slug)->first();

        if ($certification) {
            return $certification;
        }

        // Create new certification if not found (only if not dry run)
        if ($dryRun) {
            $this->line("  [DRY RUN] Would create certification: {$type}");

            return null;
        }

        // Check if slug already exists, make it unique if needed
        $counter = 1;
        $originalSlug = $slug;
        while (Certification::where('slug', $slug)->exists()) {
            $slug = "{$originalSlug}-{$counter}";
            $counter++;
        }

        $certification = Certification::create([
            'type' => $type,
            'slug' => $slug,
            'certifying_body' => null,
            'logo_url' => null,
            'verification_url' => null,
            'is_active' => true,
            'display_order' => 0,
        ]);

        // Create English translation
        DB::table('certification_translations')->insert([
            'id' => Str::uuid()->toString(),
            'certification_id' => $certification->id,
            'locale' => 'en',
            'name' => ucwords(str_replace('-', ' ', $type)),
            'description' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->line("  [CREATED] New certification: {$type}");

        return $certification;
    }

    /**
     * Parse concentration string into numeric value and unit.
     *
     * @return array{0: float|null, 1: string|null}
     */
    private function parseConcentration(?string $concentration): array
    {
        if (! $concentration) {
            return [null, null];
        }

        // Try to extract numeric part and unit
        if (preg_match('/^(\d+(?:\.\d+)?)\s*([a-zA-Z%]+)$/', $concentration, $matches)) {
            return [(float) $matches[1], strtolower($matches[2])];
        }

        return [null, null];
    }
}
