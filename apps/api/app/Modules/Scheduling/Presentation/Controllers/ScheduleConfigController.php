<?php

declare(strict_types=1);

namespace App\Modules\Scheduling\Presentation\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Company\Services\CompanyContext;
use App\Modules\Scheduling\Domain\Contracts\ScheduleConfigRepositoryInterface;
use App\Modules\Scheduling\Domain\ScheduleConfig;
use App\Modules\Scheduling\Presentation\Requests\UpdateScheduleConfigRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/**
 * Staff endpoint for the per-location ScheduleConfig aggregate.
 *
 * - show:   `scheduling.bays.view`
 * - update: `scheduling.bays.manage`
 */
final class ScheduleConfigController extends Controller
{
    public function __construct(
        private readonly CompanyContext $companyContext,
        private readonly ScheduleConfigRepositoryInterface $configs,
    ) {}

    public function show(Request $request, string $locationId): JsonResponse
    {
        $user = $request->user();
        if ($user === null || ! $user->can('scheduling.bays.view')) {
            abort(403);
        }
        if (! Str::isUuid($locationId)) {
            abort(404);
        }

        $company = $this->companyContext->requireCompany();
        $config = $this->configs->findForLocation($company->tenant_id, $company->id, $locationId);
        if ($config === null) {
            abort(404);
        }

        return response()->json(['data' => $this->serialize($config)]);
    }

    public function update(UpdateScheduleConfigRequest $request, string $locationId): JsonResponse
    {
        if (! Str::isUuid($locationId)) {
            abort(404);
        }

        $company = $this->companyContext->requireCompany();
        $config = $this->configs->findForLocation($company->tenant_id, $company->id, $locationId);
        if ($config === null) {
            abort(404);
        }

        /** @var array<string, mixed> $data */
        $data = $request->validated();

        if (isset($data['time_slot_minutes'])) {
            $config->time_slot_minutes = (int) $data['time_slot_minutes'];
        }
        if (isset($data['default_appointment_duration_minutes'])) {
            $config->default_appointment_duration_minutes = (int) $data['default_appointment_duration_minutes'];
        }
        if (isset($data['walk_in_buffer_hours_per_day'])) {
            $config->walk_in_buffer_hours_per_day = (string) $data['walk_in_buffer_hours_per_day'];
        }
        if (isset($data['overbooking_threshold_percent'])) {
            $config->overbooking_threshold_percent = (int) $data['overbooking_threshold_percent'];
        }
        if (isset($data['online_booking_enabled'])) {
            $config->online_booking_enabled = (bool) $data['online_booking_enabled'];
        }
        if (isset($data['online_booking_advance_days'])) {
            $config->online_booking_advance_days = (int) $data['online_booking_advance_days'];
        }
        if (isset($data['online_booking_min_notice_hours'])) {
            $config->online_booking_min_notice_hours = (int) $data['online_booking_min_notice_hours'];
        }
        if (isset($data['online_booking_auto_confirm'])) {
            $config->online_booking_auto_confirm = (bool) $data['online_booking_auto_confirm'];
        }
        if (array_key_exists('reminder_sms_hours_before', $data)) {
            $config->reminder_sms_hours_before = $data['reminder_sms_hours_before'] === null
                ? null
                : (int) $data['reminder_sms_hours_before'];
        }
        if (array_key_exists('reminder_email_hours_before', $data)) {
            $config->reminder_email_hours_before = $data['reminder_email_hours_before'] === null
                ? null
                : (int) $data['reminder_email_hours_before'];
        }

        $config = $this->configs->save($config);

        return response()->json(['data' => $this->serialize($config)]);
    }

    /**
     * @return array<string, mixed>
     */
    private function serialize(ScheduleConfig $config): array
    {
        return [
            'id' => $config->id,
            'tenant_id' => $config->tenant_id,
            'company_id' => $config->company_id,
            'location_id' => $config->location_id,
            'time_slot_minutes' => $config->time_slot_minutes,
            'default_appointment_duration_minutes' => $config->default_appointment_duration_minutes,
            'walk_in_buffer_hours_per_day' => $config->walk_in_buffer_hours_per_day,
            'overbooking_threshold_percent' => $config->overbooking_threshold_percent,
            'online_booking_enabled' => $config->online_booking_enabled,
            'online_booking_advance_days' => $config->online_booking_advance_days,
            'online_booking_min_notice_hours' => $config->online_booking_min_notice_hours,
            'online_booking_auto_confirm' => $config->online_booking_auto_confirm,
            'reminder_sms_hours_before' => $config->reminder_sms_hours_before,
            'reminder_email_hours_before' => $config->reminder_email_hours_before,
        ];
    }
}
