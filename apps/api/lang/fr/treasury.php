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
];
