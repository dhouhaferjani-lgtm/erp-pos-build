<?php

declare(strict_types=1);

return [
    'provisioning_enabled' => (bool) env('COUNTRY_DEFAULTS_PROVISIONING_ENABLED', false),
    'external_editors_enabled' => (bool) env('COUNTRY_DEFAULTS_EXTERNAL_EDITORS_ENABLED', false),
];
