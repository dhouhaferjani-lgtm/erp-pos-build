<?php

declare(strict_types=1);

namespace Tests\Feature\Fiscal;

use PHPUnit\Framework\TestCase;

/**
 * D16 grep guard for `PosCoreReceiptProjection` — sale-time snapshot
 * invariant per synthesis v5 §5.
 *
 * The projector reads buyer / customer / contact / B2B / accounting data
 * EXCLUSIVELY from the parsed `fiscal_events.payload` (sale-time snapshot)
 * — NEVER from the runtime customer/contact/B2B/accounting modules. This
 * locks the property "projection survives downstream deletion": if the
 * customer row is later deleted, the sealed payload remains authoritative,
 * and re-running the projector hits no missing-FK errors.
 *
 * **Test-time check**: read PosCoreReceiptProjection.php as text and
 * forbid the explicit pattern set. Source-level guards are more durable
 * than runtime checks here because the failure mode they prevent is at
 * import time, not invocation time.
 *
 * **Pass 2A.PHP.2 closure (Opus P3).** PHP.2 introduces the
 * `PaymentMethodResolver` interface in `App\Shared\Contracts\Fiscal\` so
 * the projector never imports `App\Modules\Treasury\` directly. Treasury
 * is therefore RE-ADDED to the forbidden-pattern set (it was excluded in
 * PHP.1 because the projector still needed `Treasury\Domain\PaymentMethod`
 * for the FK lookup). PHP.2 also widens the Eloquent static-call surface
 * to cover model-traversal patterns the import-line check alone would
 * miss (e.g. `Customer::find()` in projector code without a top-level
 * `use App\Modules\Customer\Domain\Customer` because the use is somewhere
 * else, or a fully-qualified call). The static-call regexes match the
 * `::` operator on capitalized identifiers we never want to see in the
 * projector body.
 */
final class PosCoreReceiptProjectionD16Test extends TestCase
{
    private const PROJECTION_FILE = __DIR__.'/../../../app/Modules/POS/Application/Projections/PosCoreReceiptProjection.php';

