<?php

declare(strict_types=1);

return [

    /*
    |----------------------------------------------------------------------
    | N-6 — printout marker for a confirmed (not yet posted) fiscal document
    |----------------------------------------------------------------------
    | Dotted keys on purpose. The documents blade tree calls `__()` with
    | ENGLISH NATURAL keys everywhere else (`__('Tax ID')`, `__('Qty')`), and
    | no `lang/*.json` file exists, so every one of those renders as literal
    | English regardless of locale. Only dotted keys reach these PHP arrays
    | and actually translate — and this line has to translate.
    */
    /*
    |----------------------------------------------------------------------
    | C-F0 / SPEC §2.4 (F-13, F-64, F-95) — le rendu PROFORMA
    |----------------------------------------------------------------------
    | Voir `lang/en/documents.php` pour la règle et sa raison. Aucune mention de
    | taxe, aucun scellement, aucune formule de comptabilisation ici : le test
    | `ProformaOutputTest` balaie le rendu français à la recherche de chacun de
    | ces termes.
    */
    'proforma' => [
        'title' => 'Proforma — document non fiscal',
        'detail' => "Il s'agit d'une estimation établie avant l'enregistrement de la vente dans les comptes. Ce n'est pas un document fiscal définitif, il ne porte aucun scellement et n'ouvre aucun droit à déduction. Un document définitif sera émis une fois la vente enregistrée.",
        'estimated_total' => 'Total estimé',
        // Gate r2 §3 (R-8) — mots de droit, jamais de taxe. Voir lang/en.
        'stamp_duty' => 'Droit de timbre',
        'discount' => 'Remise',
        'adjustment' => 'Ajustement',
    ],

    // Fix round r3 (F-C3) — rendu uniquement sur un avoir DÉFINITIF, jamais sur un
    // proforma : celui-ci ne réduit aucun solde. Voir lang/en/documents.php.
    'credit_note' => [
        'balance_note' => 'Cet avoir réduit votre solde du montant indiqué ci-dessus.',
    ],

    'posting_marker' => [
        'cancelled_title' => 'Annulée — ce document a été annulé',
        'cancelled_detail' => 'Ce document a été comptabilisé et scellé, puis annulé. Son scellement fiscal demeure dans la chaîne de hachage ; le document lui-même est nul et ne peut être utilisé comme justificatif.',
        'cancelled_unsealed_detail' => "Ce document a été annulé et ne peut être utilisé comme justificatif. Il n'a jamais été comptabilisé et ne porte aucun scellement fiscal.",
        'historical_title' => "Solde d'ouverture — repris d'un système précédent",
        'historical_detail' => "Ce document constate un solde déjà existant à l'ouverture des comptes ici. Il a été comptabilisé dans le système précédent et ne porte aucun scellement fiscal dans celui-ci.",
    ],

    'discount' => [
        'below_tolerance' => "La remise doit dépasser la marge de tolérance (:margin). Pour des résidus plus petits, utilisez l'écriture de tolérance de paiement au règlement.",
        'amount_exceeds_line_gross' => 'Le montant de la remise ne peut pas dépasser le montant brut de la ligne (:gross).',
    ],
    'bonus_quantity' => [
        'sub_row' => 'dont gratuité : +:quantity unité gratuite',
        'line_total' => 'Total ligne: :quantity unités livrées attendues',
    ],
    'guided_delivery' => [
        'fefo_allocation_failed' => "L'allocation FEFO automatique a échoué. Confirmez la livraison manuellement et choisissez explicitement le lot.",
    ],

    /*
     | Campagne N-2 — refus « stock insuffisant » (code INSUFFICIENT_STOCK).
     */
    'stock' => [
        'insufficient' => 'Stock insuffisant pour « :product » à « :location ». Disponible : :available, demandé : :requested. Réceptionnez ou transférez la marchandise avant de confirmer.',
    ],

    'purchase_order' => [
        'line_unpriced' => 'La ligne :line (« :description ») n\'a pas de prix unitaire. Saisissez le prix du fournisseur sur chaque ligne avant de confirmer ce bon de commande : confirmer à 0,000 ferait entrer la marchandise en stock à valeur nulle et fausserait votre valorisation.',
    ],
    'pre_delivery_invoicing' => [
        'refused' => "Cette facture contient des marchandises qui n'ont pas été livrées. Selon les règles comptables de ce pays, une facture définitive de marchandises ne peut pas être émise avant la livraison : elle ne peut donc pas encore être comptabilisée.",
        'alternative_delivery_note' => 'Créez et confirmez maintenant un bon de livraison pour les marchandises, puis comptabilisez la facture.',
        'alternative_advance_payment' => "Si le client paie d'avance, enregistrez un devis ou une commande et saisissez le règlement comme acompte client. Facturez une fois les marchandises livrées.",
        'legacy_bucket_label' => 'Facturé avant livraison (historique / antérieur à la règle)',
        'legacy_bucket_help' => "Factures comptabilisées avant la livraison des marchandises. Les nouvelles factures ne peuvent plus être comptabilisées ainsi ; cette liste est un registre d'exceptions pour les documents antérieurs à la règle.",
        'policy_in_force' => 'Règle en vigueur : :policy (source : :source)',
    ],
    'to_bill_queue' => [
        'no_active_location_in_scope' => "Aucun emplacement actif n'est disponible dans votre périmètre autorisé ; sélectionnez un emplacement explicitement.",
    ],
];
