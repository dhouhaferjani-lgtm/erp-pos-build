<?php

declare(strict_types=1);

return [
    'deferred_method_not_supported_on_this_path' => 'Deferred payment methods are not supported on this payment path.',

    /*
     * Labels of the two repositories a freshly registered tenant is born with
     * (DPA lane H-3). Read by PaymentRepositorySeeder under the registering
     * company's locale — a tenant must never be handed English defaults it did
     * not ask for. The operator renames them freely afterwards; these are
     * creation-time labels, not a runtime lookup.
     */
    'default_repositories' => [
        'cash_register' => 'Main Cash Register',
        'safe' => 'Office Safe',
    ],
];
