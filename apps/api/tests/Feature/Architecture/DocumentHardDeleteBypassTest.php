<?php

declare(strict_types=1);

namespace Tests\Feature\Architecture;

use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

/**
 * Ratchet: no source file under app/Modules may contain a raw query-builder
 * hard-delete on the `documents` table that would bypass the
 * DocumentMediaCascadeObserver.
 *
 * Patterns that bypass the Eloquent observer:
 *   - DB::table('documents')  …  ->delete()       (mass-delete via QB)
 *   - DB::table('documents')  …  ->forceDelete()  (if someone adds such a call)
 *   - ->where(…)->forceDelete() called on a raw Builder (not a Model instance)
 *
 * When the observer fires (Model::forceDelete()) no scan violation occurs because
 * the observer call path does not contain the literals below.
 *
 * If you add a legitimate bypass in the future (e.g. a migration or seeder that
 * bulk-prunes test data), add the relative path to ALLOWED_BYPASS_FILES and
 * include a comment explaining why MediaServiceInterface::purgeOwner is called
 * explicitly or why media purge is not required.
 */
final class DocumentHardDeleteBypassTest extends TestCase
{
    /**
     * Regex patterns that indicate a query-builder hard-delete on the documents
     * table, bypassing the Eloquent observer.
     *
     * Only flag DELETE operations — not SELECTs, COUNTs, or other reads.
     * Patterns match `DB::table('documents') … ->delete()` within 500 chars
     * (covers chained wheres on a single statement), using the DOTALL flag.
     *
     * Legitimate read-only usages of DB::table('documents') (count, select, etc.)
     * do NOT match because they never call ->delete() or ->forceDelete().
     *
     * @var list<non-empty-string>
     */
    private const BYPASS_PATTERNS = [
        // DB::table('documents')…->delete() within ~500 chars (DOTALL)
        "/DB::table\(['\"]documents['\"]\).{0,500}->delete\(\)/s",
        // DB::table('documents')…->forceDelete() within ~500 chars (DOTALL)
        "/DB::table\(['\"]documents['\"]\).{0,500}->forceDelete\(\)/s",
        // DB::statement / DB::unprepared with a raw DELETE … FROM documents
        "/DB::(statement|unprepared)\(['\"][^'\"]*DELETE[^'\"]*FROM\s+documents/i",
    ];

    /**
     * Any PHP file under app/Modules that matches BOTH a bypass pattern AND
     * is not in the allow-list should fail this test.
     */
    public function test_no_raw_query_builder_hard_delete_bypasses_observer(): void
    {
        $appBase = dirname(__DIR__, 3).'/app';
        $modulesDir = $appBase.'/Modules';

        if (! is_dir($modulesDir)) {
            self::markTestSkipped('app/Modules directory not found — skipping bypass scan.');
        }

        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($modulesDir, RecursiveDirectoryIterator::SKIP_DOTS),
        );

        $violations = [];

        /** @var SplFileInfo $file */
        foreach ($iterator as $file) {
            if ($file->getExtension() !== 'php') {
                continue;
            }

            $content = file_get_contents($file->getPathname());
            if ($content === false) {
                continue;
            }

            // Skip files that don't reference 'documents' at all — fast path
            if (! str_contains($content, 'documents')) {
                continue;
            }

            $relativePath = ltrim(str_replace($modulesDir, '', $file->getPathname()), '/');

            // If a legitimate hard-delete on `documents` is ever added (e.g. in a migration
            // or seeder), add an explicit check here with that file's relative path and
            // document why MediaServiceInterface::purgeOwner is called explicitly.

            foreach (self::BYPASS_PATTERNS as $pattern) {
                if ((bool) preg_match($pattern, $content)) {
                    $violations[] = sprintf(
                        '%s — matches bypass pattern: %s',
                        $relativePath,
                        $pattern,
                    );
                    break; // one violation per file is enough
                }
            }
        }

        self::assertEmpty(
            $violations,
            "Raw query-builder hard-delete bypass(es) detected — these will orphan Document media.\n"
            ."Add to ALLOWED_BYPASS_FILES with a comment if the bypass is intentional:\n"
            .implode("\n", $violations),
        );
    }
}
