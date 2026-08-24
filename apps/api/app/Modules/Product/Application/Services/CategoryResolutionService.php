<?php

declare(strict_types=1);

namespace App\Modules\Product\Application\Services;

use App\Modules\Product\Domain\Category;
use App\Shared\DTOs\CategoryResolutionDTO;
use App\Shared\Enums\CategoryResolutionOutcome;
use Illuminate\Database\QueryException;
use Illuminate\Support\Str;

/**
 * Resolves a free-text category name to a local category row: exact name ->
 * slug -> create. Mirrors {@see BrandResolutionService}, which has always
 * created brands on a miss; before W2-3 categories were lookup-only, so a
 * day-one tenant (empty `categories`, and no categories ImportType exists —
 * `category_name` is a PRODUCTS column, ImportType.php:125) lost every value.
 *
 * Categories are COMPANY-scoped only, never vertical-scoped: the table carries
 * `company_id` + unique (company_id, slug) and no vertical column
 * (2025_12_26_194624_create_categories_table.php:14-16,38), and config/verticals.php
 * declares no category scoping. The vertical-gated "Parapharmacy -> Product
 * Category" field is a DIFFERENT thing (ParapharmacyProductMetadata.category,
 * an enum behind `module:Parapharmacy`, Product/routes.php:89) and is untouched
 * here.
 *
 * Idempotent by construction: re-importing the same file matches and never
 * duplicates.
 */
final class CategoryResolutionService
{
    /**
     * @param  string  $name  Raw spreadsheet value; trimmed here.
     */
    public function resolve(string $companyId, string $name): CategoryResolutionDTO
    {
        $trimmed = trim($name);
        $slug = $this->slugFor($trimmed);

        $existing = $this->findLive($companyId, $trimmed, $slug);
        if ($existing !== null) {
            return new CategoryResolutionDTO((int) $existing->id, CategoryResolutionOutcome::Matched);
        }

        try {
            $created = Category::create([
                'company_id' => $companyId,
                'name' => $trimmed,
                'slug' => $slug,
                'is_active' => true,
            ]);

            return new CategoryResolutionDTO((int) $created->id, CategoryResolutionOutcome::Created);
        } catch (QueryException $e) {
            // unique (company_id, slug) lost a race, OR the slug is held by a
            // SOFT-DELETED row (soft deletes do not free the index). Both end in
            // the same place: find the holder and take it.
            $holder = Category::withTrashed()
                ->where('company_id', $companyId)
                ->where('slug', $slug)
                ->first();

            if ($holder === null) {
                throw $e;
            }

            if ($holder->trashed()) {
                $holder->restore();

                return new CategoryResolutionDTO((int) $holder->id, CategoryResolutionOutcome::Restored);
            }

            return new CategoryResolutionDTO((int) $holder->id, CategoryResolutionOutcome::Matched);
        }
    }

    private function findLive(string $companyId, string $name, string $slug): ?Category
    {
        $byName = Category::where('company_id', $companyId)
            ->where('name', $name)
            ->first();

        if ($byName !== null) {
            return $byName;
        }

        // Accent/case variants of the same name collapse to one slug, and the
        // unique index is on the slug — matching here is what stops "Hygiene"
        // and "hygiene" from colliding on create.
        return Category::where('company_id', $companyId)
            ->where('slug', $slug)
            ->first();
    }

    /**
     * Str::slug() returns '' for names with no latin-transliterable characters
     * (pure Arabic, CJK, punctuation). An empty slug would make every such
     * category collide on unique (company_id, ''), so fall back to a stable
     * digest of the name — same name in, same slug out, which keeps re-imports
     * idempotent.
     */
    private function slugFor(string $name): string
    {
        $slug = Str::slug($name);

        return $slug !== '' ? $slug : 'category-'.substr(hash('sha256', $name), 0, 16);
    }
}
