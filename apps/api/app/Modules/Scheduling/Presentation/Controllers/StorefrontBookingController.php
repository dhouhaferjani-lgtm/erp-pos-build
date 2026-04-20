<?php

declare(strict_types=1);

namespace App\Modules\Scheduling\Presentation\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Company\Domain\Company;
use App\Modules\Company\Domain\Location;
use App\Modules\Scheduling\Application\Commands\BookAppointmentCommand;
use App\Modules\Scheduling\Application\Services\AppointmentAuthoringService;
use App\Modules\Scheduling\Domain\Appointment;
use App\Modules\Scheduling\Domain\Enums\AppointmentSource;
use App\Modules\Scheduling\Domain\Enums\AppointmentType;
use App\Modules\Scheduling\Domain\Enums\WaitType;
use App\Modules\Scheduling\Domain\Exceptions\AppointmentConflictException;
use App\Modules\Scheduling\Presentation\Requests\BookAppointmentStorefrontRequest;
use App\Shared\Domain\CurrencyScale;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Str;

/**
 * Public storefront endpoint — accepts a booking payload and persists the
 * appointment with `source=Storefront`. Staff confirm / check-in afterwards;
 * the storefront endpoint never advances the status beyond `Scheduled`.
 *
 * Security layers in effect at this controller's route binding:
 *   - `throttle:storefront-booking-ip`   (10 req / min / (ip|company))
 *   - `throttle:storefront-booking-company-phone` (5 / day / (company|sha256(phone)))
 *   - `scheduling.captcha`               (VerifyCaptcha middleware)
 *
 * Response never exposes internal scheduling-side identifiers (bay_id,
 * primary_technician_profile_id, internal_notes, etc.). The payload is
 * the minimal confirmation surface:
 *   { appointment_id, appointment_number, scheduled_start, scheduled_end,
 *     status, online_booking_token }.
 */
final class StorefrontBookingController extends Controller
{
    public function __construct(
        private readonly AppointmentAuthoringService $authoring,
    ) {}

    public function store(BookAppointmentStorefrontRequest $request, string $company_id): JsonResponse
    {
        if (! Str::isUuid($company_id)) {
            abort(404);
        }

        /** @var Company|null $company */
        $company = Company::query()->find($company_id);
        if ($company === null) {
            abort(404);
        }

        $firstLocationId = $this->resolveFirstLocationId($company);
        if ($firstLocationId === null) {
            return response()->json([
                'message' => 'Company has no active location configured for online booking.',
                'error_code' => 'no_location',
            ], 422);
        }

        /** @var array<string, mixed> $data */
        $data = $request->validated();

        /** @var array<string, mixed> $vehicleInfo */
        $vehicleInfo = is_array($data['vehicle_info'] ?? null) ? $data['vehicle_info'] : [];
        $vehiclePlate = isset($vehicleInfo['plate']) && is_string($vehicleInfo['plate']) ? $vehicleInfo['plate'] : null;
        $vehicleDescription = $this->resolveVehicleDescription($vehicleInfo);

        $scheduledStart = new \DateTimeImmutable((string) $data['scheduled_start']);
        $durationMinutes = (int) $data['duration_minutes'];
        $scheduledEnd = $scheduledStart->modify("+{$durationMinutes} minutes");

        $plannedServices = $this->normalizePlannedServices($data['service_ids'] ?? []);

        $command = new BookAppointmentCommand(
            tenant_id: $company->tenant_id,
            company_id: $company->id,
            location_id: $firstLocationId,
            bay_id: null,
            primary_technician_profile_id: null,
            customer_partner_id: null,
            vehicle_id: null,
            customer_name: (string) $data['contact_name'],
            customer_phone: isset($data['contact_phone']) && is_string($data['contact_phone']) ? $data['contact_phone'] : null,
            customer_email: isset($data['contact_email']) && is_string($data['contact_email']) ? $data['contact_email'] : null,
            vehicle_plate: $vehiclePlate,
            vehicle_description: $vehicleDescription,
            appointment_type: AppointmentType::from((string) $data['appointment_type']),
            wait_type: isset($data['wait_type']) && is_string($data['wait_type'])
                ? WaitType::from($data['wait_type'])
                : WaitType::DropOff,
            scheduled_start: $scheduledStart,
            scheduled_end: $scheduledEnd,
            estimated_duration_minutes: $durationMinutes,
            source: AppointmentSource::Online,
            planned_services: $plannedServices,
            services_summary: isset($data['services_summary']) && is_string($data['services_summary']) ? $data['services_summary'] : null,
            customer_notes: isset($data['customer_notes']) && is_string($data['customer_notes']) ? $data['customer_notes'] : null,
            internal_notes: null,
        );

        try {
            $appointment = $this->authoring->create($command);
        } catch (AppointmentConflictException $e) {
            return response()->json([
                'message' => $e->getMessage(),
                'error_code' => 'appointment_conflict',
                'conflict' => $e->detail->toArray(),
            ], 409);
        }

        return response()->json([
            'data' => $this->serializePublicConfirmation($appointment),
        ], 201);
    }

