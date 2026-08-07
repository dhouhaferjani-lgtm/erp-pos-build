<?php

declare(strict_types=1);

return [
    /*
    |--------------------------------------------------------------------------
    | General API Messages
    |--------------------------------------------------------------------------
    |
    | The following language lines are used for general API responses
    | throughout the application.
    |
    */

    // General
    'success' => 'Operation completed successfully.',
    'error' => 'An error occurred.',
    'not_found' => 'Resource not found.',
    'resource_not_found' => ':resource not found.',
    'forbidden' => 'You do not have permission to perform this action.',
    'server_error' => 'Internal server error. Please try again later.',

    // CRUD operations
    'created' => ':resource created successfully.',
    'updated' => ':resource updated successfully.',
    'deleted' => ':resource deleted successfully.',
    'restored' => ':resource restored successfully.',
    'income_posted' => 'Income posted successfully.',
    'expense_settled' => 'Expense settled successfully.',

    // Documents
    'document' => [
        'posted' => 'Document posted successfully.',
        'cancelled' => 'Document cancelled successfully.',
        'confirmed' => 'Document confirmed successfully.',
        'cannot_edit_posted' => 'Posted documents cannot be edited.',
        'cannot_delete_posted' => 'Posted documents cannot be deleted.',
        'already_posted' => 'This document has already been posted.',
        'invalid_status_transition' => 'Invalid status transition.',
    ],

    // Partners
    'partner' => [
        'has_documents' => 'Cannot delete partner with associated documents.',
        'has_balance' => 'Cannot delete partner with outstanding balance.',
    ],

    // Products
    'product' => [
        'has_stock' => 'Cannot delete product with existing stock.',
        'has_documents' => 'Cannot delete product with associated documents.',
        'insufficient_stock' => 'Insufficient stock for :product. Available: :available, Requested: :requested.',
    ],

    // Payments
    'payment' => [
        'recorded' => 'Payment recorded successfully.',
        'cancelled' => 'Payment cancelled successfully.',
        'amount_exceeds_balance' => 'Payment amount exceeds outstanding balance.',
        'invalid_allocation' => 'Invalid payment allocation.',
    ],

    // Treasury
    'treasury' => [
        'instrument_not_available' => 'Payment instrument is not available.',
        'insufficient_funds' => 'Insufficient funds in repository.',
        'transfer_completed' => 'Transfer completed successfully.',
        'transfer_recorded' => 'Repository transfer recorded successfully.',
        'adjustment_tolerance_account_missing' => 'Cannot post this adjustment: the chart of accounts has no account assigned to the \':purpose\' purpose. Go to Settings → Chart of Accounts and assign an account to this purpose, then try again.',
        'insufficient_repository_balance' => 'This repository\'s balance (:available :currency) is insufficient to record an outflow of :requested :currency; this repository does not allow a negative balance.',
    ],

    // Taxation
    'taxation' => [
        'cancel_refused_period_closed' => 'Document :document cannot be cancelled: its VAT period (:period) is closed, so the cancellation would move VAT out of a period that is already settled. Issue a credit note instead, or ask your accountant to reopen :period first.',
        'cancel_refused_period_filed' => 'Document :document cannot be cancelled: its VAT period (:period) has already been filed with the tax authority. A filed period can never be reopened — issue a credit note instead.',
    ],

    // Inventory
    'inventory' => [
        'adjustment_recorded' => 'Stock adjustment recorded successfully.',
        'transfer_completed' => 'Stock transfer completed successfully.',
        'insufficient_stock' => 'Insufficient stock available.',
    ],

    // Workshop
    'workshop' => [
        'work_order_created' => 'Work order created successfully.',
        'work_order_completed' => 'Work order completed successfully.',
        'work_order_cancelled' => 'Work order cancelled successfully.',
    ],
];
