<?php

declare(strict_types=1);

namespace App\Modules\Identity\Application\DTOs;

use App\Modules\Identity\Domain\User;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

#[TypeScript]
final readonly class AuthUserData
{
    /**
     * @param  list<string>  $roles
     * @param  list<string>  $permissions
     */
    public function __construct(
        public string $id,
        public string $tenantId,
        public string $name,
        public ?string $email,
        public ?string $phone,
        public string $status,
        public ?string $locale,
        public ?string $timezone,
        public array $roles,
        public array $permissions,
        public bool $emailVerified,
    ) {}

    public static function fromUser(User $user): self
    {
        // Set the team context for Spatie permissions before loading roles/permissions
        setPermissionsTeamId($user->tenant_id);

        /** @var list<string> $roles */
        $roles = $user->getRoleNames()->values()->all();

        /** @var list<string> $permissions */
        $permissions = $user->getAllPermissions()->pluck('name')->values()->all();

        return new self(
            id: $user->id,
            tenantId: $user->tenant_id,
            name: $user->name,
            email: $user->email,
            phone: $user->phone,
            status: $user->status->value,
            locale: $user->locale,
            timezone: $user->timezone,
            roles: $roles,
            permissions: $permissions,
            emailVerified: $user->hasVerifiedEmail(),
        );
    }
}
