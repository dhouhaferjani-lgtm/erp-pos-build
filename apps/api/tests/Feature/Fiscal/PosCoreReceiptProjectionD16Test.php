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
 * **Divergence from synthesis v5 §5 — Treasury intentionally excluded.**
 * Synthesis v5 §5 lists `App\Modules\Treasury\` as forbidden, but Pass
 * 2A.PHP.1 dispatch §0 Gap A resolves the `payment_method_id` FK puzzle
 * by routing the projector to `PaymentMethod::where('tenant_id', X)->where('code', Y)->first()`
 * — a legitimate runtime read. The Treasury FK lookup is NOT a sale-time
 * snapshot violation: payment-method rows are tenant-config (not customer
 * PII), and the projector idempotency guarantees deterministic resolution
 * (FK uniqueness is enforced by `payment_methods.unique(['tenant_id', 'code'])`).
 * The dispatch's Gap A resolution overrides v5 §5's Treasury entry.
 *
 * **Pass 2A.PHP.2** un-skip path: when Pass 2A.PHP.2 migrates the
 * projector to read `method_code` from canonical payload (not from a
 * cross-module `PaymentMethod::find($payment_method_id)`), the Treasury
 * import remains for FK lookup but the model-traversal patterns the
 * test ALSO forbids (`PaymentMethod::all()`, etc.) stay rejected.
 */
final class PosCoreReceiptProjectionD16Test extends TestCase
{
    private const PROJECTION_FILE = __DIR__.'/../../../app/Modules/POS/Application/Projections/PosCoreReceiptProjection.php';

    /**
     * Forbidden patterns — synthesis v5 §5 minus Treasury (per dispatch
     * Gap A). Each pattern is a regex applied to the file contents.
     *
     * @return list<array{name: string, pattern: string, rationale: string}>
     */
    private function forbiddenPatterns(): array
    {
        return [
            ['name' => 'customer-module-direct', 'pattern' => '/\\buse\\s+App\\\\Modules\\\\Customer\\\\/', 'rationale' => 'D16: buyer snapshot in payload; no live customer lookup'],
            ['name' => 'contact-module-direct', 'pattern' => '/\\buse\\s+App\\\\Modules\\\\Contact\\\\/', 'rationale' => 'D16: buyer snapshot in payload; no live contact lookup'],
            ['name' => 'b2b-module-direct', 'pattern' => '/\\buse\\s+App\\\\Modules\\\\B2B\\\\/', 'rationale' => 'D16: B2B buyer data is sale-time snapshot'],
            ['name' => 'accounting-module-direct', 'pattern' => '/\\buse\\s+App\\\\Modules\\\\Accounting\\\\/', 'rationale' => 'D16: accounting is downstream consumer, not upstream source'],
            ['name' => 'customer-contract-indirect', 'pattern' => '/\\buse\\s+App\\\\Shared\\\\Contracts\\\\Customer\\\\/', 'rationale' => 'D16: indirect coupling via contract is still coupling'],
            ['name' => 'contact-contract-indirect', 'pattern' => '/\\buse\\s+App\\\\Shared\\\\Contracts\\\\Contact\\\\/', 'rationale' => 'D16: indirect coupling via contract is still coupling'],
            ['name' => 'b2b-contract-indirect', 'pattern' => '/\\buse\\s+App\\\\Shared\\\\Contracts\\\\B2B\\\\/', 'rationale' => 'D16: indirect coupling via contract is still coupling'],
            ['name' => 'treasury-contract-indirect', 'pattern' => '/\\buse\\s+App\\\\Shared\\\\Contracts\\\\Treasury\\\\/', 'rationale' => 'D16: Treasury FK lookup uses direct model (Gap A); contract is wider surface'],
            ['name' => 'accounting-contract-indirect', 'pattern' => '/\\buse\\s+App\\\\Shared\\\\Contracts\\\\Accounting\\\\/', 'rationale' => 'D16: indirect coupling via contract is still coupling'],
            ['name' => 'app-helper-container-resolved', 'pattern' => '/\\bapp\\s*\\(/', 'rationale' => 'CLAUDE.md rule 13: constructor injection only'],
            ['name' => 'app-make-container-resolved', 'pattern' => '/\\bApp::make\\s*\\(/', 'rationale' => 'CLAUDE.md rule 13: constructor injection only'],
            ['name' => 'resolve-container-resolved', 'pattern' => '/\\bresolve\\s*\\(/', 'rationale' => 'CLAUDE.md rule 13: constructor injection only'],
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
