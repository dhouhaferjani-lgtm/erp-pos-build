<?php

declare(strict_types=1);

namespace App\Modules\Import\Domain\Enums;

/**
 * Shared import refusal vocabulary co-created by G-12 and G-6a under §3.1d.
 *
 * G-12 created units_not_seeded; G-6a adds worker_lost. Later lanes G-4,
 * G-2, G-5, G-8, and G-1 add the row-level cases listed in §3.2.1. The
 * exhaustive match deliberately has no default so every added case must make
 * its job-level versus row-level decision explicit.
 */
enum ImportErrorCode: string
{
    case UnitsNotSeeded = 'units_not_seeded';
    case WorkerLost = 'worker_lost';

    public function isJobLevel(): bool
    {
        return match ($this) {
            self::UnitsNotSeeded, self::WorkerLost => true,
        };
    }
}
