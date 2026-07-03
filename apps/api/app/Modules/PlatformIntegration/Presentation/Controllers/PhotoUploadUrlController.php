<?php

declare(strict_types=1);

namespace App\Modules\PlatformIntegration\Presentation\Controllers;

use App\Modules\PlatformIntegration\Application\Services\ProductSubmissionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

final class PhotoUploadUrlController extends Controller
{
    public function __construct(
        private readonly ProductSubmissionService $submissionService,
    ) {}

    public function __invoke(Request $request): JsonResponse
    {
        $user = $request->user();
        if (! $user?->can('enrichment.submit')) {
            abort(403);
        }

        $validated = $request->validate([
            'filename' => ['required', 'string', 'max:255'],
            'content_type' => ['required', 'string', 'in:image/jpeg,image/png,image/webp,image/heic'],
            'size_bytes' => ['required', 'integer', 'min:1', 'max:5242880'],
        ]);

        $result = $this->submissionService->requestUploadUrl(
            (string) $validated['filename'],
            (string) $validated['content_type'],
            (int) $validated['size_bytes'],
        );

        if ($result === null) {
            return response()->json([
                'error' => ['code' => 'platform_unavailable', 'message' => 'Platform is currently unavailable.'],
            ], 502);
        }

        return response()->json(['data' => $result]);
    }
}
