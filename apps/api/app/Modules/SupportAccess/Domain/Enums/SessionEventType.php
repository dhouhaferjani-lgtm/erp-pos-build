<?php

declare(strict_types=1);

namespace App\Modules\SupportAccess\Domain\Enums;

enum SessionEventType: string
{
    case GrantRequested = 'grant_requested';
    case GrantApproved = 'grant_approved';
    case GrantRejected = 'grant_rejected';
    case GrantRevoked = 'grant_revoked';
    case SessionStarted = 'session_started';
    case SessionEnded = 'session_ended';
    case RequestReceived = 'request_received';
    case RequestAuthorized = 'request_authorized';
    case RequestDenied = 'request_denied';
    case WriteElevationRequested = 'write_elevation_requested';
    case WriteElevationApproved = 'write_elevation_approved';
    case WriteElevationRejected = 'write_elevation_rejected';
}
