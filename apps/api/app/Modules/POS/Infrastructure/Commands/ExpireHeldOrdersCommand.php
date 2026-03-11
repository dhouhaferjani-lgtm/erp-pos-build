<?php

declare(strict_types=1);

namespace App\Modules\POS\Infrastructure\Commands;

use App\Modules\POS\Application\Services\HeldOrderService;
use Illuminate\Console\Command;

/**
 * Artisan command to expire held orders that have passed their expiry time.
 *
 * Intended to be scheduled every 15 minutes to clean up stale held orders.
 */
final class ExpireHeldOrdersCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'pos:expire-held-orders';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Expire held POS orders that have passed their expiry time';

    public function __construct(
        private readonly HeldOrderService $heldOrderService,
    ) {
        parent::__construct();
    }

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $count = $this->heldOrderService->expireOrders();

        if ($count > 0) {
            $this->info("Expired {$count} held order(s).");
        } else {
            $this->info('No held orders to expire.');
        }

        return self::SUCCESS;
    }
}
