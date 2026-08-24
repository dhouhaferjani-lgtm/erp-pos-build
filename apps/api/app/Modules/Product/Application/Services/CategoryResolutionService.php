<?php

declare(strict_types=1);

namespace App\Modules\Product\Application\Services;

use App\Modules\Product\Domain\Category;
use App\Shared\DTOs\CategoryResolutionDTO;
use App\Shared\Enums\CategoryResolutionOutcome;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * Resolves a free-text category name to a local category row: exact name ->
 * slug -> trashed slug holder -> create. Mirrors {@see BrandResolutionService},
 * which has always created brands on a miss; before W2-3 categories were
 * lookup-only, so a day-one tenant (empty `categories`, and no categories
 * ImportType exists — `category_name` is a PRODUCTS column, ImportType.php:125)
 * lost every value.
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
 *
 * TRANSACTION CONTRACT (gate r1 finding 1). Callers run this inside their own
 * transaction — every import row does (ImportService::importRow). On PostgreSQL
 * a statement that errors aborts the whole transaction (25P02), so a recovery
 * SELECT after a caught insert failure is DEAD CODE there. Hence: every
 * foreseeable holder of the slug (live or trashed) is settled with a SELECT
 * BEFORE any insert is attempted, and the insert itself runs in a NESTED
 * transaction so Laravel wraps it in a SAVEPOINT — a lost race then rolls back
 * to the savepoint and leaves the caller's transaction usable.
 */
final class CategoryResolutionService
{
    /** `categories.name` and `categories.slug` are both varchar(255). */
    private const MAX_LENGTH = 255;

    /**
     * @param  string  $name  Raw spreadsheet value; trimmed here. Must be non-blank.
     *
     * @throws InvalidArgumentException when the name is blank after trimming.
     */
    public function resolve(string $companyId, string $name): CategoryResolutionDTO
    {
        $trimmed = trim($name);

        if ($trimmed === '') {
            throw new InvalidArgumentException('Category name must be non-blank after trimming.');
        }

        $slug = $this->slugFor($trimmed);

        $byName = Category::where('company_id', $companyId)
            ->where('name', $trimmed)
            ->first();

        if ($byName !== null) {
            return new CategoryResolutionDTO((int) $byName->id, (string) $byName->name, CategoryResolutionOutcome::Matched);
        }

        // Accent/case/punctuation variants of a name collapse to one slug, and the
        // unique index is on the slug — matching here is what stops "Hygiene" and
        // "Hygiene" from colliding on create. Reported, never silent: the incoming
        // name differs from the row's name by definition (the exact-name arm above
        // already returned).
        $bySlug = Category::where('company_id', $companyId)
            ->where('slug', $slug)
            ->first();

        if ($bySlug !== null) {
            return new CategoryResolutionDTO((int) $bySlug->id, (string) $bySlug->name, CategoryResolutionOutcome::MatchedBySlug);
        }

        // Soft deletes do NOT free the slug: the unique index is not partial, so a
        // trashed row still owns it. Settle it here, with a SELECT, because letting
        // the INSERT fail would poison the caller's transaction on PostgreSQL.
        $trashed = Category::onlyTrashed()
            ->where('company_id', $companyId)
            ->where('slug', $slug)
            ->first();

        if ($trashed !== null) {
            $trashed->restore();

            return new CategoryResolutionDTO((int) $trashed->id, (string) $trashed->name, CategoryResolutionOutcome::Restored);
        }

        return $this->create($companyId, $trimmed, $slug);
    }

    /**
     * All that is left is a genuine race with a concurrent worker. The nested
     * transaction makes Laravel emit a SAVEPOINT, so a unique violation rolls
     * back to it rather than aborting the row transaction the caller owns.
     */
    private function create(string $companyId, string $name, string $slug): CategoryResolutionDTO
    {
        try {
            $created = DB::transaction(fn (): Category => Category::create([
                'company_id' => $companyId,
                'name' => $name,
                'slug' => $slug,
                'is_active' => true,
            ]));

            return new CategoryResolutionDTO((int) $created->id, (string) $created->name, CategoryResolutionOutcome::Created);
        } catch (QueryException $e) {
            $winner = Category::withTrashed()
                ->where('company_id', $companyId)
                ->where('slug', $slug)
                ->first();

            if ($winner === null) {
                throw $e;
            }

            if ($winner->trashed()) {
                $winner->restore();

                return new CategoryResolutionDTO((int) $winner->id, (string) $winner->name, CategoryResolutionOutcome::Restored);
            }

            return new CategoryResolutionDTO(
                (int) $winner->id,
                (string) $winner->name,
                $winner->name === $name
                    ? CategoryResolutionOutcome::Matched
                    : CategoryResolutionOutcome::MatchedBySlug,
            );
        }
    }

    /**
     * Str::slug() can LENGTHEN a name (Arabic transliterates: عطور -> aator), so
     * a 255-char name can overflow the varchar(255) slug column — clamp it.
     *
     * It returns '' only for names with nothing transliterable at all: CJK
     * (化妆品, 护肤) and pure punctuation. Arabic does NOT hit this path. An empty
     * slug would make every such category collide on unique (company_id, ''), so
     * fall back to a stable digest of the name — same name in, same slug out,
     * which keeps re-imports idempotent.
     */
    private function slugFor(string $name): string
    {
        $slug = Str::slug($name);

        if ($slug === '') {
            return 'category-'.substr(hash('sha256', $name), 0, 16);
        }

        return mb_substr($slug, 0, self::MAX_LENGTH);
    }
}
