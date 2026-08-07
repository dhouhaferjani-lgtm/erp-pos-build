<?php

declare(strict_types=1);

namespace App\Modules\SupportAccess\Presentation\Console;

use App\Modules\SupportAccess\Application\Services\GrantExpiryService;
use Illuminate\Console\Command;

final class ExpireSupportAccessCommand extends Command
{
    protected $signature = 'support-access:expire {--limit=100 : Maximum grants and sessions to inspect}';

    protected $description = 'Expire elapsed support grants and sessions';

    public function __construct(private readonly GrantExpiryService $expiry)
    {
        parent::__construct();
    }

    public function handle(): int
    {
        $limit = filter_var($this->option('limit'), FILTER_VALIDATE_INT);
        if (! is_int($limit) || $limit < 1 || $limit > 1000) {
            $this->error('The limit must be an integer between 1 and 1000.');

            return self::INVALID;
        }

        $count = $this->expiry->expireDue($limit);
        $this->info("Expired {$count} support access record(s).");

        return self::SUCCESS;
    }
}
