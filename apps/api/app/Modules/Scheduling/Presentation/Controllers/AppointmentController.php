<?php

declare(strict_types=1);

namespace App\Modules\Scheduling\Presentation\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Company\Services\CompanyContext;
use App\Modules\Scheduling\Application\Commands\BookAppointmentCommand;
use App\Modules\Scheduling\Application\Services\AppointmentAuthoringService;
use App\Modules\Scheduling\Domain\Appointment;
use App\Modules\Scheduling\Domain\Contracts\AppointmentRepositoryInterface;
use App\Modules\Scheduling\Domain\Enums\AppointmentSource;
use App\Modules\Scheduling\Domain\Enums\AppointmentStatus;
use App\Modules\Scheduling\Domain\Enums\AppointmentType;
use App\Modules\Scheduling\Domain\Enums\WaitType;
use App\Modules\Scheduling\Domain\Exceptions\AppointmentConflictException;
use App\Modules\Scheduling\Presentation\Requests\StoreAppointmentRequest;
use App\Modules\Scheduling\Presentation\Requests\UpdateAppointmentRequest;
use App\Shared\Domain\CurrencyScale;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/**
 * Staff CRUD for appointments. Transitions live in
 * {@see AppointmentTransitionController} — updates here are limited to
 * non-transition fields (notes, contact, color_label, primary technician).
 */
final class AppointmentController extends Controller
{
    public function __construct(
        private readonly CompanyContext $companyContext,
        private readonly AppointmentRepositoryInterface $appointments,
        private readonly AppointmentAuthoringService $authoring,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        if ($user === null || ! $user->can('scheduling.appointments.view')) {
            abort(403);
        }

        $companyId = $this->companyContext->requireCompanyId();
        $perPage = min((int) $request->input('per_page', 25), 100);

        $filters = [];
        $statusRaw = $request->query('status');
        if (is_string($statusRaw) && $statusRaw !== '') {
            $status = AppointmentStatus::tryFrom($statusRaw);
            if ($status !== null) {
                $filters['status'] = $status;
            }
        }
        $bayId = $request->query('bay_id');
        if (is_string($bayId) && Str::isUuid($bayId)) {
            $filters['bay_id'] = $bayId;
        }
        $dateFromRaw = $request->query('date_from');
        if (is_string($dateFromRaw)) {
            try {
                $filters['date_from'] = new \DateTimeImmutable($dateFromRaw);
            } catch (\Exception) {
                // ignore invalid date; filter not applied
            }
        }
        $dateToRaw = $request->query('date_to');
        if (is_string($dateToRaw)) {
            try {
                $filters['date_to'] = new \DateTimeImmutable($dateToRaw);
            } catch (\Exception) {
                // ignore invalid date; filter not applied
            }
        }

        $page = $this->appointments->paginate($companyId, $filters, $perPage);

        /** @var list<array<string, mixed>> $items */
        $items = [];
        foreach ($page->items() as $appt) {
            $items[] = $this->serialize($appt);
        }

        return response()->json([
            'data' => $items,
            'meta' => [
                'current_page' => $page->currentPage(),
                'last_page' => $page->lastPage(),
                'per_page' => $page->perPage(),
                'total' => $page->total(),
            ],
        ]);
    }

    public function show(Request $request, string $id): JsonResponse
    {
        $user = $request->user();
        if ($user === null || ! $user->can('scheduling.appointments.view')) {
            abort(403);
        }
        if (! Str::isUuid($id)) {
            abort(404);
        }

        $appt = $this->appointments->findById($id);
        if ($appt === null || $appt->company_id !== $this->companyContext->requireCompanyId()) {
            abort(404);
        }

        return response()->json(['data' => $this->serialize($appt)]);
    }