    /**
     * Forbidden patterns — synthesis v5 §5 PLUS Treasury (Pass 2A.PHP.2 — the
     * `PaymentMethodResolver` seam in `App\Shared\Contracts\Fiscal\` closes
     * the dispatch §0 Gap A, so the projector no longer imports Treasury
     * directly).
     *
     * @return list<array{name: string, pattern: string, rationale: string}>
     */
    private function forbiddenPatterns(): array
    {
        return [
            // Direct module imports — synthesis v5 §5.
            ['name' => 'customer-module-direct', 'pattern' => '/\\buse\\s+App\\\\Modules\\\\Customer\\\\/', 'rationale' => 'D16: buyer snapshot in payload; no live customer lookup'],
            ['name' => 'contact-module-direct', 'pattern' => '/\\buse\\s+App\\\\Modules\\\\Contact\\\\/', 'rationale' => 'D16: buyer snapshot in payload; no live contact lookup'],
            ['name' => 'b2b-module-direct', 'pattern' => '/\\buse\\s+App\\\\Modules\\\\B2B\\\\/', 'rationale' => 'D16: B2B buyer data is sale-time snapshot'],
            ['name' => 'accounting-module-direct', 'pattern' => '/\\buse\\s+App\\\\Modules\\\\Accounting\\\\/', 'rationale' => 'D16: accounting is downstream consumer, not upstream source'],
            // Pass 2A.PHP.2 — Treasury back in forbidden patterns. The
            // PaymentMethodResolver seam in App\Shared\Contracts\Fiscal\
            // is the projector's ONLY entry point to Treasury reference
            // data; direct module imports are forbidden.
            ['name' => 'treasury-module-direct', 'pattern' => '/\\buse\\s+App\\\\Modules\\\\Treasury\\\\/', 'rationale' => 'D16 + Pass 2A.PHP.2: Treasury seam is App\\Shared\\Contracts\\Fiscal\\PaymentMethodResolver; no direct Treasury imports'],
            // Indirect coupling via contract.
            ['name' => 'customer-contract-indirect', 'pattern' => '/\\buse\\s+App\\\\Shared\\\\Contracts\\\\Customer\\\\/', 'rationale' => 'D16: indirect coupling via contract is still coupling'],
            ['name' => 'contact-contract-indirect', 'pattern' => '/\\buse\\s+App\\\\Shared\\\\Contracts\\\\Contact\\\\/', 'rationale' => 'D16: indirect coupling via contract is still coupling'],
            ['name' => 'b2b-contract-indirect', 'pattern' => '/\\buse\\s+App\\\\Shared\\\\Contracts\\\\B2B\\\\/', 'rationale' => 'D16: indirect coupling via contract is still coupling'],
            ['name' => 'accounting-contract-indirect', 'pattern' => '/\\buse\\s+App\\\\Shared\\\\Contracts\\\\Accounting\\\\/', 'rationale' => 'D16: indirect coupling via contract is still coupling'],
            // Pass 2A.PHP.2 R2 — Codex P1-2 closure. The Treasury seam for
            // the projector is `App\Shared\Contracts\Fiscal\PaymentMethodResolver`
            // (Fiscal namespace, not Treasury). Imports under
            // `App\Shared\Contracts\Treasury\` would route the projector
            // back through Treasury-owned contracts — same coupling defect
            // class as `use App\Modules\Treasury\` and forbidden by the
            // same D16 invariant.
            ['name' => 'treasury-contract-indirect', 'pattern' => '/\\buse\\s+App\\\\Shared\\\\Contracts\\\\Treasury\\\\/', 'rationale' => 'D16 + Pass 2A.PHP.2 R2: Treasury seam is App\\Shared\\Contracts\\Fiscal\\PaymentMethodResolver — never Shared\\Contracts\\Treasury'],
            // Pass 2A.PHP.2 — Eloquent static-call surface (Opus P3 expansion).
            // Catches model-traversal patterns that an import-only check
            // would miss (fully-qualified static calls, or imports hidden
            // elsewhere in the file).
            ['name' => 'customer-static-call', 'pattern' => '/\\bCustomer::/', 'rationale' => 'D16: Customer Eloquent traversal forbidden — buyer is sealed snapshot'],
            ['name' => 'contact-static-call', 'pattern' => '/\\bContact::/', 'rationale' => 'D16: Contact Eloquent traversal forbidden — buyer is sealed snapshot'],
            ['name' => 'b2b-static-call', 'pattern' => '/\\bB2B::/', 'rationale' => 'D16: B2B Eloquent traversal forbidden — buyer is sealed snapshot'],
            ['name' => 'treasury-payment-static-call', 'pattern' => '/\\bTreasuryPayment::/', 'rationale' => 'D16 + Pass 2A.PHP.2: Treasury operational model traversal forbidden'],
            // CLAUDE.md rule 13 — constructor injection only.
            ['name' => 'app-helper-container-resolved', 'pattern' => '/\\bapp\\s*\\(/', 'rationale' => 'CLAUDE.md rule 13: constructor injection only'],
            ['name' => 'app-make-container-resolved', 'pattern' => '/\\bApp::make\\s*\\(/', 'rationale' => 'CLAUDE.md rule 13: constructor injection only'],
            // Latent false-positive fix (found by the v3-refund-chain
            // review-round-2 regression sweep, unrelated to that round's
            // own changes): the bare pattern also matched legitimate
            // constructor-injected service calls like
            // `$this->restockPolicyResolver->resolve($productId)` (added
            // by the disposition-aware-restock feature) — a METHOD call on
            // a DI'd dependency, not Laravel's global `resolve()` helper
            // this guard exists to forbid. Negative lookbehind excludes
            // `->resolve(`/`::resolve(` (member/static calls) while still
            // catching the bare global-function-call form.
            ['name' => 'resolve-container-resolved', 'pattern' => '/(?<!->)(?<!::)\\bresolve\\s*\\(/', 'rationale' => 'CLAUDE.md rule 13: constructor injection only'],
        ];
    }

    public function test_projection_file_exists(): void
    {
        self::assertFileExists(self::PROJECTION_FILE);
    }

    public function test_pos_core_receipt_projection_does_not_import_forbidden_modules_or_helpers(): void
    {
        $source = file_get_contents(self::PROJECTION_FILE);
        self::assertNotFalse($source, 'PosCoreReceiptProjection.php must be readable.');

        $violations = [];
        foreach ($this->forbiddenPatterns() as $entry) {
            if (preg_match($entry['pattern'], $source) === 1) {
                $violations[] = sprintf(
                    '  - %s: pattern %s → %s',
                    $entry['name'],
                    $entry['pattern'],
                    $entry['rationale'],
                );
            }
        }

        self::assertSame(
            [],
            $violations,
            'PosCoreReceiptProjection violates synthesis v5 §5 D16 invariant — sale-time snapshot must be authoritative. '.
            "See docs/superpowers/research/2026-05-20-sale-receipt-canonical-payload-synthesis-v5.md §5 + this test class docblock.\n".
            "Violations:\n".implode("\n", $violations)
        );
    }
}
