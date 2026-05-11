<?php

declare(strict_types=1);

namespace App\Modules\Communication\Presentation\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Communication\Application\Services\DocumentEmailService;
use App\Modules\Communication\Presentation\Requests\SendDocumentEmailRequest;
use App\Modules\Document\Domain\Document;
use App\Shared\Architecture\CrossTenantRoute;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Log;

final class DocumentEmailController extends Controller
{
    public function __construct(
        private readonly DocumentEmailService $emailService,
    ) {}

    /**
     * Send a document via email.
     */
    #[CrossTenantRoute(reason: 'KNOWN TENANT-ISOLATION GAP — Document Route Model Binding does NOT auto-scope by tenant (Document model has only scopeForTenant local scope, not global). The bound $document is forwarded to DocumentEmailService::send which generates the PDF via the document\'s data — including cross-tenant data if a different-tenant document id is passed. Tracked for future api.document cluster fix.')]
    public function send(SendDocumentEmailRequest $request, Document $document): JsonResponse
    {
        try {
            /** @var array<string> $ccEmails */
            $ccEmails = $request->input('cc_emails', []);

            $sent = $this->emailService->send(
                document: $document,
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
                'document_id' => $document->id,
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
    #[CrossTenantRoute(reason: 'KNOWN TENANT-ISOLATION GAP — same Document Route Model Binding issue as send(); the queue path defers actual email sending to a job, but the Document binding gap is identical. Tracked for future api.document cluster fix.')]
    public function queue(SendDocumentEmailRequest $request, Document $document): JsonResponse
    {
        try {
            /** @var array<string> $ccEmails */
            $ccEmails = $request->input('cc_emails', []);

            $queued = $this->emailService->queue(
                document: $document,
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
                'document_id' => $document->id,
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to queue email. Please try again later.',
            ], 500);
        }
    }
}
