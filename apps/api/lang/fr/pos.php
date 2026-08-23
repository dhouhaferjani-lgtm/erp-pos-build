<?php

declare(strict_types=1);

return [
    'receipt' => 'Reçu',
    'tax_id' => 'N° TVA',
    'tel' => 'Tél',
    'receipt_no' => 'Reçu N°',
    'date_time' => 'Date/Heure',
    'terminal' => 'Terminal',
    'cashier' => 'Caissier',
    'customer' => 'Client',
    'discount' => 'Remise',
    'subtotal' => 'Sous-total',
    'tax' => 'Taxe',
    'total' => 'TOTAL',
    'cash_rounding' => 'Arrondi Espèces',
    'vat_breakdown' => 'Détail TVA',
    'vat' => 'TVA',
    'base' => 'Base',
    'payment_methods' => 'Moyens de Paiement',
    'card' => 'Carte',
    'voucher' => 'Bon',
    'change_given' => 'Monnaie Rendue',
    'fiscal_information' => 'Informations Fiscales',
    'chain_sequence' => 'N° Séquence',
    'fiscal_hash' => 'Hash Fiscal',
    'previous_hash' => 'Hash Précédent',
    'return_receipt' => 'RETOUR',
    'original_receipt' => 'Ticket Original',
    'return_reason' => 'Motif',
    'duplicate_notice' => 'Ceci est un duplicata — ne vaut pas comme original',
    'thank_you' => 'Merci !',
    'powered_by' => 'Propulsé par AutoERP',

    /*
     * Rapport Z (resources/views/pos/z-report.blade.php) — voir le commentaire
     * de la version anglaise : les clés `z_report_*` étaient référencées par la
     * vue sans jamais avoir été définies, le PDF fiscal imprimait donc la clé
     * brute. Ajout purement additif.
     */
    'generated_by' => 'Généré par',
    'z_report' => 'Rapport Z',
    'z_report_number' => 'Rapport n°',
    'z_report_z_number' => 'Numéro Z',
    'z_report_genesis' => 'Premier rapport Z de ce terminal (genèse de la chaîne)',
    'z_report_sales_summary' => 'Récapitulatif des ventes',
    'z_report_receipt_count' => 'Tickets',
    'z_report_gross_sales' => 'Ventes brutes',
    'z_report_net_sales' => 'Ventes nettes',
    'z_report_tax_amount' => 'TVA',
    'z_report_refunds' => 'Remboursements',
    'z_report_voided' => 'Annulés',
    'z_report_average_ticket' => 'Panier moyen',
    'z_report_cash_summary' => 'Récapitulatif caisse',
    'z_report_opening_cash' => 'Fond de caisse',
    'z_report_expected_cash' => 'Espèces attendues',
    'z_report_actual_cash' => 'Espèces comptées',
    'z_report_variance' => 'Écart',
    'z_report_vat_rate' => 'Taux',
    'z_report_vat_net' => 'HT',
    'z_report_vat_amount' => 'TVA',
    'z_report_vat_gross' => 'TTC',
    'z_report_payment_method' => 'Moyen',
    'z_report_payment_count' => 'Nombre',
    'z_report_payment_amount' => 'Montant',

    // B-6(ii) — ventilation de la TVA sur remboursements.
    'z_report_vat_on_sales' => 'TVA sur ventes',
    'z_report_vat_on_refunds' => 'TVA sur remboursements',
    'z_report_net_vat' => 'TVA nette',
    'z_report_vat_net_of_refunds' => 'nette des remboursements',
    'z_report_refund_vat_breakdown' => 'Détail TVA des remboursements',
    'z_report_vat_unreconciled' => 'Les montants de TVA n’ont pas pu être rapprochés pour cette période',
];
