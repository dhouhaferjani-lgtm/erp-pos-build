<?php

declare(strict_types=1);

namespace Tests\Unit\Modules\Product;

use App\Modules\Company\Services\CompanyContext;
use App\Modules\PlatformIntegration\Application\Services\ProductSubmissionService;
use App\Modules\Product\Application\Jobs\SendBrandMappingJob;
use App\Shared\Contracts\PlatformSubmissionInterface;
use App\Shared\Enums\BrandMappingPushResult;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Tests\TestCase;

final class SendBrandMappingJobTest extends TestCase
{
    public function test_handle_sets_company_context_pushes_mapping_and_clears_context(): void
    {
        $companyContext = app(CompanyContext::class);
        $companyContext->clear();

        $service = $this->createMock(PlatformSubmissionInterface::class);
        $service->expects($this->once())
            ->method('pushBrandMapping')
            ->with('canonical-1', 'brand-1')
            ->willReturn(BrandMappingPushResult::Mapped);

        $job = new SendBrandMappingJob('canonical-1', 'brand-1', 'company-1');
        $job->handle($companyContext, $service);

        $this->assertFalse($companyContext->hasCompany());
    }

    public function test_handle_does_not_throw_on_conflict(): void
    {
        $service = $this->createMock(PlatformSubmissionInterface::class);
        $service->method('pushBrandMapping')->willReturn(BrandMappingPushResult::Conflict);
        $companyContext = app(CompanyContext::class);

        $job = new SendBrandMappingJob('canonical-1', 'brand-1', 'company-1');
        $job->handle($companyContext, $service);

        $this->assertFalse($companyContext->hasCompany());
    }

    public function test_handle_does_not_throw_on_not_found(): void
    {
        $service = $this->createMock(PlatformSubmissionInterface::class);
        $service->method('pushBrandMapping')->willReturn(BrandMappingPushResult::NotFound);
        $companyContext = app(CompanyContext::class);

        $job = new SendBrandMappingJob('canonical-1', 'brand-1', 'company-1');
        $job->handle($companyContext, $service);

        $this->assertFalse($companyContext->hasCompany());
    }

    public function test_handle_throws_on_failed_so_retries_engage(): void
    {
        $service = $this->createMock(PlatformSubmissionInterface::class);
        $service->method('pushBrandMapping')->willReturn(BrandMappingPushResult::Failed);

        $job = new SendBrandMappingJob('canonical-1', 'brand-1', 'company-1');

        $this->expectException(RuntimeException::class);
        $job->handle(app(CompanyContext::class), $service);
    }

    public function test_handle_completes_without_retry_when_platform_push_is_disabled(): void
    {
        config(['services.platform.push_enabled' => false]);
        Http::fake();

        $companyContext = app(CompanyContext::class);
        $job = new SendBrandMappingJob('canonical-1', 'brand-1', 'company-1');

        $job->handle($companyContext, app(ProductSubmissionService::class));

        Http::assertNothingSent();
        $this->assertFalse($companyContext->hasCompany());
    }

    public function test_handle_clears_context_when_service_throws(): void
    {
        $companyContext = app(CompanyContext::class);
        $service = $this->createMock(PlatformSubmissionInterface::class);
        $service->method('pushBrandMapping')->willThrowException(new RuntimeException('boom'));

        $job = new SendBrandMappingJob('canonical-1', 'brand-1', 'company-1');

        try {
            $job->handle($companyContext, $service);
        } catch (RuntimeException) {
        }

        $this->assertFalse($companyContext->hasCompany());
    }

    public function test_uses_enrichment_queue_and_retry_policy(): void
    {
        $job = new SendBrandMappingJob('canonical-1', 'brand-1', 'company-1');

        $this->assertSame('enrichment', $job->queue);
        $this->assertSame(3, $job->tries);
        $this->assertSame([10, 60, 300], $job->backoff);
    }
}
