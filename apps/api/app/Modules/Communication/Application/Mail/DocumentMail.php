<?php

declare(strict_types=1);

namespace App\Modules\Communication\Application\Mail;

use App\Modules\Company\Domain\Company;
use App\Modules\Document\Domain\Document;
use App\Modules\Document\Domain\Enums\DocumentType;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Address;
use Illuminate\Mail\Mailables\Attachment;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

final class DocumentMail extends Mailable
{
    use Queueable, SerializesModels;

    /**
     * Create a new message instance.
     *
     * @param Document $document The document to send
     * @param Company $company The company sending the document
     * @param string $pdfContent The PDF content as binary string
     * @param string $pdfFilename The filename for the PDF attachment
     * @param string|null $customMessage Optional custom message to include
     * @param string|null $customSubject Optional custom subject line
     */
    public function __construct(
        public readonly Document $document,
        public readonly Company $company,
        public readonly string $pdfContent,
        public readonly string $pdfFilename,
        public readonly ?string $customMessage = null,
        public readonly ?string $customSubject = null,
    ) {
    }

    /**
     * Get the message envelope.
     */
    public function envelope(): Envelope
    {
        $subject = $this->customSubject ?? $this->getDefaultSubject();

        $from = new Address(
            $this->company->email ?? config('mail.from.address'),
            $this->company->name
        );

        return new Envelope(
            from: $from,
            subject: $subject,
        );
    }

    /**
     * Get the message content definition.
     */
    public function content(): Content
    {
        $locale = $this->company->locale ?? 'en';
        $view = $this->resolveEmailTemplate($locale);

        return new Content(
            view: $view,
            with: [
                'document' => $this->document,
                'company' => $this->company,
                'partner' => $this->document->partner,
                'documentTitle' => $this->getDocumentTitle($locale),
                'documentNumber' => $this->document->document_number,
                'total' => $this->document->total,
                'currency' => $this->document->currency ?? $this->company->currency,
                'customMessage' => $this->customMessage,
                'locale' => $locale,
            ],
        );
    }

    /**
     * Get the attachments for the message.
     *
     * @return array<int, Attachment>
     */
    public function attachments(): array
    {
        return [
            Attachment::fromData(fn () => $this->pdfContent, $this->pdfFilename)
                ->withMime('application/pdf'),
        ];
    }

    /**
     * Get the default subject line based on document type.
     */
    private function getDefaultSubject(): string
    {
        $locale = $this->company->locale ?? 'en';
        $title = $this->getDocumentTitle($locale);
        $number = $this->document->document_number;

        $subjects = [
            'en' => "{$title} {$number} from {$this->company->name}",
            'fr' => "{$title} {$number} de {$this->company->name}",
        ];

        return $subjects[$locale] ?? $subjects['en'];
    }

    /**
     * Get localized document title.
     */
    private function getDocumentTitle(string $locale): string
    {
        $titles = [
            'en' => [
                DocumentType::Quote->value => 'Quotation',
                DocumentType::SalesOrder->value => 'Sales Order',
                DocumentType::PurchaseOrder->value => 'Purchase Order',
                DocumentType::Invoice->value => 'Invoice',
                DocumentType::CreditNote->value => 'Credit Note',
                DocumentType::DeliveryNote->value => 'Delivery Note',
            ],
            'fr' => [
                DocumentType::Quote->value => 'Devis',
                DocumentType::SalesOrder->value => 'Bon de Commande',
                DocumentType::PurchaseOrder->value => 'Bon de Commande Fournisseur',
                DocumentType::Invoice->value => 'Facture',
                DocumentType::CreditNote->value => 'Avoir',
                DocumentType::DeliveryNote->value => 'Bon de Livraison',
            ],
        ];

        $type = $this->document->type->value;

        return $titles[$locale][$type] ?? $titles['en'][$type] ?? $this->document->type->label();
    }

    /**
     * Resolve the email template based on locale.
     */
    private function resolveEmailTemplate(string $locale): string
    {
        $localeTemplate = "emails.documents.{$locale}.document";
        if (view()->exists($localeTemplate)) {
            return $localeTemplate;
        }

        return 'emails.documents.document';
    }
}
