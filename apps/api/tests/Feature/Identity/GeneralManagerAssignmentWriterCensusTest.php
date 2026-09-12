<?php

declare(strict_types=1);

namespace Tests\Feature\Identity;

use App\Modules\Identity\Presentation\Controllers\RoleController;
use App\Modules\Identity\Presentation\Controllers\UserController;
use ReflectionMethod;
use Tests\TestCase;

final class GeneralManagerAssignmentWriterCensusTest extends TestCase
{
    public function test_all_runtime_role_assignment_writers_are_classified(): void
    {
        $writers = [];
        $pivotReferences = [];
        $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(app_path()));
        foreach ($files as $file) {
            if (! $file->isFile() || $file->getExtension() !== 'php') {
                continue;
            }
            // Tokenization excludes comments/docblocks, so documentation cannot
            // satisfy or accidentally grow the executable writer inventory.
            $source = '';
            foreach (token_get_all(file_get_contents($file->getPathname())) as $token) {
                if (is_array($token)) {
                    if (! in_array($token[0], [T_COMMENT, T_DOC_COMMENT], true)) {
                        $source .= $token[1];
                    }
                } else {
                    $source .= $token;
                }
            }
            $relative = substr($file->getPathname(), strlen(app_path()) + 1);
            preg_match_all('/->(assignRole|syncRoles)\s*\(([^;]+);/s', $source, $matches, PREG_SET_ORDER);
            foreach ($matches as $match) {
                $writers[] = $relative.':'.preg_replace('/\s+/', '', $match[0]);
            }
            if (str_contains($source, 'model_has_roles')) {
                $pivotReferences[] = $relative;
            }
            self::assertDoesNotMatchRegularExpression('/->roles\(\)->(?:attach|sync|syncWithoutDetaching|save|create)\s*\(/', $source, $relative);
        }
        sort($writers);
        self::assertSame([
            'Modules/Identity/Presentation/Controllers/RoleController.php:->assignRole($roleName);',
            "Modules/Identity/Presentation/Controllers/UserController.php:->assignRole(\$validated['role']);",
            "Modules/Identity/Presentation/Controllers/UserController.php:->syncRoles([\$validated['role']]);",
            "Modules/Tenant/Application/Commands/ResetTenantCommand.php:->assignRole('admin');",
            "Modules/Tenant/Application/Services/TenantInitializationService.php:->assignRole('admin');",
        ], $writers);
        // The only direct pivot reference is the existing read-only user count.
        self::assertSame(['Modules/Identity/Presentation/Controllers/RoleController.php'], $pivotReferences);
        $count = new ReflectionMethod(RoleController::class, 'countUsersForRole');
        $body = implode('', array_slice(file($count->getFileName()), $count->getStartLine() - 1, $count->getEndLine() - $count->getStartLine() + 1));
        self::assertStringContainsString('->count(', $body);
        self::assertDoesNotMatchRegularExpression('/->(?:insert|update|upsert|delete)\s*\(/', $body);
    }

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
