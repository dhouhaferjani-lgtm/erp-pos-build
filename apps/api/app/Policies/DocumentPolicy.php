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
            // R2-F4: a correcting entry exposes raw GL accounts and amounts, so
            // it rides on its own admin-tier permission, never on documents.view.
            DocumentType::CorrectingEntry => $user->can('documents.correct'),
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
            // R2-F4: a correcting entry's legs are never edited in place — a
            // draft is discarded and re-created, a posted one is immutable.
            DocumentType::CorrectingEntry => false,
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
            // R2-F4: only a DRAFT correcting entry is deletable; the status check
            // lives in CorrectingEntryService::delete().
            DocumentType::CorrectingEntry => $user->can('documents.correct'),
            default => false,
        };
    }

    /**
     * Determine whether the user can REVERT a confirmed document back to draft.
     *
     * F-W2-14 residual (a). `POST /documents/{document}/revert` keeps its coarse
     * `can:documents.update` route middleware (that is what refuses a viewer),
     * but the route is type-agnostic while the act is not: reverting a CONFIRMED
     * PURCHASE ORDER un-commits a supplier commitment and re-opens a document a
     * goods receipt or a supplier invoice may be waiting on
     * (`DocumentPostingService::revertPurchaseOrder()` refuses only when those
     * already EXIST). `cashier` holds `documents.update` and no
     * `purchase-orders.*` at all, so on the browser run it un-confirmed a PO
     * (`W2-PERM-6..11`).
     *
     * The PO arm therefore rides on `purchase-orders.confirm` — the permission
     * that governs the inverse transition (`purchase-orders.confirm` route,
     * Document routes.php) — rather than on a new permission, and rather than on
     * `purchase-orders.update`, which a role may hold to correct a DRAFT without
     * being allowed to un-commit a confirmed order.
     *
     * Every other revertable type (quote, sales order — see the `match` in
     * `DocumentPostingService::revert()`) keeps exactly the pre-existing
     * `documents.update` behaviour.
     */
    public function revert(User $user, Document $document): bool
    {
        // Check company isolation via CompanyContext
        if ($document->company_id !== $this->companyContext->getCompanyId()) {
            return false;
        }

        return match ($document->type) {
            DocumentType::PurchaseOrder => $user->can('purchase-orders.confirm'),
            default => $user->can('documents.update'),
        };
    }

    /**
     * Determine whether the user can ATTACH a file to (or detach one from) the
     * document.
     *
     * Gate r1 finding 5 (F-W2-14 residual surface): the Media routes
     * (`POST|DELETE documents/{document}/attachments…`) are type-agnostic
     * `can:documents.update`, so a `cashier` could attach to — and delete
     * attachments from — a SUPPLIER INVOICE, whose attachment IS the legal
     * supporting piece of an AP document. The supplier-invoice arm rides on the
     * same dedicated gate as every other supplier-invoice mutation
     * (`supplier-invoices.manage`, F-W2-14); every other type keeps the
     * pre-existing `documents.update` behaviour.
     */
    public function attach(User $user, Document $document): bool
    {
        // Check company isolation via CompanyContext
        if ($document->company_id !== $this->companyContext->getCompanyId()) {
            return false;
        }

        return match ($document->type) {
            DocumentType::SupplierInvoice => $user->can('supplier-invoices.manage'),
            default => $user->can('documents.update'),
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
            DocumentType::CorrectingEntry => $user->can('documents.correct'),
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
