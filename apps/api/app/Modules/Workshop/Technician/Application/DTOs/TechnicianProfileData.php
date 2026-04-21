<?php

declare(strict_types=1);

namespace App\Modules\Workshop\Technician\Application\DTOs;

use App\Modules\Workshop\Technician\Domain\Enums\EmploymentStatus;
use App\Modules\Workshop\Technician\Domain\Enums\SkillLevel;
use App\Modules\Workshop\Technician\Domain\Enums\SpecialtyCode;
use App\Modules\Workshop\Technician\Domain\TechnicianProfile;
use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * Wire DTO for a TechnicianProfile aggregate, tagged for TypeScript generation.
 *
 * PII / pay masking:
 *  - @pay fields (`hourly_cost_rate`, `hourly_billing_rate`) are stripped from
 *    `toArray()` output when the authenticated user lacks the
 *    `workshop.technicians.view_pay` permission.
 *  - @pii fields (`national_id`, `personal_address`, `personal_phone`) are
 *    stripped when the user lacks `workshop.technicians.view_pii`.
 *
 * Routes are `auth:sanctum`-gated (see CLAUDE.md rule #12), so `auth()->user()`
 * is always populated inside request-scoped serialization — no unauthenticated
 * branch is needed.
 */
#[TypeScript]
final class TechnicianProfileData extends Data
{
    /**
     * @param  list<SpecialtyCode>  $specialties
     */
    public function __construct(
        public string $id,
        public string $tenant_id,
        public string $company_id,
        public string $user_id,
        public string $user_display_name,
        public ?string $user_email,
        public SkillLevel $skill_level,
        public array $specialties,
        /** @pay */
        public ?string $hourly_cost_rate,
        /** @pay */
        public ?string $hourly_billing_rate,
        public string $currency,
        public WeeklyScheduleData $weekly_schedule,
        public ?string $hire_date,
        public EmploymentStatus $employment_status,
        public ?string $employee_code,
        public ?string $notes,
        /** @pii */
        public ?string $national_id,
        /** @pii */
        public ?string $personal_address,
        /** @pii */
        public ?string $personal_phone,
        public bool $is_active,
        public string $created_at,
        public ?string $updated_at,
    ) {}

    public static function fromModel(TechnicianProfile $profile): self
    {
        /** @var list<SpecialtyCode> $specialties */
        $specialties = $profile->specialties->values()->all();

        return new self(
            id: $profile->id,
            tenant_id: $profile->tenant_id,
            company_id: $profile->company_id,
            user_id: $profile->user_id,
            user_display_name: (string) $profile->user->name,
            user_email: $profile->user->email,
            skill_level: $profile->skill_level,
            specialties: $specialties,
            hourly_cost_rate: $profile->hourly_cost_rate,
            hourly_billing_rate: $profile->hourly_billing_rate,
            currency: $profile->currency,
            weekly_schedule: WeeklyScheduleData::fromRaw($profile->weekly_schedule),
            hire_date: $profile->hire_date?->toDateString(),
            employment_status: $profile->employment_status,
            employee_code: $profile->employee_code,
            notes: $profile->notes,
            national_id: $profile->national_id,
            personal_address: $profile->personal_address,
            personal_phone: $profile->personal_phone,
            is_active: $profile->is_active,
            created_at: $profile->created_at->toIso8601String(),
            updated_at: $profile->updated_at->toIso8601String(),
        );
    }

    /**
     * Override to apply PII / pay masking based on the authenticated user's permissions.
     *
     * Masking intentionally removes the keys entirely (not nulls them) so the frontend
     * can distinguish "field not present because hidden" from "field present but null".
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        $data = parent::toArray();
        $user = auth()->user();

        if ($user === null || ! $user->can('workshop.technicians.view_pay')) {
            unset($data['hourly_cost_rate'], $data['hourly_billing_rate']);
        }

        if ($user === null || ! $user->can('workshop.technicians.view_pii')) {
            unset($data['national_id'], $data['personal_address'], $data['personal_phone']);
        }

        return $data;
    }
}
