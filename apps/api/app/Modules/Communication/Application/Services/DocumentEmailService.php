<?php

declare(strict_types=1);

namespace App\Modules\Communication\Application\Services;

use App\Modules\Communication\Application\Mail\DocumentMail;
use App\Modules\Document\Application\Services\DocumentPdfService;
use App\Modules\Document\Domain\Document;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

final class DocumentEmailService
{
    public function __construct(
        private readonly DocumentPdfService $pdfService,
    ) {}

    /**
     * Send a document via email to the partner.
     *
     * @param  Document  $document  The document to send
     * @param  string|null  $recipientEmail  Override the default recipient email
     * @param  string|null  $customMessage  Custom message to include in the email body
     * @param  string|null  $customSubject  Custom subject line
     * @param  array<string>  $ccEmails  Additional CC recipients
     * @return bool Whether the email was sent successfully
     */
    public function send(
        Document $document,
        ?string $recipientEmail = null,
        ?string $customMessage = null,
        ?string $customSubject = null,
        array $ccEmails = [],
    ): bool {
        $document->load(['company', 'partner']);

        $email = $this->resolveRecipientEmail($document, $recipientEmail);
        if ($email === null) {
            Log::warning('Cannot send document email: no recipient email', [
                'document_id' => $document->id,
                'document_number' => $document->document_number,
            ]);

            return false;
        }

        $pdfContent = $this->pdfService->generateContent($document);
        $pdfFilename = $this->pdfService->getFilename($document);

        $mailable = new DocumentMail(
            document: $document,
            company: $document->company,
            pdfContent: $pdfContent,
            pdfFilename: $pdfFilename,
            customMessage: $customMessage,
            customSubject: $customSubject,
        );

        $mail = Mail::to($email);

        if (! empty($ccEmails)) {
            $mail->cc($ccEmails);
        }

        $mail->send($mailable);

        Log::info('Document email sent', [
            'document_id' => $document->id,
            'document_number' => $document->document_number,
            'recipient' => $email,
            'cc' => $ccEmails,
        ]);

        return true;
    }

    /**
     * Queue a document email for later sending.
     *
     * @param  Document  $document  The document to send
     * @param  string|null  $recipientEmail  Override the default recipient email
     * @param  string|null  $customMessage  Custom message to include in the email body
     * @param  string|null  $customSubject  Custom subject line
     * @param  array<string>  $ccEmails  Additional CC recipients
     */
    public function queue(
        Document $document,
        ?string $recipientEmail = null,
        ?string $customMessage = null,
        ?string $customSubject = null,
        array $ccEmails = [],
    ): bool {
        $document->load(['company', 'partner']);

        $email = $this->resolveRecipientEmail($document, $recipientEmail);
        if ($email === null) {
            Log::warning('Cannot queue document email: no recipient email', [
                'document_id' => $document->id,
                'document_number' => $document->document_number,
            ]);

            return false;
        }

        $pdfContent = $this->pdfService->generateContent($document);
        $pdfFilename = $this->pdfService->getFilename($document);

        $mailable = new DocumentMail(
            document: $document,
            company: $document->company,
            pdfContent: $pdfContent,
            pdfFilename: $pdfFilename,
            customMessage: $customMessage,
            customSubject: $customSubject,
        );

        $mail = Mail::to($email);

        if (! empty($ccEmails)) {
            $mail->cc($ccEmails);
        }

        $mail->queue($mailable);

        Log::info('Document email queued', [
            'document_id' => $document->id,
            'document_number' => $document->document_number,
            'recipient' => $email,
            'cc' => $ccEmails,
        ]);

        return true;
    }

    /**
     * Resolve the recipient email address.
     */
    private function resolveRecipientEmail(Document $document, ?string $override = null): ?string
    {
        if ($override !== null && $override !== '') {
            return $override;
        }

        $partner = $document->partner;
        if ($partner === null) {
            return null;
        }

        return $partner->email;
    }
}
