<?php

declare(strict_types=1);

namespace App\Modules\PurchaseHub\Presentation\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

final class PurchaseHubWebhookController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        $event = $request->input('event');

        Log::info('PurchaseHub webhook received', ['event' => $event]);

        // Future: handle order.receipt_confirmed to auto-create PO in ERP
        // For now, just acknowledge

        return response()->json(['status' => 'received']);
    }
}
