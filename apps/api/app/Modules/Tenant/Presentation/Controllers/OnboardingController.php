<?php

declare(strict_types=1);

namespace App\Modules\Tenant\Presentation\Controllers;

use App\Modules\Tenant\Application\Services\OnboardingChecklistService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final class OnboardingController
{
    public function __construct(
        private readonly OnboardingChecklistService $checklistService,
    ) {}

    public function status(Request $request): JsonResponse
    {
        $companyId = $request->header('X-Company-Id');

        if ($companyId === null) {
            return response()->json([
                'error' => [
                    'code' => 'MISSING_COMPANY_CONTEXT',
                    'message' => 'X-Company-Id header is required.',
                ],
            ], Response::HTTP_BAD_REQUEST);
        }

        return response()->json([
            'data' => $this->checklistService->getStatus($companyId),
        ]);
    }
}
