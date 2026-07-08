<?php

declare(strict_types=1);

namespace App\Modules\DocumentIngestion\Infrastructure;

use App\Modules\DocumentIngestion\Application\Contracts\ExtractionClientInterface;
use App\Modules\DocumentIngestion\Application\Contracts\ExtractionFailedException;
use App\Modules\DocumentIngestion\Application\Contracts\ExtractionHints;
use App\Modules\DocumentIngestion\Application\Contracts\ExtractionResponse;
use App\Modules\DocumentIngestion\Application\DTO\ExtractionResultData;
use App\Modules\DocumentIngestion\Domain\Enums\DocumentKind;
use Illuminate\Support\Facades\Http;

final class ErpMlExtractionClient implements ExtractionClientInterface
{
    public function extract(
        string $fileContents,
        string $mimeType,
        DocumentKind $kind,
        ExtractionHints $hints,
    ): ExtractionResponse {
        $url = rtrim((string) config('services.erp_ml.url', 'http://127.0.0.1:8002'), '/');
        $token = (string) config('services.erp_ml.service_token', '');

        $fields = [
            'kind' => $kind->value,
            'language_hint' => $hints->languageHint,
        ];

        if ($hints->currencyHint !== null) {
            $fields['currency_hint'] = $hints->currencyHint;
        }

        $response = Http::baseUrl($url)
            ->timeout(120)
            ->withHeaders(['X-Service-Token' => $token])
            ->attach('file', $fileContents, 'document', ['Content-Type' => $mimeType])
            ->post('/api/v1/extract', $fields);

        if (! $response->successful()) {
            throw new ExtractionFailedException(
                sprintf('erp-ml extraction failed with HTTP %d.', $response->status()),
                'HTTP_'.$response->status(),
            );
        }

        $json = $response->json();
        if (! is_array($json) || ! isset($json['result']) || ! is_array($json['result'])) {
            throw new ExtractionFailedException('erp-ml extraction returned a malformed response.', 'MALFORMED_RESPONSE');
        }

        try {
            /** @var array<string, mixed> $result */
            $result = $json['result'];
            $provider = is_string($json['provider'] ?? null) ? $json['provider'] : 'erp_ml';
            $model = is_string($json['model'] ?? null) ? $json['model'] : null;

            // erp-ml keeps doc_kind/pages in the top-level envelope, not inside `result`.
            // ExtractionResultData wants them alongside the payload fields, so fold the
            // authoritative envelope values in (top-level wins over any stray result key).
            $payload = $result;
            $payload['doc_kind'] = $json['doc_kind'] ?? null;
            $payload['pages'] = $json['pages'] ?? null;

            return new ExtractionResponse(
                result: ExtractionResultData::from($payload),
                provider: $provider,
                model: $model,
            );
        } catch (\Throwable $exception) {
            throw new ExtractionFailedException(
                'erp-ml extraction returned invalid extraction data: '.$exception->getMessage(),
                'INVALID_EXTRACTION_DATA',
                $exception,
            );
        }
    }
}
