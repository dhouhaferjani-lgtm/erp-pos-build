<?php

declare(strict_types=1);

namespace App\Modules\Communication\Presentation\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Communication\Application\Services\DocumentEmailService;
use App\Modules\Communication\Presentation\Requests\SendDocumentEmailRequest;
use App\Modules\Document\Domain\Document;
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
