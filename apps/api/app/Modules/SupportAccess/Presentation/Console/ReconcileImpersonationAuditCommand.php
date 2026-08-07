<?php

declare(strict_types=1);

namespace App\Modules\SupportAccess\Presentation\Console;

use App\Modules\SupportAccess\Application\Services\AuditMirrorDeliveryService;
use Illuminate\Console\Command;

final class ReconcileImpersonationAuditCommand extends Command
{
    protected $signature = 'support-access:audit-reconcile {--limit=100}';

    protected $description = 'Retry pending impersonation audit mirror deliveries';

    public function __construct(private readonly AuditMirrorDeliveryService $delivery)
    {
        parent::__construct();
    }

    public function handle(): int
    {
        $limit = filter_var($this->option('limit'), FILTER_VALIDATE_INT);
        if (! is_int($limit) || $limit < 1 || $limit > 1000) {
            $this->error('--limit must be an integer between 1 and 1000.');

            return self::FAILURE;
        }

        $result = $this->delivery->reconcilePending($limit);
        $this->info("Reconciled {$result->reconciled} of {$result->attempted} audit delivery record(s).");
        if ($result->failed > 0) {
            $this->error("{$result->failed} audit delivery record(s) failed; {$result->pending} remain pending.");

            return self::FAILURE;
        }
        if ($result->pending > 0) {
            $this->warn("{$result->pending} audit delivery record(s) remain pending for the next scheduled pass.");
        }

        return self::SUCCESS;
    }
}
