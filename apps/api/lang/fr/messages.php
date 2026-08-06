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
    'success' => 'Opération effectuée avec succès.',
    'error' => 'Une erreur est survenue.',
    'not_found' => 'Ressource introuvable.',
    'resource_not_found' => ':resource introuvable.',
    'forbidden' => 'Vous n\'avez pas la permission d\'effectuer cette action.',
    'server_error' => 'Erreur interne du serveur. Veuillez réessayer plus tard.',

    // CRUD operations
    'created' => ':resource créé(e) avec succès.',
    'updated' => ':resource mis(e) à jour avec succès.',
    'deleted' => ':resource supprimé(e) avec succès.',
    'restored' => ':resource restauré(e) avec succès.',
    'income_posted' => 'Revenu enregistré avec succès.',
    'expense_settled' => 'Dépense réglée avec succès.',

    // Documents
    'document' => [
        'posted' => 'Document validé avec succès.',
        'cancelled' => 'Document annulé avec succès.',
        'confirmed' => 'Document confirmé avec succès.',
        'cannot_edit_posted' => 'Les documents validés ne peuvent pas être modifiés.',
        'cannot_delete_posted' => 'Les documents validés ne peuvent pas être supprimés.',
        'already_posted' => 'Ce document a déjà été validé.',
        'invalid_status_transition' => 'Transition de statut invalide.',
    ],

    // Partners
    'partner' => [
        'has_documents' => 'Impossible de supprimer un partenaire ayant des documents associés.',
        'has_balance' => 'Impossible de supprimer un partenaire ayant un solde en cours.',
    ],

    // Products
    'product' => [
        'has_stock' => 'Impossible de supprimer un produit ayant du stock.',
        'has_documents' => 'Impossible de supprimer un produit ayant des documents associés.',
        'insufficient_stock' => 'Stock insuffisant pour :product. Disponible : :available, Demandé : :requested.',
    ],

    // Payments
    'payment' => [
        'recorded' => 'Paiement enregistré avec succès.',
        'cancelled' => 'Paiement annulé avec succès.',
        'amount_exceeds_balance' => 'Le montant du paiement dépasse le solde restant dû.',
        'invalid_allocation' => 'Allocation de paiement invalide.',
    ],

    // Treasury
    'treasury' => [
        'instrument_not_available' => 'L\'instrument de paiement n\'est pas disponible.',
        'insufficient_funds' => 'Fonds insuffisants dans le dépôt.',
        'transfer_completed' => 'Transfert effectué avec succès.',
        'transfer_recorded' => 'Transfert entre dépôts enregistré avec succès.',
        'adjustment_tolerance_account_missing' => 'Impossible d\'enregistrer cet ajustement : le plan comptable n\'a aucun compte assigné à l\'usage \':purpose\'. Allez dans Paramètres → Plan comptable pour assigner un compte à cet usage, puis réessayez.',
        'insufficient_repository_balance' => 'Le solde de ce dépôt (:available :currency) est insuffisant pour enregistrer une sortie de :requested :currency ; ce dépôt n\'autorise pas un solde négatif.',
    ],

    // Inventory
    'inventory' => [
        'adjustment_recorded' => 'Ajustement de stock enregistré avec succès.',
        'transfer_completed' => 'Transfert de stock effectué avec succès.',
        'insufficient_stock' => 'Stock insuffisant disponible.',
    ],

    // Workshop
    'workshop' => [
        'work_order_created' => 'Ordre de travail créé avec succès.',
        'work_order_completed' => 'Ordre de travail terminé avec succès.',
        'work_order_cancelled' => 'Ordre de travail annulé avec succès.',
    ],
];