    private function resolveFirstLocationId(Company $company): ?string
    {
        /** @var Location|null $location */
        $location = $company->locations()->orderBy('created_at')->first();

        return $location?->id;
    }

    /**
     * @param  array<string, mixed>  $vehicleInfo
     */
    private function resolveVehicleDescription(array $vehicleInfo): ?string
    {
        $direct = $vehicleInfo['description'] ?? null;
        if (is_string($direct) && trim($direct) !== '') {
            return $direct;
        }
        $makeModel = $vehicleInfo['make_model'] ?? null;
        if (is_string($makeModel) && trim($makeModel) !== '') {
            return $makeModel;
        }

        return null;
    }

    /**
     * @return list<array{service_ref_type: string, service_ref_id: string, display_name: string, estimated_duration_minutes: int, estimated_price?: string, display_order?: int}>
     */
    private function normalizePlannedServices(mixed $raw): array
    {
        if (! is_array($raw)) {
            return [];
        }

        /** @var list<array{service_ref_type: string, service_ref_id: string, display_name: string, estimated_duration_minutes: int, estimated_price?: string, display_order?: int}> $out */
        $out = [];

        $index = 0;
        foreach ($raw as $entry) {
            if (! is_array($entry)) {
                continue;
            }

            /** @var array{service_ref_type: string, service_ref_id: string, display_name: string, estimated_duration_minutes: int, estimated_price?: string, display_order?: int} $planned */
            $planned = [
                'service_ref_type' => (string) $entry['service_ref_type'],
                'service_ref_id' => (string) $entry['service_ref_id'],
                'display_name' => (string) $entry['display_name'],
                'estimated_duration_minutes' => (int) $entry['estimated_duration_minutes'],
                'display_order' => isset($entry['display_order']) ? (int) $entry['display_order'] : $index,
            ];

            // Serialise monetary estimated_price via CurrencyScale::bcformat($value, 3).
            // The scheduling_appointment_services.estimated_price column is NUMERIC(14,3);
            // raw float casts lose precision — see project_monetary_precision.
            if (isset($entry['estimated_price']) && (is_numeric($entry['estimated_price']) || is_string($entry['estimated_price']))) {
                $planned['estimated_price'] = CurrencyScale::bcformat((string) $entry['estimated_price'], 3);
            }

            $out[] = $planned;
            $index++;
        }

        return $out;
    }

    /**
     * @return array{
     *     appointment_id: string,
     *     appointment_number: string,
     *     scheduled_start: string,
     *     scheduled_end: string,
     *     status: string,
     *     online_booking_token: string|null,
     * }
     */
    private function serializePublicConfirmation(Appointment $appointment): array
    {
        return [
            'appointment_id' => $appointment->id,
            'appointment_number' => $appointment->appointment_number,
            'scheduled_start' => $appointment->scheduled_start->toIso8601String(),
            'scheduled_end' => $appointment->scheduled_end->toIso8601String(),
            'status' => $appointment->status->value,
            'online_booking_token' => $appointment->online_booking_token,
        ];
    }
}
