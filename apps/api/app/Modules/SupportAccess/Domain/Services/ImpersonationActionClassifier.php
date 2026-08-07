<?php

declare(strict_types=1);

namespace App\Modules\SupportAccess\Domain\Services;

use App\Modules\SupportAccess\Domain\Enums\ImpersonationActionDecision;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Http\Request;
use LogicException;

final class ImpersonationActionClassifier
{
    public function __construct(private readonly Repository $config) {}

    public function classify(Request $request): ImpersonationActionDecision
    {
        if ($request->isMethodSafe()) {
            return ImpersonationActionDecision::Safe;
        }

        $routeName = $request->route()?->getName();
        if (is_string($routeName) && in_array($routeName, $this->stringList('write_guard.readonly_post_routes'), true)) {
            return ImpersonationActionDecision::Safe;
        }

        if ($this->matchesRouteName($routeName)
            || $this->matchesPath('/'.$request->path())
            || $this->usesProtectedFinancialController($request)) {
            return ImpersonationActionDecision::HardBlocked;
        }

        return ImpersonationActionDecision::RequiresElevation;
    }

    private function matchesRouteName(?string $routeName): bool
    {
        if ($routeName === null) {
            return false;
        }

        foreach ($this->stringList('write_guard.hard_block_route_patterns') as $pattern) {
            if (fnmatch($pattern, $routeName, FNM_NOESCAPE)) {
                return true;
            }
        }

        return false;
    }

    private function matchesPath(string $path): bool
    {
        foreach ($this->stringList('write_guard.hard_block_path_patterns') as $pattern) {
            if (@preg_match($pattern, $path) === 1) {
                return true;
            }
        }

        return false;
    }

    private function usesProtectedFinancialController(Request $request): bool
    {
        $controller = $request->route()?->getActionName();
        if (! is_string($controller)) {
            return false;
        }

        foreach (['\\Modules\\POS\\', '\\Modules\\Fiscal\\', '\\Modules\\Accounting\\'] as $namespace) {
            if (str_contains($controller, $namespace)) {
                return true;
            }
        }

        return false;
    }

    /** @return list<string> */
    private function stringList(string $key): array
    {
        $value = $this->config->get('support_access.'.$key, []);
        if (! is_array($value)) {
            throw new LogicException("support_access.{$key} must be a list of non-empty strings.");
        }

        foreach ($value as $item) {
            if (! is_string($item) || $item === '') {
                throw new LogicException("support_access.{$key} must be a list of non-empty strings.");
            }
        }

        return array_values($value);
    }
}
