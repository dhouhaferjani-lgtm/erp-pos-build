<?php

declare(strict_types=1);

namespace App\Modules\Identity\Presentation\Rules;

use App\Modules\Identity\Domain\User;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Translation\PotentiallyTranslatedString;
use Spatie\Permission\Models\Role;

/**
 * Validates that the acting user may assign the given role — i.e. the role's
 * permission set is a subset of the actor's own permissions. This prevents a
 * delegated user (e.g. one holding `users.create`) from minting or promoting
 * an account into a higher-privileged role such as `admin`.
 *
 * Go-live security audit 2026-06-14, Finding #2 (privilege escalation).
 */
final class AssignableRole implements ValidationRule
{
    public function __construct(private readonly ?User $actor) {}

    /**
     * @param  Closure(string): PotentiallyTranslatedString  $fail
     */
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if ($this->actor === null) {
            $fail('You are not authorized to assign roles.');

            return;
        }

        if (! is_string($value)) {
            return; // type/exists handled by the other rules on the field
        }

        $role = Role::where('name', $value)->first();

        if ($role === null) {
            return; // `exists:roles,name` reports the missing role
        }

        $rolePermissions = $role->permissions->pluck('name');
        $actorPermissions = $this->actor->getAllPermissions()->pluck('name');

        if ($rolePermissions->diff($actorPermissions)->isNotEmpty()) {
            $fail('You cannot assign a role that grants permissions beyond your own.');
        }
    }
}
