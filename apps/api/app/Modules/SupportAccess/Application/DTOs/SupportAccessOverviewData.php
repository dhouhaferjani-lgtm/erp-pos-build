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
     */
    public function __construct(
        public array $grants,
        public array $active_sessions,
        public array $log,
        public array $pending_elevations = [],
    ) {}
}
