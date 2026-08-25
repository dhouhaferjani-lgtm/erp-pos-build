<?php

declare(strict_types=1);

return [
    'opening_requires_physical' => "Le solde d'ouverture ne peut être enregistré que pour des produits physiques.",
    'no_active_location' => "Aucun emplacement actif trouvé pour cette entreprise. Veuillez configurer un emplacement avant de publier un solde d'ouverture.",
    'opening_already_exists' => "Un solde d'ouverture actif existe déjà pour ce produit. Réinitialisez-le avant d'en enregistrer un nouveau.",
    'opening_locked_downstream' => "Ce produit possède déjà des mouvements de stock. Le solde d'ouverture ne peut pas être enregistré après des transactions réelles.",
    // LEDGER C-14(iv). Operator-facing text for the typed 422s raised by the
    // counting lifecycle. Rendered by the `CountingTransitionException` handler in
    // bootstrap/app.php via `TRANSLATION_KEY` + `translationReplacements()`, the
    // same house pattern as `InsufficientStockForFulfilmentException`. The status
    // labels mirror `apps/web/src/locales/<locale>/inventory.json`
    // `counting.status.*` so one refusal reads the same on both sides.
    'counting' => [
        'transition_refused' => 'Ce comptage est « :current » et ne peut pas passer à « :attempted ».',
        'status' => [
            'draft' => 'Brouillon',
            'scheduled' => 'Planifié',
            'count_1_in_progress' => 'Comptage 1 en cours',
            'count_1_completed' => 'Comptage 1 terminé',
            'count_2_in_progress' => 'Comptage 2 en cours',
            'count_2_completed' => 'Comptage 2 terminé',
            'count_3_in_progress' => 'Comptage 3 en cours',
            'count_3_completed' => 'Comptage 3 terminé',
            'pending_review' => 'En attente de révision',
            'finalized' => 'Finalisé',
            'cancelled' => 'Annulé',
        ],
    ],
];
