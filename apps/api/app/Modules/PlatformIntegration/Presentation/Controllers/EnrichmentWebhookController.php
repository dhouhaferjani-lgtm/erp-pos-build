<?php

declare(strict_types=1);

namespace App\Modules\PlatformIntegration\Presentation\Controllers;

use App\Modules\PlatformIntegration\Application\DTOs\EnrichmentWebhookPayload;
use App\Modules\PlatformIntegration\Application\Jobs\ProcessEnrichmentWebhookJob;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

final class EnrichmentWebhookController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        /** @var array<string, mixed> $data */
        $data = $request->all();
        $event = (string) ($data['event'] ?? '');

        if ($event === 'enrichment.batch_resolved') {
            /** @var array<int, array<string, mixed>> $items */
            $items = $data['items'] ?? [];

            foreach ($items as $item) {
                /** @var array<string, mixed> $item */
                $item['event'] = 'enrichment.resolved';
                $payload = EnrichmentWebhookPayload::fromWebhook($item);
                ProcessEnrichmentWebhookJob::dispatch($payload)->onQueue('enrichment');
            }
        } else {
            $payload = EnrichmentWebhookPayload::fromWebhook($data);
            ProcessEnrichmentWebhookJob::dispatch($payload)->onQueue('enrichment');
        }

        return response()->json(['received' => true]);
    }
}
