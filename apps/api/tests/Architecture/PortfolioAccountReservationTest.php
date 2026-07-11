<?php

declare(strict_types=1);

namespace Tests\Architecture;

use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;
use Tests\TestCase;

final class PortfolioAccountReservationTest extends TestCase
{
    public function test_portfolio_account_code_queries_are_reserved_to_the_resolver(): void
    {
        $appRoot = dirname(__DIR__, 2).'/app';
        $violations = [];

        $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($appRoot));
        foreach ($files as $file) {
            if (! $file instanceof SplFileInfo || ! $file->isFile() || $file->getExtension() !== 'php') {
                continue;
            }

            $path = $file->getPathname();
            $contents = file_get_contents($path);
            if ($contents === false) {
                continue;
            }

            if (preg_match("/where\\(\\s*['\"]code['\"]\\s*,\\s*['\"](?:5312|5313|5314|5112|5113|5114)['\"]\\s*\\)/", $contents) !== 1) {
                continue;
            }

            if (! str_ends_with($path, '/Modules/Treasury/Application/Services/InstrumentAccountResolver.php')) {
                $violations[] = str_replace(dirname(__DIR__, 2).'/', '', $path);
            }
        }

        $this->assertFileExists($appRoot.'/Modules/Treasury/Application/Services/InstrumentAccountResolver.php');
        $this->assertSame([], $violations, 'Portfolio account code queries escaped the reserved resolver: '.implode(', ', $violations));
    }
}
