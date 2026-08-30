<?php

declare(strict_types=1);

namespace Tests\Unit\Shared\Database;

use App\Shared\Database\MigrationOutput;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

final class MigrationOutputTest extends TestCase
{
    public function test_info_logs_once_without_writing_stdout_during_unit_tests(): void
    {
        self::assertTrue(class_exists(MigrationOutput::class), 'MigrationOutput must centralize migration census output.');

        Log::shouldReceive('info')->once()->with('x');
        $this->expectOutputString('');

        MigrationOutput::info('x');
    }

    public function test_error_logs_once_without_writing_stdout_during_unit_tests(): void
    {
        self::assertTrue(class_exists(MigrationOutput::class), 'MigrationOutput must centralize migration census output.');

        Log::shouldReceive('error')->once()->with('x');
        $this->expectOutputString('');

        MigrationOutput::error('x');
    }

    public function test_info_writes_one_line_when_running_in_console_outside_unit_tests(): void
    {
        self::assertTrue(class_exists(MigrationOutput::class), 'MigrationOutput must centralize migration census output.');

        App::shouldReceive('runningInConsole')->once()->andReturnTrue();
        App::shouldReceive('runningUnitTests')->once()->andReturnFalse();
        Log::shouldReceive('info')->once()->with('x');
        $this->expectOutputString("x\n");

        MigrationOutput::info('x');
    }
}
