<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Console\Command;
use Illuminate\Filesystem\Filesystem;
use LogicException;

final class ExportFrontendPermissionsMap extends Command
{
    protected $signature = 'permissions:export-frontend-map
        {--path= : Override the generated TypeScript output path}';

    protected $description = 'Export the seeder-owned role grants as a deterministic frontend permission map.';

    public function __construct(private readonly Filesystem $files)
    {
        parent::__construct();
    }

    public function handle(): int
    {
        $pathOption = $this->option('path');
        $outputPath = $pathOption ?? base_path('../web/src/hooks/permissionsMap.generated.ts');
        $contents = $this->render(
            RolesAndPermissionsSeeder::permissionNames(),
            RolesAndPermissionsSeeder::rolePermissionGrants(),
        );

        $this->files->ensureDirectoryExists(dirname($outputPath));
        if ($this->files->put($outputPath, $contents) === false) {
            $this->error('Unable to write the frontend permission map to '.$outputPath.'.');

            return self::FAILURE;
        }

        $this->info('Exported frontend permission map to '.$outputPath.'.');

        return self::SUCCESS;
    }

    /**
     * @param  list<string>  $permissionNames
     * @param  array<string, list<string>>  $rolePermissionGrants
     */
    private function render(array $permissionNames, array $rolePermissionGrants): string
    {
        /** @var array<string, list<string>> $permissionRoles */
        $permissionRoles = [];
        foreach ($permissionNames as $permissionName) {
            $permissionRoles[$permissionName] = [];
        }

        foreach ($rolePermissionGrants as $roleName => $grantedPermissions) {
            foreach ($grantedPermissions as $permissionName) {
                if (! array_key_exists($permissionName, $permissionRoles)) {
                    throw new LogicException(sprintf(
                        'Role "%s" grants unknown permission "%s".',
                        $roleName,
                        $permissionName,
                    ));
                }

                $permissionRoles[$permissionName][] = $roleName;
            }
        }

        ksort($permissionRoles, SORT_STRING);

        $lines = ['export const PERMISSIONS = {'];
        foreach ($permissionRoles as $permissionName => $roleNames) {
            sort($roleNames, SORT_STRING);
            $renderedRoles = implode(', ', array_map($this->quote(...), $roleNames));
            $lines[] = sprintf('  %s: [%s],', $this->quote($permissionName), $renderedRoles);
        }
        $lines[] = '} as const';
        $lines[] = '';
        $lines[] = 'export type Permission = keyof typeof PERMISSIONS';

        $body = implode("\n", $lines)."\n";
        $hashedBody = "\n".$body;

        return implode("\n", [
            '// This file is generated. Do not edit it by hand.',
            '// Source: apps/api/database/seeders/RolesAndPermissionsSeeder.php',
            '// Source hash: sha256:'.hash('sha256', $hashedBody),
        ])."\n".$hashedBody;
    }

    private function quote(string $value): string
    {
        return "'".str_replace(['\\', "'"], ['\\\\', "\\'"], $value)."'";
    }
}
