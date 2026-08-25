<?php

declare(strict_types=1);

return [
    'opening_requires_physical' => 'Opening balance can only be posted for physical products.',
    'no_active_location' => 'No active location found for this company. Please configure a location before posting an opening balance.',
    'opening_already_exists' => 'An active opening balance already exists for this product. Reset it before posting a new one.',
    'opening_locked_downstream' => 'This product already has inventory movements. Opening balance cannot be posted after live transactions.',
    // LEDGER C-14(iv). Operator-facing text for the typed 422s raised by the
    // counting lifecycle. Rendered by the `CountingTransitionException` handler in
    // bootstrap/app.php via `TRANSLATION_KEY` + `translationReplacements()`, the
    // same house pattern as `InsufficientStockForFulfilmentException`. The status
    // labels mirror `apps/web/src/locales/<locale>/inventory.json`
    // `counting.status.*` so one refusal reads the same on both sides.
    'counting' => [
        // Pluralised with `trans_choice` (gate r1 IMPORTANT-5): the count is
        // in the sentence and FR does not pluralise like EN.
        'unresolved_items' => 'Cannot finalize: :count item still pending resolution.|Cannot finalize: :count items still pending resolution.',
        'transition_refused' => 'This counting is :current and cannot move to :attempted.',
        'status' => [
            'draft' => 'Draft',
            'scheduled' => 'Scheduled',
            'count_1_in_progress' => 'Count 1 In Progress',
            'count_1_completed' => 'Count 1 Complete',
            'count_2_in_progress' => 'Count 2 In Progress',
            'count_2_completed' => 'Count 2 Complete',
            'count_3_in_progress' => 'Count 3 In Progress',
            'count_3_completed' => 'Count 3 Complete',
            'pending_review' => 'Pending Review',
            'finalized' => 'Finalized',
            'cancelled' => 'Cancelled',
        ],
    ],
];
