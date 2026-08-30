<?php

declare(strict_types=1);

namespace App\Shared\Database;

use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\Log;

/** census output must never reach an HTTP response body — tenant migrations run inside registration via dispatchSync. */
final class MigrationOutput
{
    public static function info(string $message): void
    {
        Log::info($message);
        self::writeToConsole($message);
    }

    public static function error(string $message): void
    {
        Log::error($message);
        self::writeToConsole($message);
    }

    private static function writeToConsole(string $message): void
    {
        if (App::runningInConsole() && ! App::runningUnitTests()) {
            echo $message.PHP_EOL;
        }
    }
}
