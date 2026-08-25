<?php

declare(strict_types=1);

return [
    'deferred_method_not_supported_on_this_path' => 'Les moyens de paiement différés ne sont pas pris en charge dans ce parcours.',

    /*
     * Labels of the two repositories a freshly registered tenant is born with
     * (DPA lane H-3). Read by PaymentRepositorySeeder under the registering
     * company's locale — a tenant must never be handed English defaults it did
     * not ask for. The operator renames them freely afterwards; these are
     * creation-time labels, not a runtime lookup.
     */
    'default_repositories' => [
        'cash_register' => 'Caisse principale',
        'safe' => 'Coffre-fort',
    ],
    /*
     * C-0a0 — motif de refus d'une affectation de paiement
     * (SPEC-document-lifecycle-dimensions §2.1, `AllocationRefusalReason`).
     * Rendu comme `message` d'un 422 `DOCUMENT_NOT_ALLOCATABLE` ; la valeur de
     * l'enum l'accompagne dans `details.reason`.
     */
    'allocation_refused' => [
        'document_not_live' => 'Ce document ne peut pas recevoir de paiement dans son statut actuel. Seuls les documents confirmés ou comptabilisés peuvent être payés.',
        'historical_opening_provenance' => "Il s'agit d'un solde d'ouverture fournisseur, ou d'un solde d'ouverture dont le sens n'a pas pu être déterminé. Les soldes d'ouverture fournisseurs ne peuvent pas encore être réglés — leur paiement arrivera dans une prochaine version.",
        'pos_derived_provenance' => "Cette facture provient d'une vente à crédit du point de vente. Elle se règle via le compte client, pas depuis cet écran.",
        'status_not_allocatable_for_type' => "Ce document ne peut pas recevoir de paiement dans son statut actuel. Comptabilisez-le d'abord, puis enregistrez le paiement.",
        'outward_document_type' => "Un avoir représente une somme due à l'autre partie : imputez-le sur un autre document ou remboursez-le, au lieu d'encaisser un paiement dessus.",
        'purchase_order_wrong_direction' => 'Une commande fournisseur ne peut pas recevoir de paiement. Enregistrez le paiement sur la facture fournisseur une fois celle-ci comptabilisée.',
        'type_never_allocatable' => "Ce type de document ne porte aucun solde qu'un paiement pourrait régler.",
        'payable_not_settleable_here' => "Il s'agit d'une facture fournisseur. Enregistrez le paiement via le parcours de paiement fournisseur, qui règle le fournisseur et solde la dette.",
    ],
];
