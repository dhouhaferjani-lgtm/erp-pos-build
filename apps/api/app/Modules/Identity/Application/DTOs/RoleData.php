<?php

declare(strict_types=1);

namespace App\Modules\Identity\Application\DTOs;

use Spatie\TypeScriptTransformer\Attributes\TypeScript;

#[TypeScript]
final readonly class RoleData
{
    /**
     * @param  list<string>  $permissions
     */
    public function __construct(
        public readonly int $id,
        public readonly string $name,
        public readonly string $guard_name,
        public readonly array $permissions,
        public readonly int $users_count,
        public readonly ?string $created_at,
        public readonly ?string $updated_at,
        public readonly bool $is_provisioned_read_only,
    ) {}
}
