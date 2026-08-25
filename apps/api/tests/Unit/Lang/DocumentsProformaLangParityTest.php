<?php

declare(strict_types=1);

namespace Tests\Unit\Lang;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * `lang/{en,fr,ar}/documents.php` key + placeholder parity for the strings lane
 * C-F0 owns — conventions gate F-C1.
 *
 * THE DEFECT THIS CLOSES. Every three-locale claim in `ProformaOutputTest` was
 * pinned tautologically: `assertStringContainsString(__('documents.proforma.title'),
 * $html)` resolves BOTH sides through the same Translator at the same locale.
 * Delete the `proforma` block from `lang/ar/documents.php` and Laravel's translator
 * falls back per key to `fallback_locale` (`config/app.php` → `en`), handing the
 * ENGLISH string to the blade and to the assertion alike — the test passes on a page
 * that renders English to an Arabic tenant. Delete it from `en` too and both sides
 * get the literal key back, and it passes again.
 *
 * `ProformaOutputTest::test_the_proforma_renders_the_literal_strings_of_its_locale`
 * is the other half of the fix (the fr/ar strings asserted as literals). This class
 * is the structural half, and it is the one that catches a key added to `en` and
 * forgotten in `ar` — the ordinary way a locale file rots.
 *
 * SCOPE IS DELIBERATELY THIS LANE'S SUBTREES. `lang/ar/documents.php` is missing 13
 * keys that predate C-F0 (`discount.*`, `bonus_quantity.*`, `stock.insufficient`,
 * `pre_delivery_invoicing.*`, `to_bill_queue.*`, `guided_delivery.*`). Asserting
 * whole-file parity would fail on inherited debt and would have to be baselined,
 * which is how a guard becomes decoration. These two subtrees are complete in all
 * three files today, so they are pinned complete.
 *
 * No database, no application boot — this is a Unit test on purpose, so it runs in
 * the `backend-test` job's `--testsuite=Unit` on every CI event, unlike the parked
 * Feature lane.
 */
final class DocumentsProformaLangParityTest extends TestCase
{
    private const LOCALES = ['en', 'fr', 'ar'];

    /**
     * @return array<string, array{0: string}>
     */
    public static function subtreeProvider(): array
    {
        return ['proforma' => ['proforma'], 'credit_note' => ['credit_note']];
    }

    #[DataProvider('subtreeProvider')]
    public function test_every_locale_carries_the_same_keys(string $subtree): void
    {
        $reference = array_keys($this->subtree('en', $subtree));
        sort($reference);

        $this->assertNotSame([], $reference, "documents.{$subtree} is empty in en");

        foreach (self::LOCALES as $locale) {
            $keys = array_keys($this->subtree($locale, $subtree));
            sort($keys);

            $this->assertSame(
                $reference,
                $keys,
                "documents.{$subtree} keys differ in {$locale}: a missing key silently falls back to English",
            );
        }
    }

    #[DataProvider('subtreeProvider')]
    public function test_every_locale_carries_the_same_placeholders(string $subtree): void
    {
        foreach (array_keys($this->subtree('en', $subtree)) as $key) {
            $reference = $this->placeholders($this->subtree('en', $subtree)[$key]);

            foreach (self::LOCALES as $locale) {
                $this->assertSame(
                    $reference,
                    $this->placeholders($this->subtree($locale, $subtree)[$key] ?? ''),
                    "documents.{$subtree}.{$key} has different :placeholders in {$locale}",
                );
            }
        }
    }

    /**
     * Every string is non-empty and is not an accidental copy of the English one.
     * `fr` and `ar` sharing a value would mean a translation was pasted, not made —
     * with the deliberate exception of a value that has no words in it.
     */
    #[DataProvider('subtreeProvider')]
    public function test_no_locale_silently_ships_the_english_string(string $subtree): void
    {
        $english = $this->subtree('en', $subtree);

        foreach (['fr', 'ar'] as $locale) {
            foreach ($this->subtree($locale, $subtree) as $key => $value) {
                $this->assertNotSame('', trim($value), "documents.{$subtree}.{$key} is empty in {$locale}");
                $this->assertNotSame(
                    $english[$key],
                    $value,
                    "documents.{$subtree}.{$key} in {$locale} is the English string verbatim",
                );
            }
        }
    }

    /**
     * @return array<string, string>
     */
    private function subtree(string $locale, string $subtree): array
    {
        $path = dirname(__DIR__, 3)."/lang/{$locale}/documents.php";
        $this->assertFileExists($path);

        /** @var array<string, mixed> $all */
        $all = require $path;

        /** @var array<string, string> $block */
        $block = is_array($all[$subtree] ?? null) ? $all[$subtree] : [];

        return $block;
    }

    /**
     * @return list<string>
     */
    private function placeholders(string $value): array
    {
        preg_match_all('/:[a-zA-Z_][a-zA-Z0-9_]*/', $value, $matches);

        $found = array_unique($matches[0]);
        sort($found);

        return $found;
    }
}
