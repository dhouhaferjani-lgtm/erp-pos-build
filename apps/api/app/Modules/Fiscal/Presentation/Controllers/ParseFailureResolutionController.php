<?php

declare(strict_types=1);

namespace App\Modules\Fiscal\Presentation\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Fiscal\Application\Services\ParseFailureResolutionService;
use App\Modules\Fiscal\Domain\Exceptions\FiscalEventTypeNotImplemented;
use App\Modules\Fiscal\Domain\Exceptions\InvalidCorrectedPayloadException;
use App\Modules\Fiscal\Domain\Exceptions\ParseFailureResolutionPreconditionException;
use App\Modules\Fiscal\Domain\Models\FiscalEvent;
use App\Modules\Identity\Domain\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class ParseFailureResolutionController extends Controller
{
    public function __construct(
        private readonly ParseFailureResolutionService $resolver,
    ) {}

    public function store(Request $request, string $id): JsonResponse
    {
        $user = $request->user();
        if (! $user instanceof User) {
            return response()->json([
                'error' => [
                    'code' => 'UNAUTHENTICATED',
                    'message' => 'Authenticated user could not be resolved.',
                ],
            ], 401);
        }

        $event = FiscalEvent::query()
            ->where('id', $id)
            ->where('tenant_id', $user->tenant_id)
            ->first();

        if (! $event instanceof FiscalEvent) {
            return response()->json([
                'error' => [
                    'code' => 'NOT_FOUND',
                    'message' => 'No parse-failed fiscal event was found for this tenant.',
                ],
            ], 404);
        }

        /** @var array{corrected_payload: array<string, mixed>} $validated */
        $validated = $request->validate([
            'corrected_payload' => ['required', 'array'],
        ]);

        try {
            $this->resolver->resolve($event->id, $validated['corrected_payload'], $user);
        } catch (InvalidCorrectedPayloadException $e) {
            return response()->json([
                'error' => [
                    'code' => 'INVALID_CORRECTED_PAYLOAD',
                    'message' => $e->getMessage(),
                ],
            ], 422);
        } catch (ParseFailureResolutionPreconditionException|FiscalEventTypeNotImplemented $e) {
            return response()->json([
                'error' => [
                    'code' => 'RESOLUTION_PRECONDITION_FAILED',
                    'message' => $e->getMessage(),
                ],
            ], 409);
        }

        return response()->json([
            'data' => [
                'id' => $event->id,
                'status' => 'resolved',
            ],
        ]);
    }
}
