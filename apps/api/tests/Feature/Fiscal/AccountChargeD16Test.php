<?php

declare(strict_types=1);

namespace Tests\Feature\Fiscal;

use PHPUnit\Framework\TestCase;

final class AccountChargeD16Test extends TestCase
{
    private const PROJECTION_FILE = __DIR__.'/../../../app/Modules/POS/Application/Projections/AccountChargeReceiptProjection.php';

    /**
     * @return list<array{name: string, pattern: string, rationale: string}>
     */
    private function forbiddenPatterns(): array
    {
        return [
            ['name' => 'treasury-module-direct', 'pattern' => '/\\buse\\s+App\\\\Modules\\\\Treasury\\\\/', 'rationale' => 'D16: Treasury operational effects live in a gated bridge, not POS-core'],
            ['name' => 'accounting-module-direct', 'pattern' => '/\\buse\\s+App\\\\Modules\\\\Accounting\\\\/', 'rationale' => 'D16: Accounting is downstream, not POS-core'],
            ['name' => 'document-module-direct', 'pattern' => '/\\buse\\s+App\\\\Modules\\\\Document\\\\/', 'rationale' => 'D8: web B2B aggregates are a later bridge, not POS-core'],
            ['name' => 'sales-module-direct', 'pattern' => '/\\buse\\s+App\\\\Modules\\\\Sales\\\\/', 'rationale' => 'D8: no sale aggregate writes from POS-core'],
            ['name' => 'partner-module-direct', 'pattern' => '/\\buse\\s+App\\\\Modules\\\\Partner\\\\/', 'rationale' => 'D16: customer identity is sealed payload snapshot here'],
            ['name' => 'customer-module-direct', 'pattern' => '/\\buse\\s+App\\\\Modules\\\\Customer\\\\/', 'rationale' => 'D16: customer mirror data is already sealed in payload'],
            ['name' => 'contact-module-direct', 'pattern' => '/\\buse\\s+App\\\\Modules\\\\Contact\\\\/', 'rationale' => 'D16: no live contact lookup from POS-core projector'],
            ['name' => 'b2b-module-direct', 'pattern' => '/\\buse\\s+App\\\\Modules\\\\B2B\\\\/', 'rationale' => 'D8: B2B Facture bridge is separate from POS-core printable'],
            ['name' => 'app-helper-container-resolved', 'pattern' => '/\\bapp\\s*\\(/', 'rationale' => 'CLAUDE.md rule 13: constructor injection only'],
            ['name' => 'app-make-container-resolved', 'pattern' => '/\\bApp::make\\s*\\(/', 'rationale' => 'CLAUDE.md rule 13: constructor injection only'],
            ['name' => 'resolve-container-resolved', 'pattern' => '/\\bresolve\\s*\\(/', 'rationale' => 'CLAUDE.md rule 13: constructor injection only'],
        ];
    }

    public function test_projection_file_exists(): void
    {
        self::assertFileExists(self::PROJECTION_FILE);
    }

    public function test_account_charge_pos_core_projection_has_no_forbidden_module_imports(): void
    {
        $source = file_get_contents(self::PROJECTION_FILE);
        self::assertNotFalse($source, 'AccountChargeReceiptProjection.php must be readable.');

        $violations = [];
        foreach ($this->forbiddenPatterns() as $entry) {
            if (preg_match($entry['pattern'], $source) === 1) {
                $violations[] = sprintf(
                    '  - %s: pattern %s -> %s',
                    $entry['name'],
                    $entry['pattern'],
                    $entry['rationale'],
                );
            }
        }

        self::assertSame(
            [],
            $violations,
            "AccountChargeReceiptProjection violates SoT v3 D16 POS-core boundary.\nViolations:\n".implode("\n", $violations),
        );
    }
}
