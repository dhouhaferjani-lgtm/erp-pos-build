<?php

declare(strict_types=1);

namespace App\Modules\SupportAccess\Application\DTOs;

use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

#[TypeScript]
final class SupportAccessOverviewData extends Data
{
    /**
     * @param  list<GrantData>  $grants
     * @param  list<SessionData>  $active_sessions
     * @param  list<SupportAccessLogEntryData>  $log
     * @param  list<ElevationData>  $pending_elevations
     * @param  array{current_page: int, per_page: int, total: int, last_page: int, from: int|null, to: int|null}|null  $log_meta
     */
    public function __construct(
        public int $max_grant_window_hours,
        public array $grants,
        public array $active_sessions,
        public array $log,
        public array $pending_elevations = [],
        public ?array $log_meta = null,
    ) {}
}
