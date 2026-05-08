<?php

declare(strict_types=1);

namespace Tests\Unit\Progression;

use App\Modules\Progression\Application\Contracts\GrowthAdvisorClientInterface;
use App\Modules\Progression\Application\Services\ProgressionService;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * api.module-gating cluster — Growth Advisor response-id contract.
 *
 * ProgressionService asserts that when the upstream Growth Advisor
 * response carries `id` (the company id echo) or `tenant_id`, those
 * values match the request's expected company / tenant. A mismatch
 * is the canonical "cross-tenant data leak" shape — a fail-loud
 * RuntimeException bubbles through the controller's error handler
 * as a 500.
 *
 * Two response shapes carry the echo:
 *   - getCompanyProfile($companyId) → CompanyProfileData (id, tenant_id)
 *   - registerCompany($data)        → CompanyProfileData (id, tenant_id)
 *
 * Six don't (URL-bound only):
 *   - getMilestones, getModules, activateModule, getRecommendations,
 *     acceptRecommendation, dismissRecommendation
 * The guard distinguishes legitimate "no echo" responses from
 * mismatching echoes — only mismatches throw.
 */
final class ProgressionServiceResponseIdVerificationTest extends TestCase
{
    public function test_get_profile_throws_when_response_company_id_mismatches(): void
    {
        $client = $this->createMock(GrowthAdvisorClientInterface::class);
        $client->method('getCompanyProfile')
            ->with('comp-A')
            ->willReturn([
                'id' => 'comp-MALICIOUS-FOREIGN', // mismatch — possible cross-tenant leak
                'tenant_id' => 'tenant-1',
                'vertical' => 'pos',
                'country' => 'FR',
                'current_stage' => 'launch',
                'stage_progress_percent' => 50,
                'total_milestones' => 10,
                'completed_milestones' => 5,
            ]);

        $service = new ProgressionService($client);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/Growth Advisor response.*company.*mismatch/i');

        $service->getProfile('comp-A');
    }

    public function test_register_company_throws_when_response_tenant_id_mismatches(): void
    {
        $client = $this->createMock(GrowthAdvisorClientInterface::class);
        $client->method('registerCompany')
            ->willReturn([
                'id' => 'comp-A', // company matches
                'tenant_id' => 'tenant-MALICIOUS-FOREIGN', // tenant mismatches
                'vertical' => 'pos',
                'country' => 'FR',
                'current_stage' => 'launch',
                'stage_progress_percent' => 0,
                'total_milestones' => 10,
                'completed_milestones' => 0,
            ]);

        $service = new ProgressionService($client);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/Growth Advisor response.*tenant.*mismatch/i');

        $service->registerCompany([
            'company_id' => 'comp-A',
            'tenant_id' => 'tenant-1',
        ]);
    }

    public function test_get_profile_does_not_throw_when_response_ids_match(): void
    {
        $client = $this->createMock(GrowthAdvisorClientInterface::class);
        $client->method('getCompanyProfile')
            ->with('comp-A')
            ->willReturn([
                'id' => 'comp-A',           // matches
                'tenant_id' => 'tenant-1',  // doesn't matter for first guard
                'vertical' => 'pos',
                'country' => 'FR',
                'current_stage' => 'launch',
                'stage_progress_percent' => 50,
                'total_milestones' => 10,
                'completed_milestones' => 5,
            ]);

        $service = new ProgressionService($client);

        // Should not throw — runs to completion and returns a DTO.
        $result = $service->getProfile('comp-A');

        $this->assertNotNull($result);
        $this->assertSame('comp-A', $result->id);
    }

    public function test_register_company_throws_when_response_company_id_mismatches(): void
    {
        $client = $this->createMock(GrowthAdvisorClientInterface::class);
        $client->method('registerCompany')
            ->willReturn([
                'id' => 'comp-MALICIOUS-FOREIGN', // mismatch
                'tenant_id' => 'tenant-1',
                'vertical' => 'pos',
                'country' => 'FR',
                'current_stage' => 'launch',
                'stage_progress_percent' => 0,
                'total_milestones' => 10,
                'completed_milestones' => 0,
            ]);

        $service = new ProgressionService($client);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/Growth Advisor response.*company.*mismatch/i');

        $service->registerCompany([
            'company_id' => 'comp-A',
            'tenant_id' => 'tenant-1',
        ]);
    }

    public function test_get_profile_returns_null_when_client_returns_null_no_throw(): void
    {
        // Service-unavailable path (circuit breaker / 404). Client returns
        // null without invoking response-id verification. Service
        // short-circuits via the existing null guard — no throw.
        $client = $this->createMock(GrowthAdvisorClientInterface::class);
        $client->method('getCompanyProfile')->willReturn(null);

        $service = new ProgressionService($client);

        $result = $service->getProfile('comp-A');

        $this->assertNull($result);
    }

    public function test_company_id_guard_allows_responses_without_company_id_echo(): void
    {
        $client = $this->createMock(GrowthAdvisorClientInterface::class);
        $service = new ProgressionService($client);
        $method = new \ReflectionMethod($service, 'assertResponseCompanyMatches');

        $method->invoke($service, ['name' => 'URL-bound response without company echo'], 'comp-A', 'test');

        $this->addToAssertionCount(1);
    }

    public function test_tenant_id_guard_allows_responses_without_tenant_id_echo(): void
    {
        $client = $this->createMock(GrowthAdvisorClientInterface::class);
        $service = new ProgressionService($client);
        $method = new \ReflectionMethod($service, 'assertResponseTenantMatches');

        $method->invoke($service, ['name' => 'URL-bound response without tenant echo'], 'tenant-1', 'test');

        $this->addToAssertionCount(1);
    }
}
