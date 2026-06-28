<?php

declare(strict_types=1);

namespace App\Policies;

use App\Modules\Company\Services\CompanyContext;
use App\Modules\Document\Domain\Document;
use App\Modules\Document\Domain\Enums\DocumentType;
use App\Modules\Identity\Domain\User;

class DocumentPolicy
{
    public function __construct(
        private readonly CompanyContext $companyContext
    ) {}

    /**
     * Determine whether the user can view any models.
     */
    public function viewAny(User $user): bool
    {
        // Allow if user has permission for any document type
        return $user->hasAnyPermission([
            'documents.view',
            'quotes.view',
            'orders.view',
            'purchase-orders.view',
            'invoices.view',
            'credit-notes.view',
            'deliveries.view',
            'expenses.view',
        ]);
    }

    /**
     * Determine whether the user can view the model.
     */
    public function view(User $user, Document $document): bool
    {
        // Check company isolation via CompanyContext (not $user->company_id —
        // that column does not exist on the users table).
        if ($document->company_id !== $this->companyContext->getCompanyId()) {
            return false;
        }

        // Check permission based on document type
        return match ($document->type) {
            DocumentType::Quote => $user->can('quotes.view'),
            DocumentType::SalesOrder => $user->can('orders.view'),
            DocumentType::PurchaseOrder => $user->can('purchase-orders.view'),
            DocumentType::Invoice => $user->can('invoices.view'),
            DocumentType::CreditNote => $user->can('credit-notes.view'),
            DocumentType::DeliveryNote => $user->can('deliveries.view'),
            DocumentType::Expense => $user->can('expenses.view'),
            default => false,
        };
    }

    /**
     * Determine whether the user can create models.
     */
    public function create(User $user): bool
    {
        // Allow if user has permission to create any document type
        return $user->hasAnyPermission([
            'quotes.create',
            'orders.create',
            'purchase-orders.create',
            'invoices.create',
            'credit-notes.create',
            'deliveries.create',
            'expenses.create',
        ]);
    }

    /**
     * Determine whether the user can update the model.
     */
    public function update(User $user, Document $document): bool
    {
        // Check company isolation via CompanyContext
        if ($document->company_id !== $this->companyContext->getCompanyId()) {
            return false;
        }

        // Check permission based on document type
        return match ($document->type) {
            DocumentType::Quote => $user->can('quotes.update'),
            DocumentType::SalesOrder => $user->can('orders.update'),
            DocumentType::PurchaseOrder => $user->can('purchase-orders.update'),
            DocumentType::Invoice => $user->can('invoices.update'),
            DocumentType::CreditNote => false, // Credit notes can't be updated
            DocumentType::DeliveryNote => false, // Delivery notes can't be updated after creation
            DocumentType::Expense => $user->can('expenses.update'),
            default => false,
        };
    }

    /**
     * Determine whether the user can delete the model.
     */
    public function delete(User $user, Document $document): bool
    {
        // Check company isolation via CompanyContext
        if ($document->company_id !== $this->companyContext->getCompanyId()) {
            return false;
        }

        // Check permission based on document type
        return match ($document->type) {
            DocumentType::Quote => $user->can('quotes.delete'),
            DocumentType::SalesOrder => $user->can('orders.delete'),
            DocumentType::PurchaseOrder => $user->can('purchase-orders.delete'),
            DocumentType::Invoice => $user->can('invoices.delete'),
            DocumentType::CreditNote => false, // Credit notes can't be deleted
            DocumentType::DeliveryNote => false, // Delivery notes can't be deleted
            DocumentType::Expense => $user->can('expenses.delete'),
            default => false,
        };
    }

    /**
     * Determine whether the user can post the document.
     */
    public function post(User $user, Document $document): bool
    {
        // Check company isolation via CompanyContext
        if ($document->company_id !== $this->companyContext->getCompanyId()) {
            return false;
        }

        // Check permission based on document type
        return match ($document->type) {
            DocumentType::Invoice => $user->can('invoices.post'),
            DocumentType::CreditNote => $user->can('credit-notes.post'),
            DocumentType::Expense => $user->can('expenses.post'),
            default => false,
        };
    }

    /**
     * Determine whether the user can restore the model.
     */
    public function restore(User $user, Document $document): bool
    {
        return false; // Not implemented
    }

    /**
     * Determine whether the user can permanently delete the model.
     */
    public function forceDelete(User $user, Document $document): bool
    {
        return false; // Not allowed
    }
}
