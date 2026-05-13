<?php

declare(strict_types=1);

namespace App\Modules\Communication\Presentation\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Communication\Application\Services\DocumentEmailService;
use App\Modules\Communication\Presentation\Requests\SendDocumentEmailRequest;
use App\Modules\Company\Services\CompanyContext;
use App\Modules\Document\Domain\Document;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Log;

/**
 * Send / queue document email.
 *
 * dev-remediation/B.M2.2 — closed both CrossTenantRoute annotations
 * (send + queue) by replacing Route Model Binding on Document with a
 * CompanyContext-scoped query. A foreign-tenant or cross-company
 * document id returns 404 with the same shape as a missing id so the
 * PDF generation pathway and downstream mail transport never see
 * cross-tenant data.
 */
final class DocumentEmailController extends Controller
{
    public function __construct(
        private readonly DocumentEmailService $emailService,
        private readonly CompanyContext $companyContext,
    ) {}

    /**
     * Send a document via email.
     */
    public function send(SendDocumentEmailRequest $request, string $document): JsonResponse
    {
        $documentModel = $this->resolveDocument($document);

        try {
            /** @var array<string> $ccEmails */
            $ccEmails = $request->input('cc_emails', []);

            $sent = $this->emailService->send(
                document: $documentModel,
                recipientEmail: $request->input('recipient_email'),
                customMessage: $request->input('message'),
                customSubject: $request->input('subject'),
                ccEmails: $ccEmails,
            );

            if (! $sent) {
                return response()->json([
                    'success' => false,
                    'message' => 'No recipient email address found. Please provide an email address or ensure the partner has an email on file.',
                ], 422);
            }

            return response()->json([
                'success' => true,
                'message' => 'Document email sent successfully.',
            ]);
        } catch (\Exception $e) {
            Log::error('Failed to send document email', [
                'document_id' => $documentModel->id,
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to send email. Please try again later.',
            ], 500);
        }
    }

    /**
     * Queue a document email for later sending.
     */
    public function queue(SendDocumentEmailRequest $request, string $document): JsonResponse
    {
        $documentModel = $this->resolveDocument($document);

        try {
            /** @var array<string> $ccEmails */
            $ccEmails = $request->input('cc_emails', []);

            $queued = $this->emailService->queue(
                document: $documentModel,
                recipientEmail: $request->input('recipient_email'),
                customMessage: $request->input('message'),
                customSubject: $request->input('subject'),
                ccEmails: $ccEmails,
            );

            if (! $queued) {
                return response()->json([
                    'success' => false,
                    'message' => 'No recipient email address found. Please provide an email address or ensure the partner has an email on file.',
                ], 422);
            }

            return response()->json([
                'success' => true,
                'message' => 'Document email has been queued for sending.',
            ]);
        } catch (\Exception $e) {
            Log::error('Failed to queue document email', [
                'document_id' => $documentModel->id,
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to queue email. Please try again later.',
            ], 500);
        }
    }

    /**
     * Resolve the bound document through the caller's tenant + company
     * scope. The shared 404 shape across foreign, missing, and
     * cross-company ids removes id-enumeration leakage.
     */
    private function resolveDocument(string $documentId): Document
    {
        $company = $this->companyContext->requireCompany();

        $document = Document::query()
            ->where('tenant_id', $company->tenant_id)
            ->where('company_id', $company->id)
            ->where('id', $documentId)
            ->first();

        if ($document === null) {
            abort(404, 'Document not found');
        }

        return $document;
    }
}
