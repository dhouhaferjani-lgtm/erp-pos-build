<?php

declare(strict_types=1);

namespace App\Modules\BatchExpiry\Application\Services;

use Illuminate\Contracts\Config\Repository;

final readonly class LotActionPermissionActivation
{
    public function __construct(private readonly Repository $config) {}

    public function enforced(): bool
    {
        return $this->config->get('lot_action_permissions.enforce', false) === true;
    }
}
