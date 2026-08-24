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
    'posting_marker' => [
        'title' => 'Non comptabilisée — aucun scellement fiscal',
        'detail' => "Ce document n'a pas été comptabilisé. Il ne porte aucun scellement fiscal ni entrée dans la chaîne de hachage, et ne constitue pas une facture fiscale définitive.",
        'cancelled_title' => "Annulée — ce document a été annulé",
        'cancelled_detail' => "Ce document a été comptabilisé et scellé, puis annulé. Son scellement fiscal demeure dans la chaîne de hachage ; le document lui-même est nul et ne peut être utilisé comme justificatif.",
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
