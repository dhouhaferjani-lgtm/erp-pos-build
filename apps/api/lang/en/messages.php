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
        'adjustment_amount_below_currency_precision' => 'The amount :amount is smaller than the smallest unit of :currency, which is recorded with :decimals decimal place(s). Enter an amount of at least one unit.',
        'repository_not_seeded' => ':repository (:code) has never held money, so there is no balance to adjust. If you are entering an opening cash float, it is an accounting opening balance: enter it in Settings → Opening balances, on a debit line for this repository\'s own cash account, putting :code in the repository_code column — that posts the ledger entry and the till balance together. Recording it here would book it as income instead.',
    ],

    // Taxation
    'taxation' => [
        'cancel_refused_period_closed' => 'Document :document cannot be cancelled: its VAT period (:period) is closed, so the cancellation would move VAT out of a period that is already settled. Issue a credit note instead, or ask your accountant to reopen :period first.',
        'cancel_refused_period_filed' => 'Document :document cannot be cancelled: its VAT period (:period) has already been filed with the tax authority. A filed period can never be reopened — issue a credit note instead.',
        // Plan CF CF-D3. A return note dated into a period that is no longer open.
        // Distinct from the cancel refusals above: the obstacle is the DATE the user
        // typed, not the invoice, so the remedy is to change the date rather than to
        // issue a credit note.
        'return_refused_period_closed' => 'Return note :document cannot be dated :date: the VAT period covering that date (:period) is closed, so the return would move VAT out of a period that is already settled. Choose a date in an open period, or ask your accountant to reopen :period first.',
        'return_refused_period_filed' => 'Return note :document cannot be dated :date: the VAT period covering that date (:period) has already been filed with the tax authority and can never be reopened. Choose a date in an open period.',
        'return_refused_period_locked' => 'Return note :document cannot be dated :date: the accounting period covering that date is closed or locked. Choose a date in an open period, or ask your accountant to reopen the period first.',
    ],

    // Document — plan CF (guided cancel flow)
    'document' => [
        'return_decision_forbidden' => 'You can cancel this invoice, but not record the goods return it needs (missing permission: :ability). Ask a manager to complete the return, or cancel without a return.',
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
