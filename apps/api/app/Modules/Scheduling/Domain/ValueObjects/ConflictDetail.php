<?php

declare(strict_types=1);

namespace App\Modules\Scheduling\Domain\ValueObjects;

use InvalidArgumentException;

/**
 * Describes an appointment-booking conflict so the API layer can return a
 * 409 / 422 response with actionable context.
 *
 *   - TYPE_OVERLAP — another active appointment occupies the same bay window
 *   - TYPE_OUT_OF_HOURS — window sits outside the bay's operating_hours
 *   - TYPE_TECHNICIAN_BUSY — requested primary technician has no availability
 *
 * Use the static factories instead of `new` so the `conflict_type` string
 * is guaranteed to be one of the canonical values.
 */
final readonly class ConflictDetail
{
    public const TYPE_OVERLAP = 'overlap';

    public const TYPE_OUT_OF_HOURS = 'out_of_hours';

    public const TYPE_TECHNICIAN_BUSY = 'technician_busy';

    /**
     * @param  list<string>  $conflicting_appointment_ids
     */
    public function __construct(
        public string $conflict_type,
        public array $conflicting_appointment_ids,
        public string $message,
    ) {
        if (! in_array($conflict_type, [self::TYPE_OVERLAP, self::TYPE_OUT_OF_HOURS, self::TYPE_TECHNICIAN_BUSY], true)) {
            throw new InvalidArgumentException(
                "conflict_type must be one of overlap|out_of_hours|technician_busy, got '{$conflict_type}'.",
            );
        }
    }

    /**
     * @param  list<string>  $conflicting_appointment_ids
     */
    public static function overlap(array $conflicting_appointment_ids, string $message): self
    {
        return new self(self::TYPE_OVERLAP, $conflicting_appointment_ids, $message);
    }

    public static function outOfHours(string $message): self
    {
        return new self(self::TYPE_OUT_OF_HOURS, [], $message);
    }

    /**
     * @param  list<string>  $conflicting_appointment_ids
     */
    public static function technicianBusy(array $conflicting_appointment_ids, string $message): self
    {
        return new self(self::TYPE_TECHNICIAN_BUSY, $conflicting_appointment_ids, $message);
    }

    /**
     * @return array{conflict_type: string, conflicting_appointment_ids: list<string>, message: string}
     */
    public function toArray(): array
    {
        return [
            'conflict_type' => $this->conflict_type,
            'conflicting_appointment_ids' => $this->conflicting_appointment_ids,
            'message' => $this->message,
        ];
    }
}
