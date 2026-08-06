<?php

declare(strict_types=1);

return [
    'discount' => [
        'below_tolerance' => "La remise doit dépasser la marge de tolérance (:margin). Pour des résidus plus petits, utilisez l'écriture de tolérance de paiement au règlement.",
        'amount_exceeds_line_gross' => 'Le montant de la remise ne peut pas dépasser le montant brut de la ligne (:gross).',
    ],
    'bonus_quantity' => [
        'sub_row' => 'dont gratuité : +:quantity unité gratuite',
        'line_total' => 'Total ligne: :quantity unités livrées attendues',
    ],
];
