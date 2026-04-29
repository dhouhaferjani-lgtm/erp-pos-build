<?php

declare(strict_types=1);

namespace App\Modules\Voucher\Providers;

use Illuminate\Support\ServiceProvider;

class VoucherServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        //
    }

    public function boot(): void
    {
        $this->loadRoutesFrom(__DIR__.'/../Presentation/routes.php');
    }
}
