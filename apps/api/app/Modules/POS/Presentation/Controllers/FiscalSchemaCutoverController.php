<?php

declare(strict_types=1);

namespace App\Modules\POS\Presentation\Controllers;

use App\Modules\Identity\Domain\User;
use App\Modules\POS\Application\Services\FiscalSchemaCutoverService;
use App\Modules\POS\Domain\Exceptions\FiscalSchemaCutoverBlockedException;
use App\Modules\POS\Domain\Terminal;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

/**
 * Handles per-terminal fiscal-schema cutover (v2 → v3).
 *
 * Route: POST /api/v1/pos/terminals/{terminal}/fiscal-schema-cutover
 *
 * Guards:
 * - auth:sanctum (applied at route group level)
 * - pos.fiscal_schema_cutover permission (checked here, returns 403)
 * - All service-level gates (open shift / un-Z-reported receipts / pending sync)
 *   return 422 with a machine-readable `reason` key.
 */
final class FiscalSchemaCutoverController extends Controller
{
    public function __construct(
        private readonly FiscalSchemaCutoverService $cutoverService,
    ) {}

    public function __invoke(Request $request, string $terminal): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        // Permission gate — return 403 not 500 on missing permission.
        if (! $user->can('pos.fiscal_schema_cutover')) {
            return response()->json([
                'message' => 'Forbidden: pos.fiscal_schema_cutover permission required.',
            ], 403);
        }

        /** @var Terminal|null $terminalModel */
        $terminalModel = Terminal::find($terminal);

        if ($terminalModel === null) {
            return response()->json(['message' => 'Terminal not found.'], 404);
        }

        try {
            $this->cutoverService->cutover($terminalModel, $user);
        } catch (FiscalSchemaCutoverBlockedException $e) {
            return response()->json([
                'message' => $e->getMessage(),
                'reason' => $e->reason,
            ], 422);
        }

        return response()->json([
            'message' => 'Terminal fiscal_schema_version upgraded to 3.',
            'terminal_id' => $terminal,
            'fiscal_schema_version' => 3,
        ], 200);
    }
}
