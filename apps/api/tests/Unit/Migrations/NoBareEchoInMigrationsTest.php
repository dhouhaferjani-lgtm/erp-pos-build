<?php

declare(strict_types=1);

namespace Tests\Unit\Migrations;

use FilesystemIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;
use Tests\TestCase;

final class NoBareEchoInMigrationsTest extends TestCase
{
    public function test_migrations_do_not_write_directly_to_stdout(): void
    {
        $root = database_path('migrations');
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS),
        );
        $offenders = [];

        /** @var SplFileInfo $file */
        foreach ($iterator as $file) {
            if (! $file->isFile() || $file->getExtension() !== 'php') {
                continue;
            }

            $contents = file_get_contents($file->getPathname());
            self::assertIsString($contents, 'Unable to read migration '.$file->getPathname());

            if (preg_match_all('/^\s*(?:echo|print|printf)\b|fwrite\s*\(\s*STDOUT/m', $contents, $matches, PREG_OFFSET_CAPTURE) < 1) {
                continue;
            }

            foreach ($matches[0] as [$match, $offset]) {
                $line = substr_count(substr($contents, 0, $offset), "\n") + 1;
                $offenders[] = str_replace($root.'/', '', $file->getPathname()).':'.$line.': '.trim($match);
            }
        }

        self::assertSame(
            [],
            $offenders,
            "Migrations must route output through MigrationOutput; direct stdout corrupts synchronous HTTP responses:\n"
                .implode("\n", $offenders),
        );
    }
}
