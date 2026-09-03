<?php

declare(strict_types=1);

namespace Tests\Unit\Console;

use Tests\TestCase;

final class VerifyCacheStoreCommandTest extends TestCase
{
    public function test_database_store_fails_tag_capability_check(): void
    {
        config(['cache.default' => 'database']);
        $this->artisan('cache:verify-store')->assertExitCode(1);
    }

    public function test_array_store_passes_tag_capability_check(): void
    {
        config(['cache.default' => 'array']);
        $this->artisan('cache:verify-store')->assertExitCode(0);
    }

    public function test_cache_config_defaults_to_redis_when_cache_store_is_unset(): void
    {
        $hadEnv = array_key_exists('CACHE_STORE', $_ENV);
        $previousEnv = $_ENV['CACHE_STORE'] ?? null;
        $hadServer = array_key_exists('CACHE_STORE', $_SERVER);
        $previousServer = $_SERVER['CACHE_STORE'] ?? null;
        $previousProcessValue = getenv('CACHE_STORE');

        try {
            putenv('CACHE_STORE');
            unset($_ENV['CACHE_STORE'], $_SERVER['CACHE_STORE']);

            /** @var array{default: string} $cacheConfig */
            $cacheConfig = require base_path('config/cache.php');
            self::assertSame('redis', $cacheConfig['default']);
        } finally {
            if ($hadEnv) {
                $_ENV['CACHE_STORE'] = $previousEnv;
            } else {
                unset($_ENV['CACHE_STORE']);
            }
            if ($hadServer) {
                $_SERVER['CACHE_STORE'] = $previousServer;
            } else {
                unset($_SERVER['CACHE_STORE']);
            }
            if ($previousProcessValue === false) {
                putenv('CACHE_STORE');
            } else {
                putenv('CACHE_STORE='.$previousProcessValue);
            }
        }
    }
}
