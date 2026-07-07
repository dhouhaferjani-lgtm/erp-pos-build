<?php

declare(strict_types=1);

namespace App\Modules\DocumentIngestion\Infrastructure;

use App\Modules\DocumentIngestion\Application\DTO\ExtractionResultData;
use App\Modules\DocumentIngestion\Domain\Enums\DocumentKind;
use App\Shared\Contracts\ExtractionClientInterface;
use App\Shared\Contracts\ExtractionFailedException;
use App\Shared\Contracts\ExtractionHints;
use Illuminate\Support\Facades\Http;

final class ErpMlExtractionClient implements ExtractionClientInterface
{
    public function extract(
        string $fileContents,
        string $mimeType,
        DocumentKind $kind,
        ExtractionHints $hints,
    ): ExtractionResultData {
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

            return ExtractionResultData::from($result);
        } catch (\Throwable $exception) {
            throw new ExtractionFailedException(
                'erp-ml extraction returned invalid extraction data: '.$exception->getMessage(),
                'INVALID_EXTRACTION_DATA',
                $exception,
            );
        }
    }
}
