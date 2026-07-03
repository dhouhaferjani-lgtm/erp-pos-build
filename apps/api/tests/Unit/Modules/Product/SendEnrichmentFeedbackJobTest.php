<?php

declare(strict_types=1);

namespace Tests\Unit\Modules\Product;

use App\Modules\Company\Services\CompanyContext;
use App\Modules\Product\Application\Jobs\SendEnrichmentFeedbackJob;
use App\Shared\Contracts\PlatformSubmissionInterface;
use App\Shared\Enums\EnrichmentFeedbackAction;
use App\Shared\Enums\EnrichmentFeedbackReason;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Tests\TestCase;

class SendEnrichmentFeedbackJobTest extends TestCase
{
    public function test_handle_sets_company_context_sends_feedback_and_clears_context(): void
    {
        $companyContext = app(CompanyContext::class);
        $service = $this->createMock(PlatformSubmissionInterface::class);
        $service->expects($this->once())
            ->method('sendFeedback')
            ->with(
                '00000000-0000-0000-0000-000000000123',
                EnrichmentFeedbackAction::Rejected,
                EnrichmentFeedbackReason::BadData,
                'Incorrect product data',
            )
            ->willReturn(true);

        $job = new SendEnrichmentFeedbackJob(
            trackingId: '00000000-0000-0000-0000-000000000123',
            action: EnrichmentFeedbackAction::Rejected->value,
            reason: EnrichmentFeedbackReason::BadData->value,
            notes: 'Incorrect product data',
            companyId: '00000000-0000-0000-0000-000000000456',
        );

        $job->handle($companyContext, $service);

        $this->assertFalse($companyContext->hasCompany());
    }

    public function test_handle_clears_company_context_when_feedback_service_throws(): void
    {
        $companyContext = app(CompanyContext::class);
        $service = $this->createMock(PlatformSubmissionInterface::class);
        $service->expects($this->once())
            ->method('sendFeedback')
            ->willThrowException(new RuntimeException('Platform down'));

        $job = new SendEnrichmentFeedbackJob(
            trackingId: '00000000-0000-0000-0000-000000000123',
            action: EnrichmentFeedbackAction::Confirmed->value,
            reason: null,
            notes: null,
            companyId: '00000000-0000-0000-0000-000000000456',
        );

        try {
            $job->handle($companyContext, $service);
            $this->fail('Expected feedback service exception.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Platform down', $exception->getMessage());
        }

        $this->assertFalse($companyContext->hasCompany());
    }

    public function test_handle_throws_when_feedback_service_returns_false(): void
    {
        $companyContext = app(CompanyContext::class);
        $service = $this->createMock(PlatformSubmissionInterface::class);
        $service->expects($this->once())
            ->method('sendFeedback')
            ->willReturn(false);

        $job = new SendEnrichmentFeedbackJob(
            trackingId: '00000000-0000-0000-0000-000000000123',
            action: EnrichmentFeedbackAction::Confirmed->value,
            reason: null,
            notes: null,
            companyId: '00000000-0000-0000-0000-000000000456',
        );

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('enrichment feedback delivery failed for 00000000-0000-0000-0000-000000000123');

        try {
            $job->handle($companyContext, $service);
        } finally {
            $this->assertFalse($companyContext->hasCompany());
        }
    }

    public function test_failed_logs_permanent_failure(): void
    {
        $logger = new CapturingFeedbackJobLog;
        Log::swap($logger);

        $job = new SendEnrichmentFeedbackJob(
            trackingId: '00000000-0000-0000-0000-000000000123',
            action: EnrichmentFeedbackAction::Confirmed->value,
            reason: null,
            notes: null,
            companyId: '00000000-0000-0000-0000-000000000456',
        );

        $job->failed(new RuntimeException('Retries exhausted'));

        $this->assertSame('Enrichment feedback job failed permanently', $logger->errors[0]['message']);
        $this->assertSame('00000000-0000-0000-0000-000000000123', $logger->errors[0]['context']['tracking_id']);
    }
}

final class CapturingFeedbackJobLog
{
    /**
     * @var list<array{message: string, context: array<string, mixed>}>
     */
    public array $errors = [];

    /**
     * @param  array<string, mixed>  $context
     */
    public function error(string $message, array $context = []): void
    {
        $this->errors[] = [
            'message' => $message,
            'context' => $context,
        ];
    }
}
