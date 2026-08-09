<?php

declare(strict_types=1);

return [
    /*
     * Labels of the two repositories a freshly registered tenant is born with
     * (DPA lane H-3). Read by PaymentRepositorySeeder under the registering
     * company's locale — a tenant must never be handed English defaults it did
     * not ask for. The operator renames them freely afterwards; these are
     * creation-time labels, not a runtime lookup.
     *
     * Terminology matches the web app's Arabic treasury bundle
     * (apps/web/src/locales/ar/treasury.json): صندوق نقدي = cash register,
     * خزنة = safe.
     */
    'default_repositories' => [
        'cash_register' => 'الصندوق النقدي الرئيسي',
        'safe' => 'خزنة المكتب',
    ],
];
