<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Illuminate\Cache\CacheManager;
use Illuminate\Console\Command;
use Illuminate\Contracts\Cache\Store;

final class VerifyCacheStoreCommand extends Command
{
    protected $signature = 'cache:verify-store';

    protected $description = 'Fail when the default cache store cannot serve tenant-tagged operations';

    public function __construct(private readonly CacheManager $cache)
    {
        parent::__construct();
    }

    public function handle(): int
    {
        $default = (string) config('cache.default');
        /** @var Store $store */
        $store = $this->cache->store($default)->getStore();
        if (! method_exists($store, 'tags')) {
            $this->error(sprintf("Cache store '%s' does not support tags; set CACHE_STORE=redis.", $default));

            return self::FAILURE;
        }
        $this->info(sprintf("Cache store '%s' supports tags.", $default));

        return self::SUCCESS;
    }
}
