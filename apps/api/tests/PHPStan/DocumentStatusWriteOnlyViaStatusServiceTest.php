<?php

declare(strict_types=1);

namespace Tests\PHPStan;

use App\Modules\Document\Domain\Services\DocumentStatusService;
use App\PHPStan\Rules\DocumentStatusWriteOnlyViaStatusService;
use PHPStan\Rules\Rule;
use PHPStan\Testing\RuleTestCase;
use ReflectionClass;

/** @extends RuleTestCase<DocumentStatusWriteOnlyViaStatusService> */
final class DocumentStatusWriteOnlyViaStatusServiceTest extends RuleTestCase
{
    private const PAID_MESSAGE = 'DocumentStatus::Paid may only be written inside App\\Modules\\Document\\Domain\\Services\\DocumentStatusService.'
        .' A payment on a CONFIRMED document is an advance (Cr 419), not a settlement:'
        .' use DocumentStatusService::markPaid(), which refuses the confirmed → paid edge.';

    private const POSTED_MESSAGE = 'Treasury may not write DocumentStatus::Posted directly.'
        .' Use App\\Modules\\Document\\Domain\\Services\\DocumentStatusService::reopenFromPaid(), which refuses to promote a'
        .' never-sealed document (fiscal_hash IS NULL) to Posted.';

    protected function getRule(): Rule
    {
        return new DocumentStatusWriteOnlyViaStatusService;
    }

    public function test_rule_is_registered_in_phpstan_neon(): void
    {
        $this->assertTrue(class_exists(DocumentStatusWriteOnlyViaStatusService::class));
        $this->assertStringContainsString(
            '- '.DocumentStatusWriteOnlyViaStatusService::class,
            (string) file_get_contents(dirname(__DIR__, 2).'/phpstan.neon'),
        );
    }

    public function test_composer_resolves_the_runtime_status_service_from_app(): void
    {
        $runtimePath = realpath(
            dirname(__DIR__, 2).'/app/Modules/Document/Domain/Services/DocumentStatusService.php',
        );
        $resolvedPath = realpath((string) (new ReflectionClass(DocumentStatusService::class))->getFileName());

        $this->assertNotFalse($runtimePath);
        $this->assertSame($runtimePath, $resolvedPath);
    }

    /**
     * The four write FORMS. `update()` / `forceFill()` are exactly the shapes the
     * WorkOrder precedent misses (TRIAGE #27) — and exactly the shapes the real
     * pre-N-6 writers used.
     */
    public function test_reports_every_paid_write_form(): void
    {
        $this->analyse(
            [
                __DIR__.'/Fixtures/DocumentStatusPaidPropertyAssignFixture.php',
                __DIR__.'/Fixtures/DocumentStatusPaidUpdateArrayFixture.php',
                __DIR__.'/Fixtures/DocumentStatusPaidForceFillFixture.php',
                __DIR__.'/Fixtures/DocumentStatusPaidViaVariableFixture.php',
                __DIR__.'/Fixtures/DocumentStatusPaidFillFixture.php',
                __DIR__.'/Fixtures/DocumentStatusPaidBuilderUpdateFixture.php',
            ],
            [
                [self::PAID_MESSAGE, 14],
                [self::PAID_MESSAGE, 18],
                [self::PAID_MESSAGE, 14],
                [self::PAID_MESSAGE, 19],
                [self::PAID_MESSAGE, 18],
                [self::PAID_MESSAGE, 19],
            ],
        );
    }

    public function test_reports_a_treasury_posted_write(): void
    {
        $this->analyse(
            [__DIR__.'/Fixtures/DocumentStatusTreasuryPostedFixture.php'],
            [[self::POSTED_MESSAGE, 19]],
        );
    }

    public function test_stays_silent_inside_the_status_service_and_on_unrelated_writes(): void
    {
        $this->analyse(
            [
                dirname(__DIR__, 2).'/app/Modules/Document/Domain/Services/DocumentStatusService.php',
                __DIR__.'/Fixtures/DocumentStatusUnrelatedWritesFixture.php',
            ],
            [],
        );
    }
}