    public function store(StoreAppointmentRequest $request): JsonResponse
    {
        $company = $this->companyContext->requireCompany();

        /** @var array<string, mixed> $data */
        $data = $request->validated();

        $plannedServices = $this->normalizePlannedServices($data['planned_services'] ?? []);

        $command = new BookAppointmentCommand(
            tenant_id: $company->tenant_id,
            company_id: $company->id,
            location_id: (string) $data['location_id'],
            bay_id: isset($data['bay_id']) && is_string($data['bay_id']) ? $data['bay_id'] : null,
            primary_technician_profile_id: isset($data['primary_technician_profile_id']) && is_string($data['primary_technician_profile_id']) ? $data['primary_technician_profile_id'] : null,
            customer_partner_id: isset($data['customer_partner_id']) && is_string($data['customer_partner_id']) ? $data['customer_partner_id'] : null,
            vehicle_id: isset($data['vehicle_id']) && is_string($data['vehicle_id']) ? $data['vehicle_id'] : null,
            customer_name: isset($data['customer_name']) && is_string($data['customer_name']) ? $data['customer_name'] : null,
            customer_phone: isset($data['customer_phone']) && is_string($data['customer_phone']) ? $data['customer_phone'] : null,
            customer_email: isset($data['customer_email']) && is_string($data['customer_email']) ? $data['customer_email'] : null,
            vehicle_plate: isset($data['vehicle_plate']) && is_string($data['vehicle_plate']) ? $data['vehicle_plate'] : null,
            vehicle_description: isset($data['vehicle_description']) && is_string($data['vehicle_description']) ? $data['vehicle_description'] : null,
            appointment_type: AppointmentType::from((string) $data['appointment_type']),
            wait_type: isset($data['wait_type']) && is_string($data['wait_type']) ? WaitType::from($data['wait_type']) : WaitType::DropOff,
            scheduled_start: new \DateTimeImmutable((string) $data['scheduled_start']),
            scheduled_end: new \DateTimeImmutable((string) $data['scheduled_end']),
            estimated_duration_minutes: (int) $data['estimated_duration_minutes'],
            source: AppointmentSource::Manual,
            planned_services: $plannedServices,
            services_summary: isset($data['services_summary']) && is_string($data['services_summary']) ? $data['services_summary'] : null,
            customer_notes: isset($data['customer_notes']) && is_string($data['customer_notes']) ? $data['customer_notes'] : null,
            internal_notes: isset($data['internal_notes']) && is_string($data['internal_notes']) ? $data['internal_notes'] : null,
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

        return response()->json(['data' => $this->serialize($appointment)], 201);
    }

    public function update(UpdateAppointmentRequest $request, string $id): JsonResponse
    {
        if (! Str::isUuid($id)) {
            abort(404);
        }

        $companyId = $this->companyContext->requireCompanyId();
        $appt = $this->appointments->findById($id);
        if ($appt === null || $appt->company_id !== $companyId) {
            abort(404);
        }

        /** @var array<string, mixed> $data */
        $data = $request->validated();

        if (array_key_exists('primary_technician_profile_id', $data)) {
            $appt->primary_technician_profile_id = is_string($data['primary_technician_profile_id']) ? $data['primary_technician_profile_id'] : null;
        }
        foreach (['customer_name', 'customer_phone', 'customer_email', 'vehicle_plate', 'vehicle_description', 'services_summary', 'customer_notes', 'internal_notes', 'color_label'] as $field) {
            if (array_key_exists($field, $data)) {
                $appt->{$field} = is_string($data[$field]) ? $data[$field] : null;
            }
        }

        $this->appointments->save($appt);

        return response()->json(['data' => $this->serialize($appt)]);
    }

    public function destroy(Request $request, string $id): JsonResponse
    {
        $user = $request->user();
        if ($user === null || ! $user->can('scheduling.appointments.cancel')) {
            abort(403);
        }
        if (! Str::isUuid($id)) {
            abort(404);
        }

        $appt = $this->appointments->findById($id);
        if ($appt === null || $appt->company_id !== $this->companyContext->requireCompanyId()) {
            abort(404);
        }

        $appt->delete();

        return response()->json(['data' => ['id' => $id, 'deleted' => true]]);
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
            if (isset($entry['estimated_price']) && (is_numeric($entry['estimated_price']) || is_string($entry['estimated_price']))) {
                $planned['estimated_price'] = CurrencyScale::bcformat((string) $entry['estimated_price'], 3);
            }
            $out[] = $planned;
            $index++;
        }

        return $out;
    }

    /**
     * @return array<string, mixed>
     */
    private function serialize(Appointment $appt): array
    {
        return [
            'id' => $appt->id,
            'tenant_id' => $appt->tenant_id,
            'company_id' => $appt->company_id,
            'location_id' => $appt->location_id,
            'appointment_number' => $appt->appointment_number,
            'bay_id' => $appt->bay_id,
            'primary_technician_profile_id' => $appt->primary_technician_profile_id,
            'customer_partner_id' => $appt->customer_partner_id,
            'vehicle_id' => $appt->vehicle_id,
            'customer_name' => $appt->customer_name,
            'customer_phone' => $appt->customer_phone,
            'customer_email' => $appt->customer_email,
            'vehicle_plate' => $appt->vehicle_plate,
            'vehicle_description' => $appt->vehicle_description,
            'appointment_type' => $appt->appointment_type->value,
            'wait_type' => $appt->wait_type->value,
            'status' => $appt->status->value,
            'scheduled_start' => $appt->scheduled_start->toIso8601String(),
            'scheduled_end' => $appt->scheduled_end->toIso8601String(),
            'estimated_duration_minutes' => $appt->estimated_duration_minutes,
            'actual_arrival_at' => $appt->actual_arrival_at?->toIso8601String(),
            'services_summary' => $appt->services_summary,
            'customer_notes' => $appt->customer_notes,
            'internal_notes' => $appt->internal_notes,
            'color_label' => $appt->color_label,
            'source' => $appt->source->value,
            'work_order_id' => $appt->work_order_id,
            'created_at' => $appt->created_at->toIso8601String(),
            'updated_at' => $appt->updated_at->toIso8601String(),
        ];
    }
}
