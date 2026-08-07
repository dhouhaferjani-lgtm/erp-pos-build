<?php

declare(strict_types=1);

namespace App\Modules\SupportAccess\Domain\Services;

use App\Modules\Identity\Domain\User;
use App\Modules\SupportAccess\Domain\Enums\SessionAccessLevel;
use Illuminate\Contracts\Config\Repository;

final class EffectivePermissionService
{
    /** @var list<string> */
    private const NEVER_INTERSECTABLE = [
        'support-access.*',
        'roles.*',
        'users.*',
        'auth.*',
    ];

    public function __construct(private readonly Repository $config) {}

    /**
     * @param  list<string>  $subjectPermissions
     * @return list<string>
     */
    public function intersect(array $subjectPermissions, SessionAccessLevel $level): array
    {
        $patterns = $this->patternsFor($level);
        if ($patterns === []) {
            return [];
        }

        $effective = array_filter(
            array_unique($subjectPermissions),
            static fn (string $permission): bool => ! self::matchesAny(self::NEVER_INTERSECTABLE, $permission)
                && self::matchesAny($patterns, $permission),
        );
        sort($effective);

        return $effective;
    }

    /** @return list<string> */
    public function forUser(User $subject, SessionAccessLevel $level): array
    {
        /** @var list<string> $permissions */
        $permissions = $subject->getUnfilteredPermissionsForSupportAccess()
            ->pluck('name')
            ->filter(static fn (mixed $name): bool => is_string($name))
            ->values()
            ->all();

        return $this->intersect($permissions, $level);
    }

    /** @return list<string> */
    private function patternsFor(SessionAccessLevel $level): array
    {
        $configured = $this->config->get('support_access.permissions.read_only', []);
        if (! is_array($configured)) {
            return [];
        }

        $patterns = $configured;
        if ($level === SessionAccessLevel::WriteElevated) {
            $write = $this->config->get('support_access.permissions.write', []);
            if (! is_array($write)) {
                return [];
            }
            $patterns = array_merge($patterns, $write);
        }

        foreach ($patterns as $pattern) {
            if (! is_string($pattern) || $pattern === '') {
                return [];
            }
        }

        /** @var list<string> $patterns */
        return array_values(array_unique($patterns));
    }

    /** @param list<string> $patterns */
    private static function matchesAny(array $patterns, string $permission): bool
    {
        foreach ($patterns as $pattern) {
            if (fnmatch($pattern, $permission, FNM_NOESCAPE)) {
                return true;
            }
        }

        return false;
    }
}
