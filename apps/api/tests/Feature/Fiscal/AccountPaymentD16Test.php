<?php

declare(strict_types=1);

namespace Tests\Feature\Fiscal;

use PHPUnit\Framework\TestCase;

final class AccountPaymentD16Test extends TestCase
{
    private const PROJECTION_FILE = __DIR__.'/../../../app/Modules/POS/Application/Projections/AccountPaymentReceiptProjection.php';

    /**
     * @return list<array{name: string, pattern: string, rationale: string}>
     */
    private function forbiddenPatterns(): array
    {
        return [
            ['name' => 'treasury-module-direct', 'pattern' => '/\\buse\\s+App\\\\Modules\\\\Treasury\\\\/', 'rationale' => 'D16: Treasury operational effects live in a gated bridge, not POS-core'],
            ['name' => 'accounting-module-direct', 'pattern' => '/\\buse\\s+App\\\\Modules\\\\Accounting\\\\/', 'rationale' => 'D16: Accounting is downstream, not POS-core'],
            ['name' => 'partner-module-direct', 'pattern' => '/\\buse\\s+App\\\\Modules\\\\Partner\\\\/', 'rationale' => 'D16: customer identity is sealed payload snapshot here'],
            ['name' => 'customer-module-direct', 'pattern' => '/\\buse\\s+App\\\\Modules\\\\Customer\\\\/', 'rationale' => 'D16: customer mirror data is already sealed in payload'],
            ['name' => 'contact-module-direct', 'pattern' => '/\\buse\\s+App\\\\Modules\\\\Contact\\\\/', 'rationale' => 'D16: no live contact lookup from POS-core projector'],
            ['name' => 'b2b-module-direct', 'pattern' => '/\\buse\\s+App\\\\Modules\\\\B2B\\\\/', 'rationale' => 'D16: B2B path is out of scope for B2C account payment'],
            ['name' => 'app-helper-container-resolved', 'pattern' => '/\\bapp\\s*\\(/', 'rationale' => 'CLAUDE.md rule 13: constructor injection only'],
            ['name' => 'app-make-container-resolved', 'pattern' => '/\\bApp::make\\s*\\(/', 'rationale' => 'CLAUDE.md rule 13: constructor injection only'],
            ['name' => 'resolve-container-resolved', 'pattern' => '/\\bresolve\\s*\\(/', 'rationale' => 'CLAUDE.md rule 13: constructor injection only'],
        ];
    }

    public function test_projection_file_exists(): void
    {
        self::assertFileExists(self::PROJECTION_FILE);
    }

    public function test_account_payment_projection_does_not_import_forbidden_modules_or_helpers(): void
    {
        $source = file_get_contents(self::PROJECTION_FILE);
        self::assertNotFalse($source, 'AccountPaymentReceiptProjection.php must be readable.');

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
            "AccountPaymentReceiptProjection violates SoT v3 D16 POS-core boundary.\nViolations:\n".implode("\n", $violations),
        );
    }
}
