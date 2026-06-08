<?php

declare(strict_types=1);

namespace App\Modules\Identity\Application\DTOs;

use App\Modules\Identity\Domain\User;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

#[TypeScript]
final readonly class UserData
{
    /**
     * @param  list<string>  $roles
     */
    public function __construct(
        public string $id,
        public string $name,
        public ?string $email,
        public ?string $phone,
        public string $status,
        public ?string $locale,
        public ?string $timezone,
        public array $roles,
        public ?string $emailVerifiedAt,
        public ?string $lastLoginAt,
        public ?string $lastLoginIp,
        public string $createdAt,
        public string $updatedAt,
        public ?bool $canDiscount,
        public ?string $maxDiscountPercent,
    ) {}

    public static function fromUser(User $user): self
    {
        // Set the team context for Spatie permissions before loading roles
        setPermissionsTeamId($user->tenant_id);

        /** @var list<string> $roles */
        $roles = $user->getRoleNames()->values()->all();

        return new self(
            id: $user->id,
            name: $user->name,
            email: $user->email,
            phone: $user->phone,
            status: $user->status->value,
            locale: $user->locale,
            timezone: $user->timezone,
            roles: $roles,
            emailVerifiedAt: $user->email_verified_at?->toIso8601String(),
            lastLoginAt: $user->last_login_at?->toIso8601String(),
            lastLoginIp: $user->last_login_ip,
            createdAt: $user->created_at->toIso8601String(),
            updatedAt: $user->updated_at->toIso8601String(),
            canDiscount: $user->can_discount,
            maxDiscountPercent: $user->max_discount_percent,
        );
    }
}
