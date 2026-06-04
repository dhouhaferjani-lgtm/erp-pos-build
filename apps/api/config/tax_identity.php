<?php

declare(strict_types=1);

return [
    'countries' => [
        'FR' => ['mode' => 'structural', 'branch_tax_id_required' => true],
        'TN' => ['mode' => 'structural', 'branch_tax_id_required' => true],
        'MA' => ['mode' => 'structural', 'branch_tax_id_required' => true],
        'DZ' => ['mode' => 'separate-linked', 'branch_tax_id_required' => false],
        'IT' => ['mode' => 'none', 'branch_tax_id_required' => false],
        'ES' => ['mode' => 'none', 'branch_tax_id_required' => false],
        'DE' => ['mode' => 'none', 'branch_tax_id_required' => false],
        'AE' => ['mode' => 'none', 'branch_tax_id_required' => false],
        'GB' => ['mode' => 'none', 'branch_tax_id_required' => false],
        'SA' => ['mode' => 'branch-code', 'branch_tax_id_required' => false],
        'EG' => ['mode' => 'branch-code', 'branch_tax_id_required' => false],
    ],
    'default' => ['mode' => 'none', 'branch_tax_id_required' => false],
];
