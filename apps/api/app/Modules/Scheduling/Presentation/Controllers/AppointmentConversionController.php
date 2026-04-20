<?php

declare(strict_types=1);

namespace App\Modules\Scheduling\Presentation\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Company\Services\CompanyContext;
use App\Modules\Scheduling\Application\Services\AppointmentConversionService;
use App\Modules\Scheduling\Domain\Contracts\AppointmentRepositoryInterface;
use App\Modules\Scheduling\Domain\Exceptions\AppointmentNotConvertibleException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/**
 * Staff endpoint that converts a (Confirmed|CheckedIn) appointment into a
 * WorkOrder. Gated by `scheduling.appointments.convert`.
 */
final class AppointmentConversionController extends Controller
{
    public function __construct(
        private readonly CompanyContext $companyContext,
        private readonly AppointmentRepositoryInterface $appointments,
        private readonly AppointmentConversionService $conversion,
    ) {}

    public function convert(Request $request, string $id): JsonResponse
    {
        $user = $request->user();
        if ($user === null || ! $user->can('scheduling.appointments.convert')) {
            abort(403);
        }
        if (! Str::isUuid($id)) {
            abort(404);
        }

        $appt = $this->appointments->findById($id);
        if ($appt === null || $appt->company_id !== $this->companyContext->requireCompanyId()) {
            abort(404);
        }

        try {
            $workOrder = $this->conversion->convertToWorkOrder(
                appointmentId: $appt->id,
                convertedByUserId: (string) $user->id,
            );
        } catch (AppointmentNotConvertibleException $e) {
            return response()->json([
                'message' => $e->getMessage(),
                'error_code' => 'appointment_not_convertible',
            ], 422);
        }

        return response()->json([
            'data' => [
                'appointment_id' => $appt->id,
                'work_order_id' => $workOrder->id,
            ],
        ], 201);
    }
}
