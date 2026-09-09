<?php

declare(strict_types=1);

namespace Tests\Feature\Identity;

use App\Modules\Identity\Presentation\Controllers\RoleController;
use App\Modules\Identity\Presentation\Controllers\UserController;
use ReflectionMethod;
use Tests\TestCase;

final class GeneralManagerAssignmentWriterCensusTest extends TestCase
{
    public function test_runtime_general_manager_assignment_sites_use_the_guard_and_team_boundary(): void
    {
        foreach ([[UserController::class, 'store'], [UserController::class, 'update'], [RoleController::class, 'assignRole']] as [$class, $method]) {
            $reflection = new ReflectionMethod($class, $method);
            $source = implode('', array_slice(file($reflection->getFileName()), $reflection->getStartLine() - 1, $reflection->getEndLine() - $reflection->getStartLine() + 1));
            foreach (['getPermissionsTeamId()', 'setPermissionsTeamId(', 'DB::transaction(', 'finally', 'lockForUpdate()'] as $boundary) {
                self::assertStringContainsString($boundary, $source, $class.'::'.$method.' requires '.$boundary);
            }
            self::assertGreaterThanOrEqual(2, substr_count($source, '$this->generalManagerAssignmentGuard->'), $class.'::'.$method.' needs final-state validation and a postcondition');
        }
    }
}
